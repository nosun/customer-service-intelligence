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
    $router->resource('conversations', ConversationController::class)->only(['index', 'show']);
    $router->get('chatbot-chats', [ChatBotChatController::class, 'index'])->name('chatbot-chats.index');
    $router->get('chatbot-chats/{id}', [ChatBotChatController::class, 'show'])->name('chatbot-chats.show');
});
