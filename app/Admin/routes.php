<?php

use App\Admin\Controllers\BenchmarkCaseController;
use App\Admin\Controllers\ChatBotChatController;
use App\Admin\Controllers\CommerceSiteController;
use App\Admin\Controllers\ConversationController;
use App\Admin\Controllers\CustomerCaseController;
use App\Admin\Controllers\HomeController;
use App\Admin\Controllers\KnowledgeArticleController;
use App\Admin\Controllers\ProductQuestionController;
use App\Admin\Controllers\ProductQuestionPolicyController;
use App\Admin\Controllers\WorkflowReplayReviewController;
use Encore\Admin\Facades\Admin;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

Admin::routes();

Route::group([
    'prefix' => config('admin.route.prefix'),
    'middleware' => config('admin.route.middleware'),
    'as' => config('admin.route.prefix').'.',
], function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'])->name('home');
    $router->get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    $router->get('conversations/{id}', [ConversationController::class, 'show'])->name('conversations.show');
    $router->post('conversations/{id}/draft', [ConversationController::class, 'storeDraft'])->name('conversations.draft');
    $router->post('conversations/{id}/verify-cancellation', [ConversationController::class, 'verifyCancellation'])->name('conversations.verify-cancellation');
    $router->post('conversations/{id}/cases/{case}/review', [ConversationController::class, 'reviewCase'])->name('conversations.cases.review');
    $router->post('conversations/{id}/nodes/{node}/review', [ConversationController::class, 'reviewNode'])->name('conversations.nodes.review');
    $router->get('chatbot-chats', [ChatBotChatController::class, 'index'])->name('chatbot-chats.index');
    $router->get('chatbot-chats/{id}', [ChatBotChatController::class, 'show'])->name('chatbot-chats.show');
    $router->get('benchmark-cases', [BenchmarkCaseController::class, 'index'])->name('benchmark-cases.index');
    $router->get('benchmark-cases/{id}', [BenchmarkCaseController::class, 'show'])->name('benchmark-cases.show');
    $router->post('benchmark-cases/{id}/steps', [BenchmarkCaseController::class, 'storeStep'])->name('benchmark-cases.steps.store');
    $router->put('benchmark-cases/{id}/steps/{stepId}', [BenchmarkCaseController::class, 'updateStep'])->name('benchmark-cases.steps.update');
    $router->get('benchmark-cases/{id}/steps/{stepId}', [BenchmarkCaseController::class, 'showStep'])->name('benchmark-cases.steps.show');
    $router->post('benchmark-cases/{id}/steps/{stepId}/annotations', [BenchmarkCaseController::class, 'storeStepAnnotation'])->name('benchmark-cases.steps.annotations.store');
    $router->post('benchmark-cases/{id}/annotations', [BenchmarkCaseController::class, 'storeAnnotation'])->name('benchmark-cases.annotations.store');
    $router->get('commerce-sites', [CommerceSiteController::class, 'index'])->name('commerce-sites.index');
    $router->get('commerce-sites/create', [CommerceSiteController::class, 'create'])->name('commerce-sites.create');
    $router->post('commerce-sites', [CommerceSiteController::class, 'store'])->name('commerce-sites.store');
    $router->get('commerce-sites/{commerceSite}/edit', [CommerceSiteController::class, 'edit'])->name('commerce-sites.edit');
    $router->put('commerce-sites/{commerceSite}', [CommerceSiteController::class, 'update'])->name('commerce-sites.update');
    $router->get('product-questions', [ProductQuestionController::class, 'index'])->name('product-questions.index');
    $router->get('product-questions/{questionId}', [ProductQuestionController::class, 'show'])->name('product-questions.show');
    $router->post('product-questions/{questionId}/generate-draft', [ProductQuestionController::class, 'generateDraft'])->name('product-questions.generate-draft');
    $router->post('product-questions/{questionId}/preview-reply', [ProductQuestionController::class, 'previewReply'])->name('product-questions.preview-reply');
    $router->post('product-questions/{questionId}/confirm-reply', [ProductQuestionController::class, 'confirmReply'])->name('product-questions.confirm-reply');
    $router->post('product-questions/{questionId}/review', [ProductQuestionController::class, 'review'])->name('product-questions.review');
    $router->post('product-questions/{questionId}/review-analysis', [ProductQuestionController::class, 'reviewAnalysis'])->name('product-questions.review-analysis');
    $router->get('product-question-policies', [ProductQuestionPolicyController::class, 'index'])->name('product-question-policies.index');
    $router->get('product-question-policies/{id}', [ProductQuestionPolicyController::class, 'show'])->name('product-question-policies.show');
    $router->post('product-question-policies/{id}/review', [ProductQuestionPolicyController::class, 'review'])->name('product-question-policies.review');
    $router->get('workflow-replay-reviews', [WorkflowReplayReviewController::class, 'index'])->name('workflow-replay-reviews.index');
    $router->get('workflow-replay-reviews/{id}', [WorkflowReplayReviewController::class, 'show'])->name('workflow-replay-reviews.show');
    $router->post('workflow-replay-reviews/{runId}/cases/{caseId}', [WorkflowReplayReviewController::class, 'review'])->name('workflow-replay-reviews.cases.review');
    $router->post('workflow-replay-reviews/{id}/gate', [WorkflowReplayReviewController::class, 'gate'])->name('workflow-replay-reviews.gate');
    $router->get('customer-cases', [CustomerCaseController::class, 'index'])->name('customer-cases.index');
    $router->get('customer-cases/{id}', [CustomerCaseController::class, 'show'])->name('customer-cases.show');
    $router->post('customer-cases/{id}/handoff', [CustomerCaseController::class, 'handoff'])->name('customer-cases.handoff');
    $router->post('customer-cases/{id}/outcome', [CustomerCaseController::class, 'outcome'])->name('customer-cases.outcome');
    $router->post('customer-cases/{caseId}/actions/{proposalId}/review', [CustomerCaseController::class, 'reviewAction'])->name('customer-cases.actions.review');
    $router->get('knowledge-articles', [KnowledgeArticleController::class, 'index'])->name('knowledge-articles.index');
    $router->get('knowledge-articles/{knowledgeArticle}', [KnowledgeArticleController::class, 'show'])->name('knowledge-articles.show');
    $router->post('knowledge-articles/{knowledgeArticle}/versions', [KnowledgeArticleController::class, 'storeDraft'])->name('knowledge-articles.versions.store');
    $router->post('knowledge-articles/{knowledgeArticle}/versions/{version}/publish', [KnowledgeArticleController::class, 'publish'])->name('knowledge-articles.versions.publish');
    $router->post('knowledge-articles/{knowledgeArticle}/versions/{version}/reject', [KnowledgeArticleController::class, 'reject'])->name('knowledge-articles.versions.reject');
});
