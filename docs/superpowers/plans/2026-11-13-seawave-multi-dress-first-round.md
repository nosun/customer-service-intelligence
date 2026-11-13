# Seawave Multi-dress First Round Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking. 用户已批准源码开发；不自动启动子agent，不执行生产动作。

**Goal:** 为Seawave多件确认建立可审阅、可恢复、不会重复首信的完整首轮流程。

**Architecture:** Laravel保存订单级Case、首轮台账及副作用意图，掌握事实、账号、策略和授权。n8n只做受限分析及独立复核，Gmail负责首信，Console负责内部备注及受限订单推进。48小时等待存数据库，不占用WorkItem租约；收到回复交HelpDesk人工处理。

**Tech Stack:** PHP ^8.4、Laravel ^13.17、PHPUnit ^12.5.12、现有Laravel Admin／queue／Scheduler、n8n work中心、GwsClient。

**Spec:** [开发设计](../specs/2026-11-13-seawave-multi-dress-first-round-design.md)。执行者同时阅读[业务规格](../../architecture/seawave-multi-dress-first-email.md)及[本例模拟](../../research/2026-11-13-ebd1295-first-round-replay.md)。

## 2026-11-13 执行状态

已按本计划实现源码并完成隔离 PHP 回归，未执行生产迁移或激活。下方原步骤保留为详细验收清单，不能将代码完成等同于真实外部验收完成。以本节和[交付记录](../../research/2026-11-13-multi-dress-implementation.md)为当前状态。

- [x] Task 1：台账、唯一键、边界策略、Case/WorkItem，隔离测试通过。
- [x] Task 2：Console 读取、完整分页、快照/哈希和扫描命令；原始命中缺失时明确未证明。
- [x] Task 3：Gmail 读取及 HelpDesk 保守适配器；来源缺失不能判定无回复。
- [x] Task 4：受限分析/固定模板/审核及一次修订的服务与 inactive n8n 候选、内部接口。
- [x] Task 5：Gmail 意图台账、单次发送、未知对账、真实 SENT 核对的 fake 验证。
- [x] Task 6：备注 Preview/有权限的人员 Confirm/版本/回查基线，HTTP fake 验证。
- [x] Task 7：48小时检查、回复优先、原始两件资格与单个 FollowUp；缺上游契约不执行推进。
- [x] Task 8：后台、调度开关、健康、隔离回放及运行手册。
- [ ] 外部验收：HelpDesk 最新完整性和占用、未生产证明、两品牌真实收件/回信、Console 实际 Preview/Confirm。
- [ ] 真实 n8n fixture 回放及专用发布门禁；当前 evaluating 可注册，active 拒绝。
- [ ] 原始命中及 Console 原子超时推进契约、执行器和真实状态回查；timeout 固定关闭。
- [ ] 主实例迁移、shadow 运行和按品牌分阶段启用；本次未执行。

验证结果：539项PHP测试/3901断言通过；本模块37项/159断言；隔离回放38项。真实外部动作均为0。

## Global Constraints

- 运行环境为Ubuntu开发机，业务来源是远程生产系统；本地Console源码不是生产状态。
- 业务只处理 `console_key=seawave`，`site_key=dressafford|ellenbridals`；MyDorris只读。
- 唯一入口是Console已存在的 `source=multi_dress` 沟通单；不重做拦截、不创建沟通单。
- 首信告知当前暂停、要求48小时内回复；不向客户披露无回复后的内部生产规则。
- 原始两件命中且当前仍为同一两件、48小时无回复、其它条件全部满足，才可进入超时推进路径。三件及以上继续暂停；三件减成两件不继承两件资格。
- 任何相关客户回复，包括不完整答案，均停止无回复路径，交HelpDesk人工处理。
- ETA、comment、退款、取消、改款、后续客户邮件、HelpDesk真实发送均不在本期执行范围。
- 邮件及Console操作是独立副作用；写备注失败不能引发重发邮件。
- 交互式trade写入必须展示实际Preview并取得用户授权后Confirm；AI审核、operation_id、设计批准都不能代替该授权。
- 默认shadow；首信、备注、超时动作分别受开关及授权控制。本计划本身不启用任何开关。
- 应用命令使用 `runuser -u csi --`；测试使用项目phpunit.xml中的隔离SQLite及fake外部服务。
- 仓库已有大量未提交变更；只暂存当前任务的文件，不reset／stash／覆盖其它成果。每任务完成审阅后再独立提交。

## 交付顺序与依赖

| 交付 | 对应任务 | 可交付结果 | 启用条件 |
| --- | --- | --- | --- |
| A：候选和草稿 | 1–4 | 订单事实、历史去重、是否发信理由、可审阅首信 | 只读接口、来源完整性和真实n8n回放通过 |
| B：首信闭环 | 5–6及8的相关页面 | 单次发送／对账、备注、人工接管 | 两品牌受控收件及HelpDesk回信验收、按站点批准发送 |
| C：等待闭环 | 7–8 | 48小时分流、待办、受限推进及可回查状态 | 命中证明、HelpDesk实时读取、Console推进及执行授权契约通过 |

工作量估计：A约3–4个开发日，B约2–3日，C约2–3日；不含外部接口开发和观察首轮48小时的自然等待。按验收结果推进，不以估计日期代替门禁。

## 文件及接口地图

新增业务集中于 `app/Services/CustomerContacts/`；不改Ticket Draft的领域语义。

| 位置 | 职责 |
| --- | --- |
| `app/Models/CustomerContact{Round,Effect,Event}.php` | 首轮、可恢复副作用、追加事件 |
| `database/migrations/2026_11_13_000074_create_customer_contact_rounds.php` | 三表、唯一键、等待索引 |
| `config/customer_contacts.php` | 策略／模式／分站点开关；默认关闭执行 |
| `config/gmail.php` | 从spam提取的通用账号映射 |
| `app/Services/CustomerContacts/MultiDressPolicy.php` | 硬性入口、是否需要首信、超时条件 |
| 同目录 `MultiDressRoundManager.php` | Case／首轮幂等创建、版本及状态转换 |
| 同目录 `ConsoleContactReader.php` | 明确终端的沟通单／订单／历史、分页完整性和命中证明 |
| 同目录 `CommunicationReader.php` | Gmail／HelpDesk沟通证据、最新水位、接管检测 |
| 同目录 `MultiDressInput.php` | 不可变事实快照、业务哈希及版本绑定 |
| 同目录 `MultiDressDraftService.php`、`MultiDressTemplate.php` | 受限工作流、正文渲染、程序守卫、独立复核 |
| 同目录 `GmailFirstRoundTransport.php`、`MultiDressMailExecutor.php` | 账号校验、发送／对账；发送意图状态机 |
| 同目录 `MultiDressConsoleExecutor.php` | 备注／两件超时预览与有授权的Confirm，回读对账 |
| 同目录 `MultiDressWaitService.php`、`MultiDressHandoffService.php` | 期限检查、回复停止、单个FollowUp |
| 同目录 `MultiDressHealthService.php` | 卡住、unknown、备注失败和到期告警 |
| `app/Http/Controllers/Internal/N8nMultiDressController.php` | 独立n8n受限接口，不含执行端点 |
| `app/Services/N8n/N8nMultiDressWorkflowClient.php` | 专用Webhook触发 |
| `n8n/workflows/work__multi-dress-first-round-v1.json` | 分析／审核／一次修订，不直接发信或写Console |
| `app/Console/Commands/{ScanMultiDressContacts,ProcessMultiDressEffects,CheckMultiDressWaiting,ReportMultiDressHealth,RunMultiDressReplay}.php` | 扫描、执行／对账、到期、健康、隔离回放 |
| `app/Admin/Controllers/MultiDressRoundController.php`、`resources/views/admin/multi-dress-rounds/{index,show}.blade.php` | 事实／草稿／执行状态／停止与接管 |
| `docs/runbooks/seawave-multi-dress-first-round.md` | 账号、启动、故障对账、停止及人工承接 |

快照契约使用数组；resources/schemas记录JSON契约，运行时由Laravel Validator及业务守卫校验，不引入额外JSON Schema库。

```php
// tests/Support/MultiDressScenario.php 的完整基础夹具；测试订单不是生产订单。
namespace Tests\Support;

final class MultiDressScenario
{
    public static function snapshot(): array
    {
        return [
            'schema_version' => 'multi_dress.first_round.input.v1',
            'identity' => ['console_key' => 'seawave', 'site_key' => 'ellenbridals',
                'sid' => 36, 'oid' => 900001, 'order_number' => 'ebd20990101-1'],
            'order' => ['paid' => true, 'cancelled' => false, 'shipped' => false,
                'production_started' => false, 'on_hold' => true],
            'items' => [
                ['id' => 101, 'type' => 'dress', 'active' => true, 'item_code' => 'TEST-A',
                    'color' => 'As picture', 'size' => '2', 'quantity' => 1],
                ['id' => 102, 'type' => 'dress', 'active' => true, 'item_code' => 'TEST-B',
                    'color' => 'Black', 'size' => '2', 'quantity' => 1],
            ],
            'contacts' => [['id' => 301, 'source' => 'multi_dress', 'version' => 1,
                'released' => false, 'deleted' => false]],
            'trigger' => ['verified' => true, 'original_count' => 2,
                'item_ids' => [101, 102], 'rule_version' => 'fixture-v1'],
            'human_hold' => false, 'other_blockers' => [], 'intent_complete' => false,
            'policy' => ['version' => 'fixture-policy-v1',
                'url' => 'https://www.ellenbridals.com/article/return-policy.html'],
        ];
    }

    public static function communication(): array
    {
        return ['complete' => true, 'fresh' => true, 'identity_unique' => true,
            'prior_contact' => false, 'reply_seen' => false, 'delivery_failure' => false,
            'human_takeover' => false, 'checkpoint' => ['captured_at' => '2099-01-01T00:00:00Z',
                'gmail_complete' => true, 'helpdesk_complete' => true]];
    }
}
```

## Task 1：首轮台账和确定性业务边界

**Files:** 创建上述三个Model、迁移、config、`MultiDressPolicy.php`、`MultiDressRoundManager.php`、`tests/Support/MultiDressScenario.php`、`tests/Feature/MultiDressRoundLedgerTest.php`、`tests/Unit/MultiDressPolicyTest.php`。

**Interfaces:**

- `MultiDressPolicy::assess(array $snapshot, array $communication): array` → `decision=skip|manual|not_needed|prepare_first_email, reason`。
- `MultiDressRoundManager::ingest(array $snapshot, array $communication): CustomerContactRound`；在DB事务中创建／复用Case及首轮，不调用外部服务。
- `MultiDressRoundManager::requestWork(CustomerContactRound $round): WorkItem`；同input_hash复用，不重发。

- [ ] 写失败测试，先验证同一订单不同contact／模板仍只有一个首轮，以及跨终端／站点不会撞键：

```php
public function test_ingest_reuses_first_round_after_contact_changes(): void
{
    $s = \Tests\Support\MultiDressScenario::snapshot();
    $c = \Tests\Support\MultiDressScenario::communication();
    $manager = app(\App\Services\CustomerContacts\MultiDressRoundManager::class);
    $first = $manager->ingest($s, $c);
    $s['contacts'][0]['id'] = 302;
    $again = $manager->ingest($s, $c);
    $this->assertSame($first->id, $again->id);
    $this->assertDatabaseCount('customer_contact_rounds', 1);
    $this->assertDatabaseCount('customer_contact_effects', 0);
}
```

- [ ] 运行 `runuser -u csi -- php artisan test tests/Feature/MultiDressRoundLedgerTest.php tests/Unit/MultiDressPolicyTest.php`，预期新类／表不存在而失败。
- [ ] 实现三表及唯一约束；`first_round`键不含模板版本、hash、contact_id。Case source按 `console:seawave:sid:oid` 命名。配置包含 `mode=shadow,send_enabled=false,console_note_enabled=false,timeout_execute_enabled=false`，分site独立开关默认关闭。
- [ ] 实现assess顺序：终端／来源 → 已处理状态 → 有效礼服范围 → 人工联系／接管 → 读取完整性 → 已知意图 → 准备首信。礼服按quantity累加且排除无效项；资格缺证明阻止timeout，但不伪造新拦截。
- [ ] 添加数据提供器覆盖MyDorris、已解除、发货、付款失败、1件、Service／配件、已有回复、完整意图、3件仍可询问；全部通过上述测试后仅提交本任务文件。

## Task 2：Console只读事实、命中证据及候选扫描

**Files:** 创建 `ConsoleContactReader.php`、`MultiDressInput.php`、`ScanMultiDressContacts.php`、`resources/schemas/multi-dress-first-round-input-v1.json`、`docs/api/seawave-multi-dress-execution-contract.md`、`tests/Feature/MultiDressContactReadTest.php`。按需扩展 `ConsoleNewClient.php` 的只读封装；不增加任意URL能力。

**Interfaces:**

- `ConsoleContactReader::candidates(int $page, int $pageSize): array` → `items,pagination,complete,request_id`；terminal固定seawave。
- `ConsoleContactReader::snapshot(string $orderNumber): array` → Task1快照加来源request_id、捕获时间及历史引用；缺命中证明为 `trigger.verified=false`。
- `MultiDressInput::capture(CustomerContactRound $round): array` → `snapshot,hash`；hash涵盖沟通水位及版本。
- 命令：`contacts:multi-dress-scan --console=seawave --order=ebd20261105-1295 --mode=shadow`。不传order时按完整分页扫描已有沟通单。

- [ ] 写失败测试，Fake第二页仍有multi_dress，第一页只有其它source，断言完整读取后才能返回候选：

```php
public function test_scan_does_not_stop_at_first_page_without_multi_dress(): void
{
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake([
        'https://console.example.invalid/api/admin/customer-contacts*' =>
            \Illuminate\Support\Facades\Http::sequence()
                ->push(['success' => true, 'data' => ['items' => [],
                    'pagination' => ['current_page' => 1, 'last_page' => 2]]])
                ->push(['success' => true, 'data' => ['items' => [['id' => 301, 'source' => 'multi_dress']],
                    'pagination' => ['current_page' => 2, 'last_page' => 2]]]),
    ]);
    config(['services.console_new.base_url' => 'https://console.example.invalid/api/admin',
        'services.console_new.token' => 'fixture']);
    $first = app(\App\Services\CustomerContacts\ConsoleContactReader::class)->candidates(1, 100);
    $second = app(\App\Services\CustomerContacts\ConsoleContactReader::class)->candidates(2, 100);
    $this->assertFalse($first['complete']);
    $this->assertTrue($second['complete']);
    $this->assertSame('multi_dress', $second['items'][0]['source']);
}
```

- [ ] 运行 `runuser -u csi -- php artisan test tests/Feature/MultiDressContactReadTest.php`，预期新reader不存在而失败。
- [ ] 封装GET contacts／show／histories／order；不传文档未声明source筛选；读取其它阻断仅为防错，不操作其事项。source_hash区分系统自己的备注与人工新编辑，不能排除整个internal_note字段。
- [ ] 将trade当前缺少的contacts工具、原始命中证明、release前置条件、正常订单阶段推进列成外部接口验收表；当前只有旧快照时显式incomplete，禁止自动执行。交互核实业务数据先list_sites，之后每次调用明确site。
- [ ] 增加生产处理中／已取消一件的本例只读fixture，预期skip；403、分页上限、缺scope、未知rule_version均有明确reason。测试通过后提交本任务文件。

## Task 3：完整沟通读取、账号映射和接管检测

**Files:** 创建 `CommunicationReader.php`、`GmailCommunicationSource.php`、`HelpDeskCommunicationSource.php`、`config/gmail.php`、`tests/Feature/MultiDressCommunicationReadTest.php`。修改 `GwsClient.php`、`HelpDeskClient.php`、`config/gmail_spam.php`；保持现有spam功能兼容。

**Interfaces:**

- `GwsClient::listMessageIds(string $query, int $pageLimit): array` → message ID列表；到上限仍有nextPageToken必须抛错，不能返回部分列表冒充完整。
- `HelpDeskClient::getTicket(string $ticketId): array`、`getTicketMessages(string $ticketId, int $pageLimit = 100): array`；只读、完整分页；具体路径以已核实HelpDesk文档为准并写入Task2契约文档。
- `CommunicationReader::capture(array $identity, \Carbon\CarbonImmutable $since): array` → Task1通信结构加 `evidence_refs,associated_ticket_ids,ambiguous_messages`。
- `GmailCommunicationSource::capture(array $identity, \Carbon\CarbonImmutable $since): array` 及 `HelpDeskCommunicationSource::capture(array $identity, \Carbon\CarbonImmutable $since): array` → `complete,messages,checkpoint,human_takeover`；CommunicationReader构造器注入这两个source，统一做关联／完整性判定。
- 有关邮件读取必须验证实际账号。若HelpDesk接口无法完整返回当前消息／活动，返回complete=false，不回退成“未回复”。

- [ ] 写失败测试，使用缺少关联详情的HelpDesk结果，必须阻止无回复推断：

```php
public function test_unavailable_helpdesk_messages_are_not_no_reply_evidence(): void
{
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Process::preventStrayProcesses();
    $this->mock(\App\Services\CustomerContacts\GmailCommunicationSource::class)
        ->shouldReceive('capture')->once()->andReturn(['complete' => true, 'messages' => [],
            'checkpoint' => [], 'human_takeover' => false]);
    $this->mock(\App\Services\CustomerContacts\HelpDeskCommunicationSource::class)
        ->shouldReceive('capture')->once()->andReturn(['complete' => false, 'messages' => [],
            'checkpoint' => [], 'human_takeover' => false, 'error' => 'detail_unavailable']);
    $reader = app(\App\Services\CustomerContacts\CommunicationReader::class);
    $result = $reader->capture(\Tests\Support\MultiDressScenario::snapshot()['identity'],
        \Carbon\CarbonImmutable::parse('2099-01-01T00:00:00Z'));
    $this->assertFalse($result['complete']);
    $this->assertFalse($result['fresh']);
}
```

上例只替换外部source，实际执行CommunicationReader的关联／完整性判定；生产类不提供切换事实的方法。

- [ ] 运行 `runuser -u csi -- php artisan test tests/Feature/MultiDressCommunicationReadTest.php`，预期新类不存在而失败。
- [ ] 将账号映射移到gmail.php，spam读取新映射；检查两个账号profile及Reply-To／send-as事实，未验证别名不使用。通信按order、邮箱、引用ID、HelpDesk ID关联，不按subject单独认定。
- [ ] 分别覆盖新主题回复、部分回复、转发重复、同一客户多单、DSN／自动回复、垃圾箱、历史人工发信和已关闭ticket；无法唯一关联标manual。完成读取时记录扫描水位和captured_at。
- [ ] 回归 `runuser -u csi -- php artisan test tests/Feature/MultiDressCommunicationReadTest.php tests/Feature/GwsClientTest.php tests/Feature/GmailSpamReviewTest.php tests/Feature/GmailSpamForwardTest.php`；通过后提交本任务文件。

## Task 4：专用n8n草稿、固定模板及独立审核

**Files:** 创建 `MultiDressDraftService.php`、`MultiDressTemplate.php`、`N8nMultiDressController.php`、`N8nMultiDressWorkflowClient.php`、专用workflow JSON、`resources/schemas/multi-dress-first-round-analysis-v1.json`、`tests/Feature/N8nMultiDressWorkflowTest.php`、`tests/Unit/MultiDressTemplateTest.php`。修改 `routes/api.php`、`config/services.php`。

**Interfaces:**

- `MultiDressTemplate::render(array $snapshot, array $analysis): array` → `subject,body,template_version,content_hash`；收件人不由分析输出。
- `MultiDressDraftService::start(WorkItem $item, string $executionId): array` → 受限快照；租约600秒。
- `MultiDressDraftService::complete(WorkItem $item, string $executionId, array $analysis, array $review): array` → `round_key,decision,reviewed_content_hash`。
- 审核通过时保存 `approved_mail_ref,approved_input_hash,approved_content_hash,review_hash`，冻结私有邮件文件；ready状态或单独一个review_hash不能绕过这些校验。
- 内部POST `/internal/n8n/multi-dress-work-items/{workItemKey}/start|complete|fail`，仅 `internal.n8n:work`；请求带execution_id，complete绑定输入和正文hash。
- `N8nMultiDressWorkflowClient::trigger(WorkItem $item): void`；workflow_key `work/multi-dress-first-round-v1`，version `1.0.0`。

- [ ] 写失败测试，商品名称为空时只用型号，不臆造款名；两件和三件模板均不得披露内部超时后果：

```php
public function test_template_includes_hold_and_deadline_without_internal_timeout_rule(): void
{
    $snapshot = \Tests\Support\MultiDressScenario::snapshot();
    $mail = app(\App\Services\CustomerContacts\MultiDressTemplate::class)->render($snapshot,
        ['question_module' => 'purpose_and_keep_plan', 'unknowns' => ['purpose_or_wearer', 'keep_plan']]);
    $this->assertStringContainsString('on hold', $mail['body']);
    $this->assertStringContainsString('within 48 hours', $mail['body']);
    $this->assertStringContainsString('TEST-A', $mail['body']);
    $this->assertStringContainsString('TEST-B', $mail['body']);
    $this->assertStringNotContainsString('automatically enter production', $mail['body']);
}
```

- [ ] 运行 `runuser -u csi -- php artisan test tests/Unit/MultiDressTemplateTest.php tests/Feature/N8nMultiDressWorkflowTest.php`，预期新类／路由不存在而失败。
- [ ] 工作流顺序为start→分析JSON→固定模板渲染→独立AI审核→最多一次修订→complete。新增内部render接口由 `N8nMultiDressController::render` 处理，路径同前缀 `/render`，方法调用 `MultiDressTemplate::render`，不开放任意模板／工具。
- [ ] 程序校验站点政策、商品数量／码／色、暂停事实、48小时、有限提问模块、无额外承诺及exact hash；工作流状态只允许evaluating在shadow运行，live须active。缓存指纹包含模型／prompt／policy／template版本。
- [ ] 测试重复complete、过期租约、输入变化、人工接管、未知模块、注入提示、复核不通过／第二次不通过；全通过后提交本任务文件。真实n8n回放另在Task8验收，不把fake审核等同AI通过。

## Task 5：单次Gmail发送、持久化意图及unknown对账

**Files:** 创建 `GmailFirstRoundTransport.php`、`MultiDressMailExecutor.php`、`ProcessMultiDressEffects.php`、`tests/Feature/MultiDressMailExecutionTest.php`、`tests/Support/FakeFirstRoundTransport.php`。修改服务容器绑定所在 `app/Providers/AppServiceProvider.php`。

**Interfaces:**

- `GmailFirstRoundTransport::verifyAccount(string $siteKey): array` → 真实profile及已验证身份。
- `GmailFirstRoundTransport::send(array $frozenMail): array` → 单次gws调用的收据；发送失败不通用重试。
- `GmailFirstRoundTransport::reconcile(CustomerContactEffect $effect): array` → `matched|unknown|ambiguous,receipt`；必须核对marker、身份、正文hash和完整时间窗。
- `MultiDressMailExecutor::send(int $roundId): array`、`reconcile(int $effectId): array`；unknown／sending只能reconcile。
- 命令 `contacts:multi-dress-process-effects --limit=20`；先读取mode和独立开关，shadow不创建live transport。

- [ ] 写失败测试，单次发送超时以后重复运行不可重发：

```php
public function test_unknown_send_is_reconciled_without_a_second_send(): void
{
    $transport = new \Tests\Support\FakeFirstRoundTransport('unknown');
    $this->app->instance(\App\Services\CustomerContacts\GmailFirstRoundTransport::class, $transport);
    $round = \App\Models\CustomerContactRound::factory()->readyFirstRound()->create();
    $executor = app(\App\Services\CustomerContacts\MultiDressMailExecutor::class);
    $executor->send($round->id);
    $executor->send($round->id);
    $this->assertSame(1, $transport->sendCalls);
    $this->assertSame('send_unknown', $round->fresh()->communication_state);
    $this->assertNull($round->fresh()->wait_until);
}
```

本任务创建 `database/factories/CustomerContactRoundFactory.php`，`readyFirstRound(): static` 使用Task1夹具、Task4真实renderer、Storage::fake('local')私有文件，填齐冻结内容和相符的input／content／review hash；不存在单个hash强行放行的捷径。setUp打开测试专用send开关，并mock ConsoleContactReader和CommunicationReader返回未变夹具，禁止真实HTTP。Fake构造器签名 `__construct(string $outcome='sent')`，公开计数 `int $sendCalls`；send记录后返回unknown或模拟SENT收据，永不调用Process。

- [ ] 运行 `runuser -u csi -- php artisan test tests/Feature/MultiDressMailExecutionTest.php`，预期新执行器不存在而失败。
- [ ] DB事务／唯一effect提交发送意图后才调用gws；已有sending或unknown不抢发。发送前刷新事实和通信、核对审核hash及账号；失败在调用前可重试，调用发生后不确定一律unknown。
- [ ] 回查实际SENT及正文／收件人；保存实际RFC822 ID，不能依赖提交的Message-ID。确认后设置sent_at与wait_until=sent_at+48h，建立单独sent_note effect；回查晚到不改计时起点。
- [ ] 测试两个worker争用同一effect的数据库唯一约束／状态CAS、发送后崩溃、返回缺失、回查多匹配、发件箱分页上限、无匹配仍unknown、重复任务不重置计时、shadow和replay零Process调用。
- [ ] 回归 `runuser -u csi -- php artisan test tests/Feature/MultiDressMailExecutionTest.php tests/Feature/GwsClientTest.php`；通过后提交本任务文件。

## Task 6：备注预览、授权确认、冲突恢复和人工可见记录

**Files:** 创建 `MultiDressConsoleExecutor.php`、`tests/Feature/MultiDressConsoleExecutionTest.php`；扩充Task2接口契约文档。仅在确认接口范围后扩展 `ConsoleNewClient.php`，不复用任意订单patch进行放行。

**Interfaces:**

- `MultiDressConsoleExecutor::previewNote(int $roundId): array` → 本次effect、实际diff、operation／confirm token、payload_hash、expiry。
- `MultiDressConsoleExecutor::confirm(int $effectId, string $authorizationReference): array`；核对真实用户、预览摘要、未过期、源状态未变，再使用同一操作标识Confirm。
- `MultiDressConsoleExecutor::reconcile(int $effectId): array` → 原操作和最新业务记录的对账结果。
- 授权凭据引用现有用户审计体系；operation_id单独存在必须拒绝执行。不得硬编码reviewed_by或使用nosun-agent冒充真实actor。

- [ ] 写失败测试，只有operation ID而没有用户授权时绝不Confirm：

```php
public function test_operation_id_does_not_authorize_a_console_write(): void
{
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake();
    $effect = \App\Models\CustomerContactEffect::factory()->previewedNote()->create();
    $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
    try {
        app(\App\Services\CustomerContacts\MultiDressConsoleExecutor::class)->confirm($effect->id, '');
    } finally {
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
```

扩展上一任务的Round factory，创建 `database/factories/CustomerContactEffectFactory.php`；effect factory的 `previewedNote(): static` 创建关联round、kind=sent_note、state=previewed、payload_hash和fixture操作引用，authorization_reference为空。

- [ ] 运行 `runuser -u csi -- php artisan test tests/Feature/MultiDressConsoleExecutionTest.php`，预期新类／factory不存在而失败。
- [ ] 备注内容为最新internal_note加唯一首轮marker，recorder为nosun-agent；只允许这两个changes字段。字段级冲突要保留人工新增内容并重新预览；已知失效可以换预览，unknown必须先复用原operation对账。
- [ ] 测试发送成功而备注失败，重跑只补备注；marker已存在直接记录完成；人工原备注完整保留；错误终端、sid、contact、status/customer_requirement等越权字段拒绝。发送动作不受备注失败反向重试。
- [ ] 该任务完成只具备受控写回能力，不默认启用生产Confirm。全部测试通过后提交本任务文件。

## Task 7：48小时等待、回复交接和两件受限推进

**Files:** 创建 `MultiDressWaitService.php`、`MultiDressHandoffService.php`、`CheckMultiDressWaiting.php`、`tests/Feature/MultiDressWaitingTest.php`；在 `MultiDressConsoleExecutor.php` 增加受限timeout；复用 `FollowUpManager.php` 不伪造Conversation。

**Interfaces:**

- `MultiDressWaitService::inspect(int $roundId, \Carbon\CarbonImmutable $at): array` → `waiting|human_handoff|held|timeout_preview_ready`及reason和证据。
- `MultiDressHandoffService::upsert(int $roundId, string $reason, array $evidence): \App\Models\FollowUp`；round行锁及follow_up_id保证单个待办。
- `MultiDressConsoleExecutor::previewTimeout(int $roundId): array`；Confirm仍调用Task6同一授权方法，源服务必须接受并执行完整前置条件。
- 命令 `contacts:multi-dress-check-waiting --limit=20`；每轮小批读取waiting/due，发送unknown不能进入。

- [ ] 写失败测试，固定时钟验证两件到期仅形成预览资格；先用factory创建sent、note recorded、真实发送时间已知的隔离首轮：

```php
public function test_partial_reply_stops_timeout_even_after_deadline(): void
{
    $round = \App\Models\CustomerContactRound::factory()->sentTwoDress()->create();
    $communication = \Tests\Support\MultiDressScenario::communication();
    $communication['reply_seen'] = true;
    $communication['evidence_refs'] = ['fixture:partial-customer-reply:Yes'];
    $this->mock(\App\Services\CustomerContacts\CommunicationReader::class)
        ->shouldReceive('capture')->andReturn($communication);
    $result = app(\App\Services\CustomerContacts\MultiDressWaitService::class)->inspect($round->id,
        \Carbon\CarbonImmutable::parse($round->wait_until)->addSecond());
    $this->assertSame('human_handoff', $result['decision']);
    $this->assertSame('reply_seen', $round->fresh()->waiting_state);
    $this->assertDatabaseMissing('customer_contact_effects', ['round_id' => $round->id, 'effect_kind' => 'two_dress_timeout']);
}
```

factory的 `sentTwoDress(): static` 填入Task1完整快照、sent_at=2099-01-01T00:00:00Z、wait_until=2099-01-03T00:00:00Z、note_state=recorded。Console reader由测试替身返回未变快照，禁止真实HTTP。

- [ ] 运行 `runuser -u csi -- php artisan test tests/Feature/MultiDressWaitingTest.php`，预期新service不存在而失败。
- [ ] 按设计第9节逐项实现条件，先处理reply再判断到期；到期时强制读取最新Gmail／HelpDesk及接管，不依赖同步表“暂时没有消息”。未知原始两件证明、三件减两件、其它阻断、note未成功、临近执行新消息均hold。
- [ ] timeout effect只在全部资格成立后生成；preview和confirm前均重检，源系统必须原子核对目标事项version／商品scope／其它阻断／接管。现有裸release不满足契约时只保留建议。
- [ ] 分开记录blocker_removed、normal_assignment_queue、assigned、production_started，只有源系统真实证据才推进记录；接口只返回release成功时停在assignment_pending。不能整单审核解锁其它事项。
- [ ] 测试47:59:59、48:00:00、延迟执行期间新回复、部分回复、三件以上、原始三件减两件、商品变化、混合ETA/comment、人工接管、退信、读取不完整、授权缺失、重复到期任务仅一个FollowUp；通过后提交本任务文件。

## Task 8：后台、隔离回放、调度和上线验收

**Files:** 创建后台controller／views、`MultiDressHealthService.php`、`ReportMultiDressHealth.php`、`RunMultiDressReplay.php`、runbook、`tests/Feature/MultiDressReplayTest.php`、`tests/Feature/MultiDressAdminTest.php`、`tests/Feature/MultiDressHealthTest.php`。修改 `app/Admin/routes.php`、`routes/console.php`、`n8n/README.md`。

**Interfaces:**

- `MultiDressHealthService::report(\Carbon\CarbonImmutable $at): array` → counts、blocking_incidents、per_site；不含客户正文／地址。
- `contacts:multi-dress-replay --fixture=resources/data/seawave-multi-dress-replay-ebd1295-v1.json`：隔离Case／WorkItem、checksum绑定、假执行适配器；不接受live发送选项。
- 后台页面 `/admin/multi-dress-rounds` 及 `/{id}`；展示事实、证据、草稿、审核、发送／备注／等待／推进各自状态，提供人工暂停及真实预览确认。

- [ ] 写失败测试，replay模式任何真实进程／HTTP调用都会失败：

```php
public function test_offline_replay_has_no_external_side_effects(): void
{
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Process::preventStrayProcesses();
    $this->artisan('contacts:multi-dress-replay', [
        '--fixture' => base_path('resources/data/seawave-multi-dress-replay-ebd1295-v1.json'),
    ])->assertSuccessful();
    \Illuminate\Support\Facades\Http::assertNothingSent();
    \Illuminate\Support\Facades\Process::assertNothingRan();
}
```

- [ ] 运行 `runuser -u csi -- php artisan test tests/Feature/MultiDressReplayTest.php tests/Feature/MultiDressAdminTest.php tests/Feature/MultiDressHealthTest.php`，预期新命令／页面不存在而失败。
- [ ] 后台不把AI审核显示成人工授权；人工停止立即使发送／timeout待执行失效。恢复仅重新检查，不重发。权限限制复用既有后台用户授权，操作保留真实actor。
- [ ] Scheduler候选每5分钟、effects每分钟、waiting每分钟、health每5分钟，均有withoutOverlapping；DB锁和effect唯一键仍是执行防重基础。mode=off不扫描，shadow不执行远程动作；关闭发送后unknown仍只读对账。
- [ ] 把本轮47项设计断言移植为PHP契约和执行测试，另补真实并发／崩溃恢复测试；当前Python探针只作为研究材料，不导入生产路径。
- [ ] 完成真实n8n隔离回放，记录workflow checksum、execution ID、事实／政策／正文人工标注；本例当前状态必须skip，历史回放必须明确哪些通信事实是假设。出现事实／政策错误或应阻止场景漏拦，禁止active。
- [ ] 对两个品牌分别使用批准的内部测试收件地址验证身份、收件、回复转HelpDesk和关联；检查邮件外观和48小时文本。此步骤需要后续明确真实测试发送授权，本次不执行。
- [ ] 全面跑本模块测试及受影响的Gmail／Console／FollowUp／Ticket工作流回归；检查迁移唯一键、前端可见状态、调度开关和runbook后提交本任务文件。通过后先发布A的shadow，再分别批准B和C。

## 自检和未通过门禁

| 设计要求 | 任务覆盖 | 当前状态 |
| --- | --- | --- |
| 多终端隔离、现有入口、当前已处理跳过 | 1、2、8 | 源码及隔离测试完成，主实例未安装/启用 |
| 历史去重、真实回复、HelpDesk人工接管 | 3、7 | 实时详情及占用契约待联调，不按空消息放行 |
| 首信文案、事实和独立AI审核 | 4 | 模板/审核服务与inactive工作流完成；真实n8n审核未执行 |
| 唯一首轮、单次发送、unknown对账 | 1、5 | 持久化执行器及unknown回查fake测试通过；真实收件待验收 |
| 备注冲突、授权、失败不重发 | 6 | 客户端与HTTP fake验证完成；无真实Preview/Confirm |
| 两件48小时／三件以上hold | 7 | 本地持久化分支通过；原始命中证明及源推进契约待交付 |
| 后台、待办、健康、隔离模式、停止 | 7、8 | 后台/命令/调度定义已实现；主实例未迁移，执行开关关闭 |

执行顺序为1→2／3→4→5→6→7→8；可将每项作为独立审阅提交。外部接口未就绪不阻止A的离线／shadow开发，但阻止对应live动作。
