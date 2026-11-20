<?php

namespace App\Http\Controllers;

use App\Services\PixelforgeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, PixelforgeWebhookService $webhookService): JsonResponse
    {
        $rawPayload = (string) $request->getContent();
        $signatureHeader = $request->header('Pixelforge-Signature');

        if (!$webhookService->verifySignature($rawPayload, $signatureHeader)) {
            Log::warning('Pixelforge webhook received with invalid signature', [
                'ip' => $request->ip(),
                'signature' => $signatureHeader,
            ]);
            return response()->json(['error' => 'Invalid webhook signature'], 401);
        }

        $eventData = $request->json()->all();
        $eventId = $request->header('Pixelforge-Event-Id') ?: ($eventData['id'] ?? null);
        $deliveryAttempt = (int) ($request->header('Pixelforge-Delivery-Attempt') ?: 1);

        $result = $webhookService->processEvent($eventData, $eventId, $deliveryAttempt);

        return response()->json([
            'received' => true,
            'event_id' => $eventId,
            'result' => $result,
        ], 200);
    }
}
