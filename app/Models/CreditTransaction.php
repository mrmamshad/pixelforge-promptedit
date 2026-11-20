<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'amount_usd',
        'balance_after_usd',
        'description',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_usd' => 'string',
            'balance_after_usd' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
