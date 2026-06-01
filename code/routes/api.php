<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\BlueprintController;
use App\Http\Controllers\Api\PaperController;
use App\Services\PythonService;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/test-python', function (PythonService $python) {
    try {
        return response()->json([
            'status' => 'success',
            'python_response' => $python->health(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
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

/*
|--------------------------------------------------------------------------
| QForge domain (M1)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Shared read (admin + teacher): catalog browsing for blueprint building.
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('subjects', [SubjectController::class, 'index']);
        Route::get('subjects/{subject}', [SubjectController::class, 'show']);
        Route::get('subjects/{subject}/units', [UnitController::class, 'index']);
    });

    // Admin-only writes for the catalog + full question management.
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('subjects', SubjectController::class)->except(['index', 'show']);
        Route::apiResource('subjects.units', UnitController::class)->shallow()->except(['index']);
        Route::apiResource('questions', QuestionController::class);
    });

    // Teacher-only, owner-scoped blueprints + paper generation.
    Route::middleware('role:teacher')->group(function () {
        Route::apiResource('blueprints', BlueprintController::class);
        Route::post('papers/generate', [PaperController::class, 'generate']);
    });
});
