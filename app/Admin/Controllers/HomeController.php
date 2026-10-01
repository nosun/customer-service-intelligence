<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
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
            ->row(new InfoBox('Tickets', 'fa-comments', 'aqua', admin_url('conversations'), Conversation::count()));
    }
}
