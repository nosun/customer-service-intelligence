# Seawave 多件确认首轮：现有接入与待交付契约

更新日期：2026-11-13。本文区分已实现的客户端能力与尚不存在的上游能力；提议字段不代表生产接口已提供。

## 终端及入口

仅使用 `console_key=seawave`，来源为 `console-new.seawavetech.com` 的现存 `source=multi_dress` 沟通单。品牌固定 `dressafford/sid=1/dad` 与 `ellenbridals/sid=36/ebd`。MyDorris 不进入本模块执行链。所有读取使用现有 ConsoleNewConnections 和资源白名单；不接受任意 URL。

当前读取订单、沟通单列表与详情、沟通历史及工单列表。列表完整分页，页码不符或达到上限即失败。当前源码不依据未知工单状态码推断“尚未生产”：只有订单明确提供布尔 `production_started=false` 才能形成此证明；目前生产文档未保证该字段，缺失进入人工核查。

## 已实现：本地受限 n8n 接口

前缀 `/api/internal/n8n/multi-dress-work-items/{work_item_key}`，使用现有 work 内部 Token 中间件。只接受 `multi_dress_first_round` WorkItem，不允许邮件、退款、取消、Console Preview/Confirm 等动作。

| POST 后缀 | 请求主要字段 | 结果 |
| --- | --- | --- |
| `/start` | execution_id、workflow_key、workflow_version | 600秒租约，返回 context：input_hash、去除客户地址的 snapshot、communication、可用问题模块 |
| `/render` | execution_id、analysis | 固定模板 mail、input_hash，最多渲染两次 |
| `/complete` | execution_id、analysis、review | 校验精确审核哈希，冻结私有邮件，结果 ready 或 manual |
| `/fail` | execution_id、error_message | 只终结当前租约工作，不发信 |

`analysis` 限定 decision、question_module、evidence_refs、blocking_reasons；`review` 必须包含 approved、reviewed_input_hash、reviewed_content_hash、violations。PHP 使用 Laravel Validator 及服务端业务校验，resources/schemas 是公开契约说明，当前没有额外 JSON Schema 运行库。

源哈希覆盖订单、商品、沟通单及人工备注、沟通证据、政策、模板、workflow 文件 checksum、AI 配置与 Gmail 账号。只排除采集时刻和请求 ID。任何业务事实变化都必须重新生成和审核。发信还校验已完成工作、work/customer_contacts 领域、active 状态、登记文件 checksum、精确审核证明及冻结的收件/发件地址。

## 已实现：Console 备注写回适配器

`PATCH /api/admin/customer-contacts/{id}`，仅接受 `changes.internal_note` 与 `changes.recorder=nosun-agent`，以及正整数 version。保留原备注，追加唯一首轮 marker。

Preview 请求包含 preview=true、reason、version、changes；必须返回 allowed=true、diff、confirm_token、expires_at，且 diff.after.internal_note 与请求一致。页面展示实际 diff 后，由已登录且有 execute 权限的真实人员确认。Confirm 再读目标与版本，传 preview=false、原 confirm_token、固定 idempotency_key，记录真实 authorized_by 与精确 payload_hash。AI 审核及 operation_id 都不能代替人工授权。

源接口明确返回409/422的CONTACT_CONFLICT或CONFIRM_TOKEN_STALE时，原写入未被接受，可重新读取同事内容并再次预览；其它不明结果只读回查 marker，禁止重放 Confirm。首次回查成功保存 contact_baseline（版本、内部备注、状态、客户要求、记录人、解除/删除/阻断字段）；后续人工修改不能被再次回查覆盖。等待检查将其作为停止超时路径的依据。

这是客户端已实现的预期响应契约，尚未在真实生产 Preview/Confirm 上完成验收。本次没有执行任何生产写操作。

## 待交付：HelpDesk 最新沟通与人工占用

需要一个有明确完整性与时间水位的只读适配器，可按品牌、订单及客户身份搜索全部相关 Ticket，并读取最新事件、客服发件/草稿及接管状态。须证明搜索范围完整，区分超时、失败、无结果和无回复，识别转发及重复事件，提供稳定 ticket/event ID 和 checked_at。

当前 HelpDeskCommunicationSource 只使用本地同步表作为正向线索，固定 complete=false；缓存暂未出现邮件不能证明无回复，也不能证明同事未处理。因此当前真实订单不会自动达到首信或无回复推进资格。

Gmail 全文件夹搜索及完整分页已实现，但邮件原始地址/订单之外的转发身份歧义仍转人工，不由 AI 猜测归属；这不能替代 HelpDesk 的实时占用检查。

## 待交付：原始命中及生产前状态证明

需要 Console 提供首次拦截时不可变的 rule_version、original_count（有效礼服按 quantity 累加）、item_ids、发生时间及来源证据。后续订单从三件减为两件不能覆盖原始命中。当前缺失时 trigger.verified=false；它本身不阻止询问意图，但阻止两件超时路径。

需要上游给出可验证的未生产、未发货、支付及暂停证据，不能靠订单已付款或工单列表为空推断尚未生产。

## 待交付：两件48小时无回复的受限推进

现有 release 只移除分配阻断，不等于订单进入正常分配，更不等于已投产。客户端没有把它包装成完整推进。`timeout_execute_enabled` 固定 false，`previewTimeout()` 固定返回 source_timeout_contract_missing。

未来上游操作需要原子核对：终端/站点/订单、目标事项 ID 与版本、原始两件证明、当前同一有效礼服范围、支付/取消/发货/生产状态、其它阻断、人工接管，以及绑定到最新回复水位的授权。Preview 后事实变化必须使 Confirm 失效。不得解除 ETA、comment 等其它事项。

响应需分开提供 blocker_removed、normal_assignment_queue、assigned、production_started 的真实证据及幂等操作引用；只有实际发生的步骤才能记为完成。跨 Gmail/HelpDesk/Console 无单库事务，最终执行前的最新回复重检与上游版本条件仍必需。

## 待交付：真实 n8n 发布证据

本次仅生成 inactive workflow 文件、PHP 隔离回放及静态节点检查。新增 key 可登记 evaluating，但 WorkflowReleaseGateRegistry 尚无该 key 的发布门禁，active 注册会被拒绝。不能将离线报告、手工构造 active 记录或模拟审核作为发布证据。

上线前需补专用真实 n8n fixture 回放入口与发布门禁，绑定 workflow checksum、fixture checksum、真实 execution ID、模型信息和人工标注结果，失败用例不得漏拦。独立审核应单独执行，不能复用分析输出冒充审核。受控品牌收件/回复链验收仍需另行授权。
