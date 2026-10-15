<?php

namespace Tests\Feature;

use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseStep;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationEvent;
use App\Models\DecisionCase;
use App\Models\EmailTemplate;
use App\Models\KnowledgeArticle;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BenchmarkAnnotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_ten_stratified_cases_without_reusing_conversations(): void
    {
        $account = $this->account();
        foreach (['presales', 'production', 'logistics', 'after_sales', 'unknown'] as $bucket) {
            for ($index = 1; $index <= 4; $index++) {
                $this->analyzedTicket($account, $bucket, $index);
            }
        }

        $this->artisan('benchmark:sample', [
            '--count' => 10,
            '--dataset' => 'v1-pilot',
            '--split' => 'pilot',
            '--seed' => 'test-seed',
        ])->assertSuccessful();

        $this->assertSame(10, BenchmarkCase::where('dataset_version', 'v1-pilot')->count());
        foreach (['presales', 'production', 'logistics', 'after_sales', 'unknown'] as $bucket) {
            $this->assertSame(2, BenchmarkCase::where('dataset_version', 'v1-pilot')->where('sampling_bucket', $bucket)->count());
        }

        $this->artisan('benchmark:sample', [
            '--count' => 10,
            '--dataset' => 'v1-pilot',
            '--split' => 'pilot',
            '--seed' => 'test-seed',
        ])->assertSuccessful();

        $this->assertSame(20, BenchmarkCase::where('dataset_version', 'v1-pilot')->count());
        $this->assertSame(
            20,
            BenchmarkCase::where('dataset_version', 'v1-pilot')->distinct()->count('conversation_id'),
        );
    }

    public function test_administrator_can_blind_review_and_save_annotation(): void
    {
        Http::fake();
        $administrator = $this->administrator();
        $ticket = $this->analyzedTicket($this->account(), 'production', 1);
        $event = $ticket->events()->firstOrFail();
        DecisionCase::create([
            'conversation_id' => $ticket->id,
            'case_key' => 'helpdesk:'.$ticket->external_id,
            'case_summary' => 'DECISION SECRET SHOULD STAY HIDDEN',
            'review_status' => 'candidate',
            'extractor_version' => 'test',
            'input_hash' => hash('sha256', 'hidden-case'),
            'extracted_at' => now(),
        ]);
        $ticket->analysis()->update(['primary_problem_type' => 'RULE SECRET SHOULD STAY HIDDEN']);
        $case = $this->benchmarkCase($ticket);
        $template = EmailTemplate::create([
            'name' => 'Order status update',
            'template_type' => 'template',
            'source' => 'email.csv',
            'body' => 'Template body',
            'active' => true,
            'raw_row' => [],
        ]);

        $this->actingAs($administrator, 'admin');
        $this->get('/admin/benchmark-cases')
            ->assertOk()
            ->assertSee('基准集标注')
            ->assertSee($ticket->subject);

        $this->get("/admin/benchmark-cases/{$case->id}")
            ->assertOk()
            ->assertSee('第一步：切分决策阶段')
            ->assertSee('Customer question production 1')
            ->assertDontSee('RULE SECRET SHOULD STAY HIDDEN')
            ->assertDontSee('DECISION SECRET SHOULD STAY HIDDEN');

        $this->from("/admin/benchmark-cases/{$case->id}")
            ->post("/admin/benchmark-cases/{$case->id}/annotations", [
                'site_key' => 'dressafford.com',
                'intents' => ['production_order_status', 'production_rush_request'],
                'primary_intent' => 'production_order_status',
                'risk_level' => 'high',
                'required_facts' => ['order', 'production'],
                'missing_information' => "Current production stage\nConfirmed event date",
                'decision' => 'ask_clarification',
                'email_template_id' => $template->id,
                'ideal_reply' => 'Please confirm your event date while we check production.',
                'automation_level' => 'draft_review',
                'evidence_event_ids' => [$event->id],
                'notes' => 'Requires production lookup.',
            ])->assertRedirect("/admin/benchmark-cases/{$case->id}");

        $this->assertDatabaseHas('benchmark_annotations', [
            'benchmark_case_id' => $case->id,
            'annotator_id' => $administrator->id,
            'primary_intent' => 'production_order_status',
            'decision' => 'ask_clarification',
            'automation_level' => 'draft_review',
        ]);
        $this->assertSame('annotated', $case->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_administrator_can_split_a_ticket_and_blind_annotate_each_stage(): void
    {
        $administrator = $this->administrator();
        $ticket = $this->analyzedTicket($this->account(), 'presales', 1);
        $firstCustomer = $ticket->events()->firstOrFail();
        $firstCustomer->update(['body_text' => 'Can you make a maternity dress larger than the available sizes?']);
        $firstReply = $this->event($ticket, 'stage-one-reply', 'agent', 'Alex', 'Select the largest size and leave the actual measurements in the comment box.');
        $secondCustomer = $this->event($ticket, 'stage-two-customer', 'client', 'Customer', 'I placed order DAD-1001 but there was no comment box. Can you update my measurements?');
        $secondReply = $this->event($ticket, 'stage-two-reply', 'agent', 'Alex', 'Please send the correct measurements and I can update them.');
        $case = $this->benchmarkCase($ticket);

        $this->actingAs($administrator, 'admin')
            ->post("/admin/benchmark-cases/{$case->id}/steps", [
                'title' => '下单前询问超规格孕妇装定制',
                'trigger_event_id' => $firstCustomer->id,
                'context_start_event_id' => $firstCustomer->id,
                'observed_reply_event_ids' => [$firstReply->id],
                'requires_response' => '1',
                'stage_end_reason' => '客户完成下单，业务状态发生变化',
            ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post("/admin/benchmark-cases/{$case->id}/steps", [
            'title' => '下单后补充并更新实际尺寸',
            'trigger_event_id' => $secondCustomer->id,
            'context_start_event_id' => $firstCustomer->id,
            'observed_reply_event_ids' => [$secondReply->id],
            'requires_response' => '1',
            'stage_end_reason' => '等待客户提供正确尺寸',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('benchmark_case_steps', 2);
        $firstStep = $case->steps()->orderBy('sequence')->firstOrFail();
        $secondStep = $case->steps()->orderByDesc('sequence')->firstOrFail();

        $this->get("/admin/benchmark-cases/{$case->id}/steps/{$firstStep->id}")
            ->assertOk()
            ->assertSee('Can you make a maternity dress larger')
            ->assertDontSee('Select the largest size')
            ->assertDontSee('I placed order DAD-1001');

        $this->post("/admin/benchmark-cases/{$case->id}/steps/{$firstStep->id}/annotations", [
            'site_key' => 'dressafford.com',
            'lifecycle_stage' => 'presales',
            'customer_goal' => '确认页面可选范围之外的孕妇装尺寸是否可以定制。',
            'intents' => ['presales_sizing'],
            'primary_intent' => 'presales_sizing',
            'risk_level' => 'normal',
            'required_facts' => ['product', 'policy'],
            'missing_information' => '当前站点是否仍有订单备注框',
            'decision' => 'answer',
            'response_strategy' => 'answer_policy',
            'required_actions' => ['lookup_product'],
            'knowledge_gaps' => '超规格尺寸的生产上限和费用规则',
            'prohibited_claims' => '未确认页面功能前不得要求客户使用备注框',
            'new_knowledge_key' => 'custom-size-outside-selector',
            'new_knowledge_title' => '页面可选范围之外的定制尺寸处理',
            'new_knowledge_type' => 'policy',
            'new_knowledge_applicability' => 'DAD 孕妇装或定制礼服的尺寸超过页面可选范围',
            'ideal_reply' => 'We can check whether this dress supports your measurements before you order.',
            'automation_level' => 'draft_review',
            'evidence_event_ids' => [$firstCustomer->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('benchmark_annotations', [
            'benchmark_case_step_id' => $firstStep->id,
            'lifecycle_stage' => 'presales',
            'response_strategy' => 'answer_policy',
        ]);
        $this->assertDatabaseHas('knowledge_articles', [
            'key' => 'custom-size-outside-selector',
            'status' => 'observed',
        ]);

        $this->get("/admin/benchmark-cases/{$case->id}/steps/{$firstStep->id}")
            ->assertOk()
            ->assertSee('提交盲标后的历史回复对照')
            ->assertSee('Select the largest size');

        $observedKnowledge = KnowledgeArticle::where('key', 'custom-size-outside-selector')->firstOrFail();
        $this->post("/admin/benchmark-cases/{$case->id}/steps/{$secondStep->id}/annotations", [
            'site_key' => 'dressafford.com',
            'lifecycle_stage' => 'ordered',
            'customer_goal' => '把正确尺寸关联到已经创建的订单。',
            'intents' => ['presales_sizing', 'production_order_change'],
            'primary_intent' => 'production_order_change',
            'risk_level' => 'high',
            'required_facts' => ['customer_identity', 'order', 'production', 'policy'],
            'missing_information' => "完整正确尺寸\n订单当前生产状态",
            'decision' => 'ask_clarification',
            'response_strategy' => 'verify_before_action',
            'required_actions' => ['lookup_order', 'lookup_production', 'collect_measurements', 'update_measurements', 'confirm_action_result'],
            'knowledge_article_ids' => [$observedKnowledge->id],
            'knowledge_gaps' => '进入生产后的尺寸修改限制',
            'prohibited_claims' => '接口更新成功前不得声称尺寸已经修改',
            'ideal_reply' => 'Please send the complete correct measurements. We will verify the order and production status before updating it.',
            'automation_level' => 'draft_review',
            'evidence_event_ids' => [$firstCustomer->id, $secondCustomer->id],
            'mark_as_gold' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $secondStep->refresh();
        $this->assertSame('adjudicated', $secondStep->status);
        $this->assertSame('ordered', $secondStep->gold_lifecycle_stage);
        $this->assertSame('production_order_change', $secondStep->gold_primary_intent);
        $this->assertContains('update_measurements', $secondStep->gold_required_actions);
        $this->assertSame([$observedKnowledge->id], $secondStep->gold_knowledge_article_ids);
    }

    public function test_auto_send_rejects_unverified_knowledge_and_write_actions(): void
    {
        $administrator = $this->administrator();
        $ticket = $this->analyzedTicket($this->account(), 'presales', 1);
        $event = $ticket->events()->firstOrFail();
        $case = $this->benchmarkCase($ticket);
        $step = BenchmarkCaseStep::create([
            'benchmark_case_id' => $case->id,
            'sequence' => 1,
            'title' => 'Custom size question',
            'trigger_event_id' => $event->id,
            'context_start_event_id' => $event->id,
            'context_end_event_id' => $event->id,
            'observed_reply_event_ids' => [],
        ]);
        $knowledge = KnowledgeArticle::create([
            'key' => 'unverified-custom-size',
            'title' => 'Unverified custom size policy',
            'status' => 'observed',
        ]);

        $this->actingAs($administrator, 'admin')
            ->from("/admin/benchmark-cases/{$case->id}/steps/{$step->id}")
            ->post("/admin/benchmark-cases/{$case->id}/steps/{$step->id}/annotations", [
                'site_key' => 'dressafford.com',
                'lifecycle_stage' => 'ordered',
                'customer_goal' => 'Update measurements.',
                'intents' => ['production_order_change'],
                'primary_intent' => 'production_order_change',
                'risk_level' => 'normal',
                'decision' => 'propose_action',
                'response_strategy' => 'execute_and_confirm',
                'required_actions' => ['update_measurements'],
                'knowledge_article_ids' => [$knowledge->id],
                'ideal_reply' => 'We will update it.',
                'automation_level' => 'auto_send',
                'evidence_event_ids' => [$event->id],
            ])
            ->assertRedirect("/admin/benchmark-cases/{$case->id}/steps/{$step->id}")
            ->assertSessionHasErrors('automation_level');

        $this->assertDatabaseCount('benchmark_annotations', 0);
    }

    public function test_gold_adjudication_copies_the_complete_annotation(): void
    {
        $administrator = $this->administrator();
        $ticket = $this->analyzedTicket($this->account(), 'presales', 1);
        $event = $ticket->events()->firstOrFail();
        $case = $this->benchmarkCase($ticket);

        $this->actingAs($administrator, 'admin')
            ->post("/admin/benchmark-cases/{$case->id}/annotations", [
                'site_key' => 'ellenbridals.com',
                'intents' => ['presales_sizing'],
                'primary_intent' => 'presales_sizing',
                'risk_level' => 'normal',
                'required_facts' => ['product'],
                'missing_information' => 'Customer measurements',
                'decision' => 'ask_clarification',
                'ideal_reply' => 'Please share your measurements.',
                'automation_level' => 'auto_send',
                'evidence_event_ids' => [$event->id],
                'notes' => 'Safe clarification.',
                'mark_as_gold' => '1',
            ])->assertRedirect();

        $gold = $case->fresh();
        $this->assertSame('adjudicated', $gold->status);
        $this->assertSame('ellenbridals.com', $gold->gold_site_key);
        $this->assertSame(['presales_sizing'], $gold->gold_intents);
        $this->assertSame(['product'], $gold->gold_required_facts);
        $this->assertSame(['Customer measurements'], $gold->gold_missing_information);
        $this->assertSame([$event->id], $gold->gold_evidence_event_ids);
        $this->assertSame($administrator->id, $gold->adjudicated_by);
        $this->assertNotNull($gold->adjudicated_at);

        $this->actingAs($administrator, 'admin')
            ->post("/admin/benchmark-cases/{$case->id}/annotations", [
                'site_key' => 'dressafford.com',
                'intents' => ['presales_product_information'],
                'primary_intent' => 'presales_product_information',
                'risk_level' => 'normal',
                'decision' => 'answer',
                'automation_level' => 'draft_review',
                'evidence_event_ids' => [$event->id],
            ])->assertRedirect();

        $gold = $case->fresh();
        $this->assertSame('adjudicated', $gold->status);
        $this->assertSame('ellenbridals.com', $gold->gold_site_key);
        $this->assertSame('presales_sizing', $gold->gold_primary_intent);
    }

    public function test_annotation_rejects_inconsistent_intent_and_foreign_evidence(): void
    {
        $administrator = $this->administrator();
        $account = $this->account();
        $ticket = $this->analyzedTicket($account, 'logistics', 1);
        $otherTicket = $this->analyzedTicket($account, 'logistics', 2);
        $case = $this->benchmarkCase($ticket);
        $valid = $this->validAnnotation($otherTicket->events()->firstOrFail()->id);

        $this->actingAs($administrator, 'admin')
            ->from("/admin/benchmark-cases/{$case->id}")
            ->post("/admin/benchmark-cases/{$case->id}/annotations", $valid)
            ->assertRedirect("/admin/benchmark-cases/{$case->id}")
            ->assertSessionHasErrors('evidence_event_ids');
        $this->assertDatabaseCount('benchmark_annotations', 0);

        $valid['evidence_event_ids'] = [$ticket->events()->firstOrFail()->id];
        $valid['primary_intent'] = 'production_order_status';
        $this->post("/admin/benchmark-cases/{$case->id}/annotations", $valid)
            ->assertSessionHasErrors('primary_intent');
        $this->assertDatabaseCount('benchmark_annotations', 0);
    }

    public function test_guest_is_redirected_and_menu_exists(): void
    {
        $this->get('/admin/benchmark-cases')->assertRedirect('/admin/auth/login');
        $this->assertDatabaseHas(config('admin.database.menu_table'), [
            'title' => '基准集标注',
            'uri' => 'benchmark-cases',
        ]);
    }

    private function validAnnotation(int $evidenceId): array
    {
        return [
            'site_key' => 'dressafford.com',
            'intents' => ['logistics_tracking_delivery'],
            'primary_intent' => 'logistics_tracking_delivery',
            'risk_level' => 'normal',
            'required_facts' => ['shipment'],
            'decision' => 'answer',
            'automation_level' => 'draft_review',
            'evidence_event_ids' => [$evidenceId],
        ];
    }

    private function benchmarkCase(Conversation $ticket): BenchmarkCase
    {
        return BenchmarkCase::create([
            'conversation_id' => $ticket->id,
            'dataset_version' => 'v1-pilot',
            'split' => 'pilot',
            'sampling_bucket' => $ticket->analysis->primary_category,
            'source_hash' => hash('sha256', 'benchmark-'.$ticket->id),
            'status' => 'pending',
            'selected_at' => now(),
        ]);
    }

    private function analyzedTicket(ChannelAccount $account, string $bucket, int $index): Conversation
    {
        $externalId = "{$bucket}-{$index}";
        $ticket = Conversation::create([
            'channel_account_id' => $account->id,
            'site_key' => $index % 3 === 0 ? null : ($index % 2 === 0 ? 'ellenbridals.com' : 'dressafford.com'),
            'external_id' => $externalId,
            'external_short_id' => strtoupper(substr($bucket, 0, 3)).'-'.$index,
            'subject' => "Benchmark {$bucket} {$index}",
            'status' => 'open',
            'silo' => $index % 2 === 0 ? 'archive' : 'tickets',
            'requester_snapshot' => [],
            'assignment_snapshot' => [],
            'updated_at_external' => now(),
            'raw_payload' => [],
        ]);
        ConversationEvent::create([
            'conversation_id' => $ticket->id,
            'external_id' => 'event-'.$externalId,
            'event_type' => 'message',
            'occurred_at' => now(),
            'author_type' => 'client',
            'author_name' => 'Customer',
            'body_text' => "Customer question {$bucket} {$index}",
            'content_hash' => hash('sha256', 'event-'.$externalId),
            'raw_payload' => [],
        ]);
        ConversationAnalysis::create([
            'conversation_id' => $ticket->id,
            'primary_category' => $bucket,
            'primary_problem_type' => $bucket.'_question',
            'reply_candidate_count' => 1,
            'analyzer_version' => 'test',
            'analyzed_at' => now(),
        ]);

        return $ticket;
    }

    private function event(Conversation $ticket, string $externalId, string $authorType, ?string $authorName, string $body): ConversationEvent
    {
        return ConversationEvent::create([
            'conversation_id' => $ticket->id,
            'external_id' => $externalId,
            'event_type' => 'message',
            'occurred_at' => now()->addSecond($ticket->events()->count()),
            'author_type' => $authorType,
            'author_name' => $authorName,
            'body_text' => $body,
            'content_hash' => hash('sha256', $externalId),
            'raw_payload' => [],
        ]);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'benchmark-admin',
            'password' => Hash::make('secret'),
            'name' => 'Benchmark Admin',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }

    private function account(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => 'benchmark-account-'.fake()->uuid(),
            'display_name' => 'Benchmark HelpDesk',
        ]);
    }
}
