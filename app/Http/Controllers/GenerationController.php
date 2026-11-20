<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use App\Services\PixelforgePricingService;
use App\Services\PixelforgeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * Display main studio page with image generation form & recent history.
     */
    public function index(Request $request, PixelforgePricingService $pricingService): Response
    {
        $user = $request->user() ?: User::first();
        if (!$user) {
            $user = User::create([
                'name' => 'Demo Creator',
                'email' => 'creator@pixelforge.local',
                'password' => bcrypt('password123'),
                'balance_usd' => '10.000000000',
                'is_admin' => true,
            ]);
            Auth::login($user);
        }

        $generations = Generation::where('user_id', $user->id)
            ->latest()
            ->take(50)
            ->get();

        $pricing = $pricingService->getPricing();

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
            'pricing' => $pricing,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'balance_usd' => (string) $user->balance_usd,
                'is_admin' => (bool) $user->is_admin,
            ],
            'markupPercent' => (float) config('pixelforge.markup_percent', 25),
        ]);
    }

    /**
     * Real-time price estimator API endpoint as user types prompt or tweaks params.
     */
    public function estimate(Request $request, PixelforgePricingService $pricingService): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'size' => 'nullable|string|in:1024x1024,1536x1024,1024x1536',
            'quality' => 'nullable|string|in:low,medium,high',
            'reference_images' => 'nullable|array|max:4',
            'reference_images.*' => 'url',
        ]);

        $size = $validated['size'] ?? '1024x1024';
        $quality = $validated['quality'] ?? 'medium';
        $refImages = $validated['reference_images'] ?? [];

        $estimation = $pricingService->estimate($validated['prompt'], $size, $quality, $refImages);

        return response()->json($estimation);
    }

    /**
     * Submit generation to Pixelforge provider and record locally.
     */
    public function store(Request $request, PixelforgeClient $client, PixelforgePricingService $pricingService): RedirectResponse|JsonResponse
    {
        $user = $request->user() ?: User::first();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'size' => 'required|string|in:1024x1024,1536x1024,1024x1536',
            'quality' => 'required|string|in:low,medium,high',
            'reference_images' => 'nullable|array|max:4',
            'reference_images.*' => 'url',
        ]);

        $refImages = array_values(array_filter($validated['reference_images'] ?? []));

        // 1. Calculate upfront estimated price
        $estimate = $pricingService->estimate(
            $validated['prompt'],
            $validated['size'],
            $validated['quality'],
            $refImages
        );

        $expectedUserPrice = $estimate['user_price']['expected'];
        $maxUserPrice = $estimate['user_price']['max'];

        // 2. Balance Check (Stretch Goal requirement)
        if (!$user->hasBalance($maxUserPrice)) {
            $msg = "Insufficient balance (\${$user->balance_usd}). Maximum possible cost is \${$maxUserPrice}. Please top up your balance.";
            if ($request->wantsJson()) {
                return response()->json(['error' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // 3. Create local record in 'queued' status
        $idempotencyKey = (string) Str::uuid();

        $rates = $estimate['rates'];

        $generation = Generation::create([
            'user_id' => $user->id,
            'prompt' => $validated['prompt'],
            'size' => $validated['size'],
            'quality' => $validated['quality'],
            'reference_images' => $refImages,
            'status' => 'queued',
            'idempotency_key' => $idempotencyKey,
            'rate_input_text' => (string) ($rates['input_text_tokens'] ?? '5.00'),
            'rate_input_image' => (string) ($rates['input_image_tokens'] ?? '10.00'),
            'rate_output' => (string) ($rates['output_tokens'] ?? '40.00'),
            'rate_reasoning' => (string) ($rates['reasoning_tokens'] ?? '12.50'),
            'estimated_cost_usd' => $expectedUserPrice,
        ]);

        // 4. Send request to Pixelforge provider
        $apiPayload = [
            'prompt' => $validated['prompt'],
            'size' => $validated['size'],
            'quality' => $validated['quality'],
            'metadata' => [
                'app_user_id' => (string) $user->id,
                'app_generation_id' => (string) $generation->id,
            ],
        ];

        if (!empty($refImages)) {
            $apiPayload['reference_images'] = $refImages;
        }

        $res = $client->createGeneration($apiPayload, $idempotencyKey);

        if (!$res['success']) {
            $errorCode = $res['error']['code'] ?? 'api_error';
            $errorMessage = $res['error']['message'] ?? 'Failed to queue generation';

            $generation->update([
                'status' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'provider_cost_usd' => '0.000000000',
                'user_charged_usd' => '0.000000000',
                'profit_usd' => '0.000000000',
                'billed_at' => now(),
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => $errorMessage,
                    'generation' => $generation,
                ], $res['status'] >= 400 && $res['status'] < 600 ? $res['status'] : 422);
            }

            return back()->with('error', $errorMessage);
        }

        // Successfully queued with Pixelforge
        $pixelforgeId = $res['data']['id'] ?? null;
        $generation->update([
            'pixelforge_id' => $pixelforgeId,
            'status' => $res['data']['status'] ?? 'queued',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'generation' => $generation,
            ]);
        }

        return back()->with('success', 'Generation submitted to Pixelforge queue!');
    }

    /**
     * Poll status of single generation (used as fallback or quick update in UI).
     */
    public function checkStatus(Generation $generation, PixelforgeClient $client, PixelforgeWebhookService $webhookService): JsonResponse
    {
        // If already final, return local state
        if ($generation->isFinal()) {
            return response()->json([
                'generation' => $generation,
                'user_balance' => (string) $generation->user->balance_usd,
            ]);
        }

        // Query Pixelforge if has pixelforge_id
        if ($generation->pixelforge_id) {
            $res = $client->getGeneration($generation->pixelforge_id);
            if ($res['success']) {
                $status = $res['data']['status'] ?? $generation->status;
                if ($status !== $generation->status && in_array($status, ['processing', 'succeeded', 'failed'])) {
                    // Process event safely through webhook service logic
                    $fakeEvent = [
                        'id' => 'poll_sync_' . uniqid(),
                        'type' => "generation.{$status}",
                        'data' => ['object' => $res['data']],
                    ];
                    $webhookService->processEvent($fakeEvent, $fakeEvent['id']);
                    $generation->refresh();
                }
            }
        }

        return response()->json([
            'generation' => $generation,
            'user_balance' => (string) $generation->user->balance_usd,
        ]);
    }
}
