<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    /**
     * Simulated credit purchase for testing balance stretch goal.
     */
    public function topup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:500',
        ]);

        $user = $request->user() ?: User::first();
        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        $amountFormatted = number_format((float) $validated['amount'], 9, '.', '');

        $user->adjustBalance(
            $amountFormatted,
            'topup',
            "Simulated credit top-up (\${$validated['amount']})"
        );

        return back()->with('success', "Added \${$validated['amount']} credits to your account!");
    }
}
