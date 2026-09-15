<?php

use App\Http\Controllers\Api\MerchantDashboardController;
use App\Http\Controllers\Api\UsageEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// TODO: scope this token to the subscription's merchant once merchant-level
// API tokens exist - right now auth:sanctum only proves *some* valid token,
// not that the caller owns this subscription.
Route::middleware(['auth:sanctum', 'throttle:usage-events'])->post('/usage-events', [UsageEventController::class, 'store']);

// TODO: same gap as above - scope to the authenticated merchant once
// merchant-level tokens exist, rather than trusting the route parameter.
Route::middleware('auth:sanctum')->get('/merchants/{merchant}/dashboard', [MerchantDashboardController::class, 'show']);
