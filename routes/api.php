<?php

use App\Http\Controllers\Internal\N8nProductQuestionDraftController;
use App\Http\Controllers\Internal\N8nProductQuestionWorkflowController;
use App\Http\Controllers\Internal\N8nTicketDraftController;
use App\Services\ProductQuestions\DressLengthChart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/n8n')
    ->middleware('internal.n8n:work')
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
        Route::post('/draft-requests/{requestId}/start', [N8nProductQuestionDraftController::class, 'start']);
        Route::post('/draft-requests/{requestId}/facts', [N8nProductQuestionDraftController::class, 'facts']);
        Route::post('/draft-requests/{requestId}/policies', [N8nProductQuestionDraftController::class, 'policies']);
        Route::post('/draft-requests/{requestId}/complete', [N8nProductQuestionDraftController::class, 'complete']);
        Route::post('/draft-requests/{requestId}/fail', [N8nProductQuestionDraftController::class, 'fail']);
        Route::post('/ai/structured-completion', [N8nProductQuestionDraftController::class, 'completion']);
        Route::post('/ticket-work-items/{workItemKey}/start', [N8nTicketDraftController::class, 'start']);
        Route::post('/ticket-work-items/{workItemKey}/facts', [N8nTicketDraftController::class, 'facts']);
        Route::post('/ticket-work-items/{workItemKey}/policies', [N8nTicketDraftController::class, 'policies']);
        Route::post('/ticket-work-items/{workItemKey}/complete', [N8nTicketDraftController::class, 'complete']);
        Route::post('/ticket-work-items/{workItemKey}/fail', [N8nTicketDraftController::class, 'fail']);
    });

Route::prefix('internal/n8n')
    ->middleware('internal.n8n:analysis')
    ->group(function (): void {
        Route::post('/product-question-analysis/lease', [N8nProductQuestionWorkflowController::class, 'leaseAnalysis']);
        Route::post('/product-questions/{question}/analyze', [N8nProductQuestionWorkflowController::class, 'analyze']);
    });
