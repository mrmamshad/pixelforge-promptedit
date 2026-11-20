<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgePricingService
{
    public const SCALE = 9;

    /**
     * Default fallback rates in case API is temporarily unreachable.
     */
    protected array $defaultPricing = [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];

    /**
     * Get live pricing data from Pixelforge API or cache.
     */
    public function getPricing(): array
    {
        return Cache::remember('pixelforge_pricing_data', 600, function () {
            try {
                $response = Http::timeout(5)->get(config('pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1') . '/pricing');
                if ($response->successful()) {
                    return $response->json();
                }
            } catch (\Throwable $e) {
                // fall back to defaults
            }
            return $this->defaultPricing;
        });
    }

    /**
     * Estimate token counts and min/max/expected costs upfront.
     */
    public function estimate(string $prompt, string $size = '1024x1024', string $quality = 'medium', array $referenceImages = []): array
    {
        $pricing = $this->getPricing();
        $rates = $pricing['rates'] ?? $this->defaultPricing['rates'];
        $tokensConfig = $pricing['tokens'] ?? $this->defaultPricing['tokens'];

        // 1. Text tokens: ~1 token per 4 chars (Unicode), +-15% variance
        $charCount = mb_strlen($prompt);
        $baseTextTokens = (int) ceil($charCount / ($tokensConfig['characters_per_text_token'] ?? 4));
        if ($baseTextTokens < 1 && $charCount > 0) {
            $baseTextTokens = 1;
        }

        $variance = (float) ($tokensConfig['text_token_variance'] ?? 0.15);
        $minTextTokens = (int) floor($baseTextTokens * (1 - $variance));
        $maxTextTokens = (int) ceil($baseTextTokens * (1 + $variance));

        // 2. Reference image tokens: 765 per image
        $imageCount = count($referenceImages);
        $imageTokens = $imageCount * ($tokensConfig['per_reference_image'] ?? 765);

        // 3. Output tokens: exact lookup
        $outputTokens = $tokensConfig['output'][$quality][$size]
            ?? $this->defaultPricing['tokens']['output'][$quality][$size]
            ?? 1100;

        // 4. Reasoning tokens: min/max by quality
        $reasoningRange = $tokensConfig['reasoning'][$quality]
            ?? $this->defaultPricing['tokens']['reasoning'][$quality]
            ?? ['min' => 200, 'max' => 2000];

        $minReasoning = $reasoningRange['min'];
        $maxReasoning = $reasoningRange['max'];
        $avgReasoning = (int) round(($minReasoning + $maxReasoning) / 2);

        // Calculate cost for min, expected (avg), and max
        $costMin = $this->calculateTokenCost([
            'input_text_tokens' => $minTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $minReasoning,
        ], $rates);

        $costExpected = $this->calculateTokenCost([
            'input_text_tokens' => $baseTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $avgReasoning,
        ], $rates);

        $costMax = $this->calculateTokenCost([
            'input_text_tokens' => $maxTextTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $maxReasoning,
        ], $rates);

        $markup = (float) config('pixelforge.markup_percent', 25);

        return [
            'rates' => $rates,
            'tokens_breakdown' => [
                'text_estimated' => $baseTextTokens,
                'text_range' => [$minTextTokens, $maxTextTokens],
                'image_tokens' => $imageTokens,
                'output_tokens' => $outputTokens,
                'reasoning_range' => [$minReasoning, $maxReasoning],
            ],
            'provider_cost' => [
                'min' => $costMin,
                'expected' => $costExpected,
                'max' => $costMax,
            ],
            'user_price' => [
                'min' => $this->applyMarkup($costMin, $markup),
                'expected' => $this->applyMarkup($costExpected, $markup),
                'max' => $this->applyMarkup($costMax, $markup),
            ],
        ];
    }

    /**
     * Calculate exact provider cost from actual usage tokens and rates.
     */
    public function calculateTokenCost(array $usage, array $rates): string
    {
        $inputText = (string) ($usage['input_text_tokens'] ?? 0);
        $inputImage = (string) ($usage['input_image_tokens'] ?? 0);
        $output = (string) ($usage['output_tokens'] ?? 0);
        $reasoning = (string) ($usage['reasoning_tokens'] ?? 0);

        $rateText = (string) ($rates['input_text_tokens'] ?? '5.00');
        $rateImage = (string) ($rates['input_image_tokens'] ?? '10.00');
        $rateOutput = (string) ($rates['output_tokens'] ?? '40.00');
        $rateReasoning = (string) ($rates['reasoning_tokens'] ?? '12.50');

        $oneMillion = '1000000';

        // item_cost = (tokens * rate) / 1,000,000
        $costText = bcdiv(bcmul($inputText, $rateText, self::SCALE), $oneMillion, self::SCALE);
        $costImage = bcdiv(bcmul($inputImage, $rateImage, self::SCALE), $oneMillion, self::SCALE);
        $costOutput = bcdiv(bcmul($output, $rateOutput, self::SCALE), $oneMillion, self::SCALE);
        $costReasoning = bcdiv(bcmul($reasoning, $rateReasoning, self::SCALE), $oneMillion, self::SCALE);

        $total = bcadd($costText, $costImage, self::SCALE);
        $total = bcadd($total, $costOutput, self::SCALE);
        $total = bcadd($total, $costReasoning, self::SCALE);

        return $total;
    }

    /**
     * Apply markup percentage to provider cost.
     * e.g. 25% markup means cost * 1.25.
     */
    public function applyMarkup(string $providerCost, float $markupPercent = 25.0): string
    {
        $multiplier = bcadd('1', bcdiv((string) $markupPercent, '100', self::SCALE), self::SCALE);
        return bcmul($providerCost, $multiplier, self::SCALE);
    }
}
