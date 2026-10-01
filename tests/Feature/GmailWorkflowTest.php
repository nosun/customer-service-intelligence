<?php

namespace Tests\Feature;

use App\Models\CandidateReplyPair;
use App\Models\GmailThread;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\MessageAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_excludes_spam_messages(): void
    {
        $mailbox = Mailbox::create(['email' => 'support@example.com']);
        $thread = GmailThread::create([
            'mailbox_id' => $mailbox->id,
            'gmail_thread_id' => 'spam-thread',
        ]);
        $message = Message::create([
            'mailbox_id' => $mailbox->id,
            'thread_id' => $thread->id,
            'gmail_message_id' => 'spam-message',
            'direction' => 'inbound',
            'subject' => 'You are a lottery winner',
            'body_text' => 'Claim your prize now by replying with your details.',
        ]);

        $this->artisan('gmail:analyze')->assertSuccessful();

        $this->assertDatabaseHas('message_analyses', [
            'message_id' => $message->id,
            'category' => 'noise',
            'is_noise' => true,
            'noise_type' => 'spam',
        ]);
    }

    public function test_fixture_sync_analysis_and_export_are_idempotent(): void
    {
        $fixture = base_path('tests/fixtures/gmail-threads.json');
        $arguments = [
            '--mailbox' => 'support@dressafford-service.com',
            '--fixture' => $fixture,
        ];

        $this->artisan('gmail:sync', $arguments)
            ->expectsOutputToContain('找到 2 个测试 Thread')
            ->expectsOutputToContain('同步完成')
            ->assertSuccessful();
        $this->artisan('gmail:sync', $arguments)->assertSuccessful();
        $this->artisan('gmail:sync', [...$arguments, '--resume' => true])
            ->expectsOutputToContain('断点继续：已跳过 2 个已保存 Thread')
            ->assertSuccessful();

        $this->assertDatabaseCount('gmail_threads', 2);
        $this->assertDatabaseCount('messages', 4);
        $this->assertSame(2, GmailThread::count());
        $this->assertSame(4, Message::count());
        $this->assertSame(
            'Hi, is this dress available in navy and how long does production take?',
            Message::where('gmail_message_id', 'message-presales-in')->value('body_text'),
        );

        $this->artisan('gmail:analyze')->assertSuccessful();
        $this->artisan('gmail:analyze')->assertSuccessful();

        $this->assertSame(2, MessageAnalysis::count());
        $this->assertSame(2, CandidateReplyPair::count());
        $this->assertSame(
            ['after_sales', 'presales'],
            MessageAnalysis::query()
                ->join('messages', 'messages.id', '=', 'message_analyses.message_id')
                ->orderBy('messages.gmail_message_id')
                ->pluck('message_analyses.category')
                ->all(),
        );

        $output = storage_path('framework/testing/candidate-reply-pairs.csv');
        $this->artisan('knowledge:export', ['--output' => $output])->assertSuccessful();

        $rows = array_map('str_getcsv', file($output, FILE_IGNORE_NEW_LINES));
        $this->assertCount(3, $rows);
        $this->assertSame([
            'pair_id',
            'category',
            'customer_subject',
            'customer_excerpt',
            'agent_reply_excerpt',
            'matched_rules',
            'review_status',
        ], $rows[0]);

        $reviewOutput = storage_path('framework/testing/customer-issue-review.csv');
        $summaryOutput = storage_path('framework/testing/customer-issue-summary.json');
        $this->artisan('gmail:review-export', [
            '--output' => $reviewOutput,
            '--summary' => $summaryOutput,
        ])->assertSuccessful();

        $reviewRows = array_map('str_getcsv', file($reviewOutput, FILE_IGNORE_NEW_LINES));
        $this->assertCount(3, $reviewRows);
        $this->assertSame(2, json_decode(file_get_contents($summaryOutput), true)['customer_messages']);
    }
}
