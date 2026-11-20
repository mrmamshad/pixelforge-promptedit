<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    protected $fillable = [
        'pixelforge_id',
        'user_id',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'status',
        'image_url',
        'image_url_expires_at',
        'error_code',
        'error_message',
        'idempotency_key',
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
        'total_tokens',
        'rate_input_text',
        'rate_input_image',
        'rate_output',
        'rate_reasoning',
        'estimated_cost_usd',
        'provider_cost_usd',
        'user_charged_usd',
        'profit_usd',
        'billed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'image_url_expires_at' => 'datetime',
            'billed_at' => 'datetime',
            'estimated_cost_usd' => 'string',
            'provider_cost_usd' => 'string',
            'user_charged_usd' => 'string',
            'profit_usd' => 'string',
            'rate_input_text' => 'string',
            'rate_input_image' => 'string',
            'rate_output' => 'string',
            'rate_reasoning' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }
}
