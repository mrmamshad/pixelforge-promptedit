<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'balance_usd',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance_usd' => 'string',
            'is_admin' => 'boolean',
        ];
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Check if user has at least $amount in balance.
     */
    public function hasBalance(string $amount): bool
    {
        return bccomp((string) $this->balance_usd, (string) $amount, 9) >= 0;
    }

    /**
     * Adjust balance atomically and log credit transaction.
     */
    public function adjustBalance(string $amountDelta, string $type, string $description, ?string $referenceId = null): CreditTransaction
    {
        return DB::transaction(function () use ($amountDelta, $type, $description, $referenceId) {
            /** @var User $freshUser */
            $freshUser = User::where('id', $this->id)->lockForUpdate()->first();

            $newBalance = bcadd((string) $freshUser->balance_usd, (string) $amountDelta, 9);
            if (bccomp($newBalance, '0.000000000', 9) < 0) {
                $newBalance = '0.000000000';
            }

            $freshUser->balance_usd = $newBalance;
            $freshUser->save();

            $tx = $freshUser->creditTransactions()->create([
                'type' => $type,
                'amount_usd' => (string) $amountDelta,
                'balance_after_usd' => (string) $newBalance,
                'description' => $description,
                'reference_id' => $referenceId,
            ]);

            $this->balance_usd = $newBalance;

            return $tx;
        });
    }
}
