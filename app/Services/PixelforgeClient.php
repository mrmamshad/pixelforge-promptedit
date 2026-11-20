<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PixelforgeClient
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('pixelforge.api_key');
        $this->baseUrl = rtrim((string) config('pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1'), '/');
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ])->timeout(30);
    }

    /**
     * Submit a generation request to Pixelforge.
     */
    public function createGeneration(array $payload, ?string $idempotencyKey = null): array
    {
        $request = $this->http();
        if ($idempotencyKey) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        try {
            $response = $request->post("{$this->baseUrl}/generations", $payload);
            return $this->formatResponse($response);
        } catch (\Throwable $e) {
            Log::error('Pixelforge createGeneration network error', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'status' => 500,
                'error' => [
                    'code' => 'network_error',
                    'message' => 'Unable to reach Pixelforge provider: ' . $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Retrieve a generation by its Pixelforge ID.
     */
    public function getGeneration(string $id): array
    {
        try {
            $response = $this->http()->get("{$this->baseUrl}/generations/{$id}");
            return $this->formatResponse($response);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => ['code' => 'network_error', 'message' => $e->getMessage()],
            ];
        }
    }

    /**
     * Get usage reports for reconciliation with GET /v1/usage.
     */
    public function getUsage(?string $startDate = null, ?string $endDate = null): array
    {
        try {
            $params = array_filter([
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            $response = $this->http()->get("{$this->baseUrl}/usage", $params);
            return $this->formatResponse($response);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => ['code' => 'network_error', 'message' => $e->getMessage()],
            ];
        }
    }

    protected function formatResponse(Response $response): array
    {
        $body = $response->json() ?? [];
        if ($response->successful()) {
            return [
                'success' => true,
                'status' => $response->status(),
                'data' => $body,
            ];
        }

        return [
            'success' => false,
            'status' => $response->status(),
            'error' => $body['error'] ?? [
                'code' => 'api_error_' . $response->status(),
                'message' => $response->body(),
            ],
        ];
    }
}
