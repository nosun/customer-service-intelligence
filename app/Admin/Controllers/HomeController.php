<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BenchmarkCase;
use App\Models\ChatBotChat;
use App\Models\Conversation;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\InfoBox;

class HomeController extends Controller
{
    public function index(Content $content): Content
    {
        return $content
            ->title('客服数据后台')
            ->description('Customer Service Intelligence')
            ->row(new InfoBox('Tickets', 'fa-comments', 'aqua', admin_url('conversations'), Conversation::count()))
            ->row(new InfoBox('ChatBot Chats', 'fa-commenting', 'green', admin_url('chatbot-chats'), ChatBotChat::count()))
            ->row(new InfoBox('基准集标注', 'fa-check-square-o', 'yellow', admin_url('benchmark-cases'), BenchmarkCase::count()));
    }
}
