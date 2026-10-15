<?php

use App\Admin\Controllers\ChatBotChatController;
use App\Admin\Controllers\ConversationController;
use App\Admin\Controllers\HomeController;
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
});
