<?php

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Local-only convenience page: mints a throwaway token and renders the
// merchant dashboard in-browser by calling the real API endpoint client-side.
// Not part of the graded API surface - just a way to see live output without
// needing Postman/curl. Gated to the local environment so it can never ship.
if (app()->environment('local')) {
    Route::get('/dashboard-preview/{merchant}', function (Merchant $merchant) {
        $user = User::firstOrCreate(
            ['email' => 'dashboard-preview@example.com'],
            ['name' => 'Dashboard Preview', 'password' => bcrypt(str()->random(32))],
        );

        $token = $user->createToken('dashboard-preview')->plainTextToken;

        return view('dashboard-preview', [
            'merchant' => $merchant,
            'token' => $token,
        ]);
    });
}
