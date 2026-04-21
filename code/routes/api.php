<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Api\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/test-python', function () {
    try {
        $response = Http::get(config('app.python_url') . '/health');
        return response()->json([
            'status' => 'success',
            'python_response' => $response->json()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin/dashboard', function () {
    return response()->json([
        'message' => 'Welcome admin',
    ]);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->get('/teacher/dashboard', function () {
    return response()->json([
        'message' => 'Welcome teacher',
    ]);
});
