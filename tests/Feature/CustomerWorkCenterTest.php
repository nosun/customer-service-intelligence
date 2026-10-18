<?php

namespace Tests\Feature;

use App\Models\CommerceSite;
use App\Models\CustomerCase;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraftRequest;
use App\Models\WorkItem;
use App\Services\Work\ActionProposalService;
use App\Services\Work\CustomerCaseManager;
use App\Services\Work\FactResult;
use App\Services\Work\ProductQuestionWorkItemManager;
use App\Services\Work\WorkContracts;
use App\Services\Work\WorkItemLeaseService;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class CustomerWorkCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_question_draft_request_attaches_to_a_generic_case_and_work_item(): void
    {
        $question = $this->question();
        $request = ProductQuestionDraftRequest::create([
            'request_id' => 'work-center-request',
            'product_question_id' => $question->id,
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => '1.2.0',
            'input_hash' => str_repeat('a', 64),
            'input_snapshot' => ['question' => 'Does it have pockets?'],
            'status' => 'queued',
        ]);

        $item = app(ProductQuestionWorkItemManager::class)->attach($request);

        $this->assertSame('product_question_draft', $item->work_type);
        $this->assertSame('queued', $item->status);
        $this->assertSame($item->id, $request->fresh()->work_item_id);
        $this->assertDatabaseHas('customer_cases', [
            'case_key' => 'product-question:'.$question->commerce_site_id.':'.$question->external_id,
            'case_type' => 'product_question',
            'status' => 'open',
        ]);

        $request->update([
            'status' => 'running',
            'n8n_execution_id' => 'execution-42',
            'attempt_count' => 1,
            'started_at' => now(),
        ]);
        $this->assertDatabaseHas('work_items', [
            'id' => $item->id,
            'status' => 'running',
            'execution_id' => 'execution-42',
            'lease_owner' => 'execution-42',
        ]);
    }

    public function test_work_item_lease_enforces_owner_attempt_and_completion_contracts(): void
    {
        $case = $this->customerCase();
        $item = WorkItem::create([
            'work_item_key' => '019a-work-item-lease',
            'customer_case_id' => $case->id,
            'work_type' => 'logistics_fact_lookup',
            'status' => 'queued',
        ]);
        $leases = app(WorkItemLeaseService::class);

        $leased = $leases->lease($item, 'worker-a', 120);
        $this->assertSame('running', $leased->status);
        $this->assertSame(1, $leased->attempt_count);

        try {
            $leases->complete($leased, 'worker-b', ['status' => 'wrong owner']);
            $this->fail('A non-owner completed the Work Item.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Only the current lease owner can finish a work item.', $exception->getMessage());
        }

        $completed = $leases->complete($leased, 'worker-a', ['status' => 'delivered']);
        $this->assertSame('completed', $completed->status);
        $this->assertSame('delivered', $completed->result['status']);
        $this->assertNull($completed->lease_owner);
    }

    public function test_action_proposal_requires_human_approval_before_execution_result(): void
    {
        $case = $this->customerCase();
        $actions = app(ActionProposalService::class);
        $proposal = $actions->propose($case, 'pause_shipment', ['order_no' => 'ORDER-1'], [
            'reason' => 'Customer requested cancellation.',
        ]);
        $duplicate = $actions->propose($case, 'pause_shipment', ['order_no' => 'ORDER-1']);

        $this->assertSame($proposal->id, $duplicate->id);
        $this->assertSame('proposed', $proposal->status);
        $this->expectException(RuntimeException::class);
        $actions->recordResult($proposal, true, ['remote_status' => 'paused']);
    }

    public function test_approved_action_records_separate_execution_result_and_case_outcome(): void
    {
        $case = $this->customerCase();
        $actions = app(ActionProposalService::class);
        $proposal = $actions->propose($case, 'pause_shipment', ['order_no' => 'ORDER-2']);
        $approved = $actions->approve($proposal, 101, 'Approved by customer service lead.');
        $executing = $actions->markExecuting($approved);
        $succeeded = $actions->recordResult($executing, true, ['remote_status' => 'paused']);

        $this->assertSame('succeeded', $succeeded->status);
        $this->assertSame('paused', $succeeded->execution_result['remote_status']);

        $resolved = app(CustomerCaseManager::class)->recordOutcome($case, 'request_completed', [
            'summary' => 'Shipment pause verified.',
        ]);
        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('request_completed', $resolved->outcome_type);
    }

    public function test_versioned_contracts_keep_sensitive_facts_out_of_ai_shareable_data(): void
    {
        $public = new FactResult('product.color', 'Ivory', 'tradeManager', Carbon::now(), 'public');
        $private = new FactResult('order.email', 'customer@example.test', 'console-new', Carbon::now(), 'private');
        $contracts = app(WorkContracts::class);
        $result = $contracts->workerResult([$public->toArray(), $private->toArray()]);

        $this->assertTrue($result['facts'][0]['shareable_with_ai']);
        $this->assertFalse($result['facts'][1]['shareable_with_ai']);
        $this->assertSame(WorkContracts::WORKER_RESULT_VERSION, $result['schema_version']);
    }

    public function test_administrator_can_handoff_case_and_approve_a_proposal_without_executing_it(): void
    {
        $case = $this->customerCase();
        $proposal = app(ActionProposalService::class)->propose($case, 'pause_shipment', ['order_no' => 'ORDER-3']);
        $administrator = $this->administrator();

        $this->actingAs($administrator, 'admin')
            ->get("/admin/customer-cases/{$case->id}")
            ->assertOk()
            ->assertSee('Shipment has not moved')
            ->assertSee('批准但不执行');
        $this->post("/admin/customer-cases/{$case->id}/handoff", [
            'queue' => 'senior-logistics',
            'owner_id' => 88,
            'reason' => 'Carrier investigation needs a senior owner.',
        ])->assertRedirect();
        $this->assertDatabaseHas('customer_cases', [
            'id' => $case->id,
            'queue' => 'senior-logistics',
            'owner_id' => 88,
        ]);
        $this->assertDatabaseHas('customer_case_handoffs', [
            'customer_case_id' => $case->id,
            'created_by' => $administrator->id,
        ]);

        $this->post("/admin/customer-cases/{$case->id}/actions/{$proposal->id}/review", [
            'decision' => 'approve',
            'review_notes' => 'Approved for a separate executor.',
        ])->assertRedirect();
        $this->assertDatabaseHas('action_proposals', [
            'id' => $proposal->id,
            'status' => 'approved',
            'reviewed_by' => $administrator->id,
            'execution_result' => null,
        ]);
    }

    public function test_customer_case_menu_is_created(): void
    {
        $this->assertDatabaseHas(config('admin.database.menu_table'), [
            'title' => '客服 Case',
            'uri' => 'customer-cases',
        ]);
    }

    private function question(): ProductQuestion
    {
        $site = CommerceSite::create([
            'name' => 'Work Center',
            'site_key' => 'work-center',
            'admin_api_url' => 'https://work-center.test',
            'api_token' => 'secret',
            'enabled' => true,
        ]);

        return ProductQuestion::create([
            'commerce_site_id' => $site->id,
            'external_id' => 1001,
            'sn' => 'WC-1',
            'question' => 'Does it have pockets?',
            'reply_status' => 'unanswered',
            'raw_payload' => [],
            'remote_present' => true,
            'synced_at' => now(),
        ]);
    }

    private function customerCase(): CustomerCase
    {
        return CustomerCase::create([
            'case_key' => 'case-'.fake()->uuid(),
            'case_type' => 'logistics_exception',
            'title' => 'Shipment has not moved',
            'status' => 'open',
            'risk_level' => 'normal',
            'priority' => 'normal',
            'queue' => 'logistics',
        ]);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'work-center-admin',
            'password' => Hash::make('secret'),
            'name' => 'Work Center Admin',
        ]);
        $administrator->roles()->attach(Role::where('slug', 'administrator')->firstOrFail());

        return $administrator;
    }
}
