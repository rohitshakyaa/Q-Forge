<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminOverviewController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\BlueprintController;
use App\Http\Controllers\Api\BankExpansionController;
use App\Http\Controllers\Api\PaperController;
use App\Http\Controllers\Api\PastPaperController;
use App\Http\Controllers\Api\DocumentUploadController;
use App\Http\Controllers\Api\QuestionReviewController;
use App\Http\Controllers\Api\UserController;
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
        // Dashboard overview — system-wide aggregate counts + activity feed.
        Route::get('admin/overview', [AdminOverviewController::class, 'index']);

        // Users & Roles — admin-provisioned accounts (no public signup).
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::apiResource('subjects', SubjectController::class)->except(['index', 'show']);
        Route::apiResource('subjects.units', UnitController::class)->shallow()->except(['index']);

        // M4 — extraction review queue. Static segments before the {question}
        // wildcard so they aren't swallowed by it.
        Route::post('questions/bulk-approve', [QuestionReviewController::class, 'bulkApprove']);
        Route::post('questions/bulk-reject', [QuestionReviewController::class, 'bulkReject']);
        Route::post('questions/{question}/approve', [QuestionReviewController::class, 'approve']);
        Route::post('questions/{question}/reject', [QuestionReviewController::class, 'reject']);
        Route::apiResource('questions', QuestionController::class);

        // M4 — past-paper/syllabus PDF upload → queued extraction → review queue.
        // Static segment before the apiResource so it isn't read as {documentUpload}.
        Route::post('uploads/{documentUpload}/import', [DocumentUploadController::class, 'import']);
        Route::apiResource('uploads', DocumentUploadController::class)
            ->parameters(['uploads' => 'documentUpload'])
            ->only(['index', 'store', 'show', 'destroy']);

        // M3.1 — record a real past exam as an imported paper (repetition source).
        Route::post('subjects/{subject}/past-papers', [PastPaperController::class, 'store']);
    });

    // Teacher-only, owner-scoped blueprints + paper generation, lifecycle, export.
    Route::middleware('role:teacher')->group(function () {
        Route::apiResource('blueprints', BlueprintController::class);
        // M5 — AI bank expansion for an infeasible blueprint (re-derives the shortfall
        // server-side), then batch-status polling for the dispatched job.
        Route::post('blueprints/{blueprint}/expand-bank', [BankExpansionController::class, 'expand']);
        Route::get('jobs/{batchId}', [BankExpansionController::class, 'jobStatus']);
        Route::post('papers/generate', [PaperController::class, 'generate']);
        // Static segments before the {paper} wildcard so they aren't swallowed by it.
        Route::get('papers/analytics', [PaperController::class, 'analytics']);
        Route::get('papers/{paper}/export', [PaperController::class, 'export']);
        Route::post('papers/{paper}/save', [PaperController::class, 'save']);
        Route::apiResource('papers', PaperController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    });
});
