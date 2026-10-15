<?php

use App\Admin\Controllers\ChatBotChatController;
use App\Admin\Controllers\CommerceSiteController;
use App\Admin\Controllers\ConversationController;
use App\Admin\Controllers\HomeController;
use App\Admin\Controllers\ProductQuestionController;
use App\Admin\Controllers\ProductQuestionFactoryCapabilityController;
use App\Admin\Controllers\ProductQuestionPolicyController;
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
    $router->post('conversations/{id}/cases/{case}/review', [ConversationController::class, 'reviewCase'])->name('conversations.cases.review');
    $router->post('conversations/{id}/nodes/{node}/review', [ConversationController::class, 'reviewNode'])->name('conversations.nodes.review');
    $router->get('chatbot-chats', [ChatBotChatController::class, 'index'])->name('chatbot-chats.index');
    $router->get('chatbot-chats/{id}', [ChatBotChatController::class, 'show'])->name('chatbot-chats.show');
    $router->get('commerce-sites', [CommerceSiteController::class, 'index'])->name('commerce-sites.index');
    $router->get('commerce-sites/create', [CommerceSiteController::class, 'create'])->name('commerce-sites.create');
    $router->post('commerce-sites', [CommerceSiteController::class, 'store'])->name('commerce-sites.store');
    $router->get('commerce-sites/{commerceSite}/edit', [CommerceSiteController::class, 'edit'])->name('commerce-sites.edit');
    $router->put('commerce-sites/{commerceSite}', [CommerceSiteController::class, 'update'])->name('commerce-sites.update');
    $router->get('product-questions', [ProductQuestionController::class, 'index'])->name('product-questions.index');
    $router->get('product-questions/{questionId}', [ProductQuestionController::class, 'show'])->name('product-questions.show');
    $router->post('product-questions/{questionId}/preview-reply', [ProductQuestionController::class, 'previewReply'])->name('product-questions.preview-reply');
    $router->post('product-questions/{questionId}/confirm-reply', [ProductQuestionController::class, 'confirmReply'])->name('product-questions.confirm-reply');
    $router->post('product-questions/{questionId}/review', [ProductQuestionController::class, 'review'])->name('product-questions.review');
    $router->post('product-questions/{questionId}/review-analysis', [ProductQuestionController::class, 'reviewAnalysis'])->name('product-questions.review-analysis');
    $router->get('product-question-factory-capabilities', [ProductQuestionFactoryCapabilityController::class, 'index'])->name('product-question-factory-capabilities.index');
    $router->get('product-question-factory-capabilities/{id}', [ProductQuestionFactoryCapabilityController::class, 'show'])->name('product-question-factory-capabilities.show');
    $router->put('product-question-factory-capabilities/{id}', [ProductQuestionFactoryCapabilityController::class, 'update'])->name('product-question-factory-capabilities.update');
    $router->get('product-question-policies', [ProductQuestionPolicyController::class, 'index'])->name('product-question-policies.index');
    $router->get('product-question-policies/{id}', [ProductQuestionPolicyController::class, 'show'])->name('product-question-policies.show');
    $router->post('product-question-policies/{id}/review', [ProductQuestionPolicyController::class, 'review'])->name('product-question-policies.review');
});
