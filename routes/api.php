<?php

use App\Http\Controllers\Internal\N8nProductQuestionDraftController;
use App\Http\Controllers\Internal\N8nProductQuestionWorkflowController;
use App\Services\ProductQuestions\DressLengthChart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/n8n')
    ->middleware('internal.n8n')
    ->group(function (): void {
        Route::get('/reference/dress-lengths', function (Request $request, DressLengthChart $chart) {
            $input = $request->validate([
                'hemline' => ['nullable', 'required_with:size', 'string', 'max:100'],
                'size' => ['nullable', 'required_with:hemline', 'string', 'max:10'],
            ]);

            return response()->json(isset($input['hemline'], $input['size'])
                ? ['data' => $chart->lookup($input['hemline'], $input['size'])]
                : ['data' => $chart->all()]);
        });
        Route::post('/product-question-analysis/lease', [N8nProductQuestionWorkflowController::class, 'leaseAnalysis']);
        Route::post('/product-question-drafts/lease', [N8nProductQuestionWorkflowController::class, 'leaseDrafts']);
        Route::post('/product-questions/{question}/analyze', [N8nProductQuestionWorkflowController::class, 'analyze']);
        Route::post('/product-questions/{question}/draft', [N8nProductQuestionWorkflowController::class, 'draft']);
        Route::post('/draft-requests/{requestId}/start', [N8nProductQuestionDraftController::class, 'start']);
        Route::post('/draft-requests/{requestId}/facts', [N8nProductQuestionDraftController::class, 'facts']);
        Route::post('/draft-requests/{requestId}/policies', [N8nProductQuestionDraftController::class, 'policies']);
        Route::post('/draft-requests/{requestId}/complete', [N8nProductQuestionDraftController::class, 'complete']);
        Route::post('/draft-requests/{requestId}/fail', [N8nProductQuestionDraftController::class, 'fail']);
        Route::post('/ai/structured-completion', [N8nProductQuestionDraftController::class, 'completion']);
    });
