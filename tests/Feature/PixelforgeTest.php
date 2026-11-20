<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\PixelforgePricingService;
use App\Services\PixelforgeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PixelforgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.webhook_secret' => 'whsec_test_secret_key_12345']);
        config(['pixelforge.markup_percent' => 25]);
    }

    /**
     * Verify the official worked example from the Pixelforge documentation matches down to 9 decimal places.
     */
    public function test_pricing_service_calculates_exact_cost_and_markup(): void
    {
        $pricingService = new PixelforgePricingService();

        $rates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

        // Worked example from docs:
        // text = 115, image = 1530, output = 6450, reasoning = 8000
        $usage = [
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ];

        $providerCost = $pricingService->calculateTokenCost($usage, $rates);
        // Doc says: 0.373875000
        $this->assertSame('0.373875000', $providerCost);

        // 25% markup: 0.373875 * 1.25 = 0.467343750
        $userPrice = $pricingService->applyMarkup($providerCost, 25);
        $this->assertSame('0.467343750', $userPrice);
    }

    /**
     * Webhook verification: Valid HMAC succeeds, tampered body or invalid signature rejected with 401.
     */
    public function test_webhook_signature_verification(): void
    {
        $payload = json_encode([
            'id' => 'evt_test_01',
            'type' => 'ping',
            'created_at' => now()->toIso8601String(),
            'data' => ['object' => []],
        ]);

        $timestamp = time();
        $secret = 'whsec_test_secret_key_12345';
        $validSig = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        // 1. Valid request
        $response = $this->call(
            'POST',
            '/webhooks/pixelforge',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$validSig}",
                'HTTP_PIXELFORGE_EVENT_ID' => 'evt_test_01',
            ],
            $payload
        );
        $response->assertStatus(200);

        // 2. Tampered signature
        $badResponse = $this->call(
            'POST',
            '/webhooks/pixelforge',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1=invalid_tampered_signature",
            ],
            $payload
        );
        $badResponse->assertStatus(401);
    }

    /**
     * Webhooks delivered twice (e.g. [duplicate] test keyword) must NEVER bill user twice.
     */
    public function test_webhook_idempotency_prevents_duplicate_billing(): void
    {
        $user = User::factory()->create([
            'balance_usd' => '10.000000000',
        ]);

        $generation = Generation::create([
            'pixelforge_id' => 'gen_idempotent_test_01',
            'user_id' => $user->id,
            'prompt' => 'Cyberpunk neon car [duplicate]',
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => 'queued',
        ]);

        $eventData = [
            'id' => 'evt_succeeded_dup_01',
            'type' => 'generation.succeeded',
            'data' => [
                'object' => [
                    'id' => 'gen_idempotent_test_01',
                    'status' => 'succeeded',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/test.png',
                    'usage' => [
                        'input_text_tokens' => 10,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 500,
                    ],
                    'rates' => [
                        'input_text_tokens' => '5.00',
                        'input_image_tokens' => '10.00',
                        'output_tokens' => '40.00',
                        'reasoning_tokens' => '12.50',
                    ],
                ],
            ],
        ];

        $webhookService = app(PixelforgeWebhookService::class);

        // First delivery attempt
        $res1 = $webhookService->processEvent($eventData, 'evt_succeeded_dup_01', 1);
        $this->assertSame('success', $res1['status']);

        $user->refresh();
        $balanceAfterFirst = $user->balance_usd;
        $this->assertTrue(bccomp($balanceAfterFirst, '10.000000000', 9) < 0);

        // Second delivery attempt (simulating duplicate webhook delivery)
        $res2 = $webhookService->processEvent($eventData, 'evt_succeeded_dup_01', 2);
        $this->assertSame('duplicate', $res2['status']);

        $user->refresh();
        // Balance must remain identical (never double-charged)
        $this->assertSame($balanceAfterFirst, $user->balance_usd);
    }

    /**
     * Generations that fail cost $0.00 and are never billed to the user.
     */
    public function test_failed_generations_cost_zero(): void
    {
        $user = User::factory()->create([
            'balance_usd' => '10.000000000',
        ]);

        $generation = Generation::create([
            'pixelforge_id' => 'gen_fail_test_01',
            'user_id' => $user->id,
            'prompt' => 'An impossible prompt [fail]',
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => 'queued',
        ]);

        $eventData = [
            'id' => 'evt_failed_01',
            'type' => 'generation.failed',
            'data' => [
                'object' => [
                    'id' => 'gen_fail_test_01',
                    'status' => 'failed',
                    'error' => [
                        'code' => 'generation_failed',
                        'message' => 'The model could not produce an image for the prompt.',
                    ],
                ],
            ],
        ];

        $webhookService = app(PixelforgeWebhookService::class);
        $webhookService->processEvent($eventData, 'evt_failed_01', 1);

        $generation->refresh();
        $user->refresh();

        $this->assertSame('failed', $generation->status);
        $this->assertSame(0, bccomp('0.000000000', (string) $generation->user_charged_usd, 9));
        $this->assertSame(0, bccomp('10.000000000', (string) $user->balance_usd, 9));
    }

    /**
     * Out of order webhook delivery must NEVER move a generation back from succeeded to processing.
     */
    public function test_out_of_order_webhooks_do_not_downgrade_status(): void
    {
        $user = User::factory()->create(['balance_usd' => '10.000000000']);
        $generation = Generation::create([
            'pixelforge_id' => 'gen_order_test_01',
            'user_id' => $user->id,
            'prompt' => 'Order test',
            'status' => 'succeeded',
        ]);

        $webhookService = app(PixelforgeWebhookService::class);

        // Delayed generation.processing event arriving after generation.succeeded
        $eventData = [
            'id' => 'evt_delayed_processing_01',
            'type' => 'generation.processing',
            'data' => [
                'object' => [
                    'id' => 'gen_order_test_01',
                    'status' => 'processing',
                ],
            ],
        ];

        $webhookService->processEvent($eventData, 'evt_delayed_processing_01', 1);

        $generation->refresh();
        // Must remain succeeded
        $this->assertSame('succeeded', $generation->status);
    }

    /**
     * Balance guard: User with insufficient balance is blocked from generating.
     */
    public function test_insufficient_balance_blocks_generating(): void
    {
        $user = User::factory()->create([
            'balance_usd' => '0.000000000',
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/generations', [
            'prompt' => 'A lighthouse in high detail',
            'size' => '1536x1024',
            'quality' => 'high',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }
}
