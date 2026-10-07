<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\TrackerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/tracker/poll', TrackerController::class)->middleware(['bridge', 'throttle:tracker']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:frontend'])->group(function () {
    Route::get('/auth/me', fn (Request $request) => $request->user());
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::get('/devices/{device}', [DeviceController::class, 'show'])->whereNumber('device');
    Route::patch('/devices/{device}/settings', [DeviceController::class, 'settings'])->whereNumber('device');
    Route::get('/devices/{device}/commands', [DeviceController::class, 'commands'])->whereNumber('device');
    Route::post('/devices/{device}/commands', [DeviceController::class, 'storeCommand'])->whereNumber('device');
    Route::get('/devices/{device}/reports', [DeviceController::class, 'reports'])->whereNumber('device');
});
