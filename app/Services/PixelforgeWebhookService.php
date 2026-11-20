<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookService
{
    public function __construct(
        protected PixelforgePricingService $pricingService
    ) {}

    /**
     * Verify the Pixelforge webhook signature.
     * Pixelforge-Signature: t=1762265009,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd
     */
    public function verifySignature(string $rawPayload, ?string $signatureHeader): bool
    {
        if (empty($signatureHeader)) {
            return false;
        }

        $secret = config('pixelforge.webhook_secret');
        if (empty($secret)) {
            Log::warning('PIXELFORGE_WEBHOOK_SECRET is not configured.');
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        if (!isset($parts['t']) || !isset($parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $signature = $parts['v1'];

        // Reject events older than 300 seconds (5 mins) or too far in the future
        if (abs(time() - $timestamp) > 300) {
            Log::warning('Pixelforge webhook timestamp out of tolerance', ['timestamp' => $timestamp, 'now' => time()]);
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Process an incoming verified webhook event with idempotency and state safety.
     */
    public function processEvent(array $eventData, ?string $eventId, int $deliveryAttempt = 1): array
    {
        $eventId = $eventId ?: ($eventData['id'] ?? ('gen_evt_' . uniqid()));
        $eventType = $eventData['type'] ?? 'unknown';

        // 1. Idempotency Check: if event already processed, do not re-run
        $existingEvent = WebhookEvent::where('event_id', $eventId)->first();
        if ($existingEvent) {
            Log::info("Pixelforge webhook event {$eventId} already processed, skipping deduplication.");
            return [
                'status' => 'duplicate',
                'message' => 'Event already processed',
            ];
        }

        $genData = $eventData['data']['object'] ?? [];
        $pixelforgeId = $genData['id'] ?? null;
        $metadata = $genData['metadata'] ?? [];
        $appGenId = $metadata['app_generation_id'] ?? null;
        $appUserId = $metadata['app_user_id'] ?? null;

        DB::transaction(function () use ($eventId, $eventType, $deliveryAttempt, $eventData, $pixelforgeId, $genData, $appGenId, $appUserId) {
            // Find target generation
            $generation = null;
            if ($pixelforgeId) {
                $generation = Generation::where('pixelforge_id', $pixelforgeId)->first();
            }
            if (!$generation && $appGenId) {
                $generation = Generation::find($appGenId);
            }

            // In race condition (webhook arrives before POST returns): create placeholder
            if (!$generation && $pixelforgeId) {
                $user = null;
                if ($appUserId) {
                    $user = User::find($appUserId);
                }
                if (!$user) {
                    $user = User::first() ?? User::factory()->create([
                        'name' => 'Demo User',
                        'email' => 'user@pixelforge.local',
                    ]);
                }

                $generation = Generation::create([
                    'pixelforge_id' => $pixelforgeId,
                    'user_id' => $user->id,
                    'prompt' => $genData['prompt'] ?? 'Untitled generation',
                    'size' => $genData['size'] ?? '1024x1024',
                    'quality' => $genData['quality'] ?? 'medium',
                    'reference_images' => $genData['reference_images'] ?? [],
                    'status' => 'queued',
                ]);
            }

            if ($generation) {
                if ($pixelforgeId && empty($generation->pixelforge_id)) {
                    $generation->pixelforge_id = $pixelforgeId;
                }

                switch ($eventType) {
                    case 'generation.processing':
                        // Only transition if still queued (never move backwards from succeeded or failed)
                        if ($generation->status === 'queued') {
                            $generation->status = 'processing';
                            $generation->save();
                        }
                        break;

                    case 'generation.succeeded':
                        // If already billed and succeeded, skip duplicate charging
                        if ($generation->status === 'succeeded' && $generation->billed_at !== null) {
                            break;
                        }

                        $usage = $genData['usage'] ?? [];
                        $rates = $genData['rates'] ?? $this->pricingService->getPricing()['rates'];

                        $providerCost = $this->pricingService->calculateTokenCost($usage, $rates);
                        $markupPercent = (float) config('pixelforge.markup_percent', 25);
                        $userCharge = $this->pricingService->applyMarkup($providerCost, $markupPercent);
                        $profit = bcsub($userCharge, $providerCost, 9);

                        $generation->update([
                            'status' => 'succeeded',
                            'image_url' => $genData['image_url'] ?? null,
                            'image_url_expires_at' => $genData['image_url_expires_at'] ?? null,
                            'input_text_tokens' => $usage['input_text_tokens'] ?? 0,
                            'input_image_tokens' => $usage['input_image_tokens'] ?? 0,
                            'output_tokens' => $usage['output_tokens'] ?? 0,
                            'reasoning_tokens' => $usage['reasoning_tokens'] ?? 0,
                            'total_tokens' => $usage['total_tokens'] ?? 0,
                            'rate_input_text' => (string) ($rates['input_text_tokens'] ?? '5.00'),
                            'rate_input_image' => (string) ($rates['input_image_tokens'] ?? '10.00'),
                            'rate_output' => (string) ($rates['output_tokens'] ?? '40.00'),
                            'rate_reasoning' => (string) ($rates['reasoning_tokens'] ?? '12.50'),
                            'provider_cost_usd' => $providerCost,
                            'user_charged_usd' => $userCharge,
                            'profit_usd' => $profit,
                            'billed_at' => now(),
                        ]);

                        // Charge user credit balance atomically
                        $user = $generation->user;
                        if ($user) {
                            $desc = "Pixelforge image generation #{$generation->id} ({$generation->size}, {$generation->quality})";
                            $user->adjustBalance('-' . $userCharge, 'generation_charge', $desc, (string) $generation->id);
                        }
                        break;

                    case 'generation.failed':
                        // Failed generations are completely free (never charged)
                        if ($generation->status !== 'succeeded') {
                            $generation->update([
                                'status' => 'failed',
                                'error_code' => $genData['error']['code'] ?? 'generation_failed',
                                'error_message' => $genData['error']['message'] ?? 'Image generation failed',
                                'provider_cost_usd' => '0.000000000',
                                'user_charged_usd' => '0.000000000',
                                'profit_usd' => '0.000000000',
                                'billed_at' => now(),
                            ]);
                        }
                        break;
                }
            }

            // Log event into WebhookEvent table for historical audit
            WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'delivery_attempt' => $deliveryAttempt,
                'payload' => $eventData,
                'status' => 'processed',
            ]);
        });

        return [
            'status' => 'success',
            'message' => "Event {$eventId} processed successfully",
        ];
    }
}
