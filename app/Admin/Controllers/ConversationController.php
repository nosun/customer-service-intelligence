<?php

namespace App\Admin\Controllers;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class ConversationController extends AdminController
{
    protected $title = 'Tickets';

    protected function grid(): Grid
    {
        $grid = new Grid(new Conversation);

        $grid->model()
            ->with('channelAccount')
            ->withCount('events')
            ->orderByDesc('updated_at_external');

        $grid->filter(function (Grid\Filter $filter): void {
            $filter->disableIdFilter();
            $filter->where(function ($query): void {
                $keyword = trim((string) $this->input);

                $query->where(function ($query) use ($keyword): void {
                    $query->where('external_id', 'like', "%{$keyword}%")
                        ->orWhere('external_short_id', 'like', "%{$keyword}%")
                        ->orWhere('subject', 'like', "%{$keyword}%")
                        ->orWhere('requester_email', 'like', "%{$keyword}%");
                });
            }, '关键词', 'keyword');
            $filter->equal('channel_account_id', '渠道账户')
                ->select(ChannelAccount::query()->orderBy('display_name')->pluck('display_name', 'id'));
            $filter->equal('site_key', '站点');
            $filter->equal('status', '状态');
            $filter->equal('silo', '分类');
            $filter->between('updated_at_external', '外部更新时间')->datetime();
        });

        $grid->column('id', 'ID')->sortable();
        $grid->column('external_short_id', 'Ticket ID');
        $grid->column('subject', '主题')->limit(60);
        $grid->column('requester_email', '客户邮箱');
        $grid->column('site_key', '站点');
        $grid->column('channelAccount.display_name', '渠道账户');
        $grid->column('status', '状态');
        $grid->column('priority', '优先级')->sortable();
        $grid->column('silo', '分类');
        $grid->column('events_count', '事件数');
        $grid->column('updated_at_external', '外部更新时间')->sortable();

        $grid->disableCreateButton();
        $grid->actions(function (Grid\Displayers\Actions $actions): void {
            $actions->disableEdit();
            $actions->disableDelete();
        });

        return $grid;
    }

    protected function detail($id): Show
    {
        $show = new Show(Conversation::query()->with(['channelAccount', 'analysis'])->findOrFail($id));

        $show->field('id', 'ID');
        $show->field('external_id', '外部 ID');
        $show->field('external_short_id', 'Ticket ID');
        $show->field('subject', '主题');
        $show->field('status', '状态');
        $show->field('priority', '优先级');
        $show->field('silo', '分类');
        $show->field('source_type', '来源类型');
        $show->field('source_detail', '来源详情');
        $show->field('site_key', '站点');
        $show->field('channel_account_id', '渠道账户')->as(function ($id): string {
            return $this->channelAccount?->display_name ?: "#{$id}";
        });
        $show->field('requester_email', '客户邮箱');
        $show->field('requester_snapshot', '客户信息')->json();
        $show->field('assignment_snapshot', '分配信息')->json();
        $show->field('created_at_external', '外部创建时间');
        $show->field('updated_at_external', '外部更新时间');
        $show->field('last_message_at', '最后消息时间');
        $show->field('analysis.primary_category', '主要类别');
        $show->field('analysis.primary_problem_type', '问题类型');
        $show->field('analysis.urgency', '紧急度');
        $show->field('analysis.needs_review', '需要复核')->using([0 => '否', 1 => '是']);

        $show->events('消息与事件', function (Grid $events): void {
            $events->disableCreateButton();
            $events->disableActions();
            $events->disableBatchActions();
            $events->model()->orderBy('occurred_at');
            $events->column('occurred_at', '时间');
            $events->column('event_type', '类型');
            $events->column('author_type', '作者类型');
            $events->column('author_name', '作者');
            $events->column('author_email', '作者邮箱');
            $events->column('is_private', '私密')->using([0 => '否', 1 => '是']);
            $events->column('status_value', '状态变化');
            $events->column('body_text', '内容')->limit(160);
        });

        $show->panel()->tools(function (Show\Tools $tools): void {
            $tools->disableEdit();
            $tools->disableDelete();
        });

        return $show;
    }
}
