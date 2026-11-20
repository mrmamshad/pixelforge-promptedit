<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_type',
        'delivery_attempt',
        'payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
