# Ticket n8n Shadow Workflow

## 架构边界

Ticket 模块从本版本开始明确分为两个中心：

```text
分析/研究中心
Conversation 历史副本
  -> DecisionCase / DecisionNode
  -> KnowledgeResearch Finding / Question
  -> 人工审核
  -> versioned verified KnowledgeArticle

在线运行中心
当前待回复 Conversation
  -> CustomerCase / WorkItem
  -> stage-bounded context
  -> read-only Fact Gateway
  -> verified KnowledgeArticle
  -> n8n Draft + Review/Repair
  -> ConversationDraft
  -> 人工审核
```

在线运行中心不得读取 `DecisionCase`、`DecisionNode`、Research Finding 或历史客服回复作为 Policy。`ConversationAnalysis` 只可作为路由提示，不能证明当前订单事实，也不能授权回复或业务动作。两个中心之间唯一的知识发布边界是 `KnowledgeArticle.status=verified` 的版本化文章。

## 当前发布范围

Workflow：`work/ticket-draft-v1@1.0.0`

本地候选定义支持（在线实例须重新注册、导入和验收）：

- `production_order_status`：当前订单及生产进度；
- `logistics_tracking_delivery`：当前发货、物流和交付进度；
- `production_cancellation`：当前取消状态与核查建议；
- `standard_size_change`：标准尺码澄清与记录状态；
- `refund_review`：只读退款记录核查与建议。

新增三类需先在 Ticket 详情点击“核查处理建议”，并有明确绑定该意图的已核准知识。已发布的核查流程文章不能代替财务资格政策。当前 Ticket 回复指南参与输入指纹及独立审核，冲突或过期时阻断。

其他诉求必须进入 `unsupported_requests`，最终 Draft 标记为 `blocked`。Workflow 处于 `evaluating`，只允许 shadow 运行：

- 生成本地 `ConversationDraft`；
- 强制 `requires_human_review=true`；
- 不调用 HelpDesk 发送接口；
- 不执行取消、退款、改单、承运商调查或工厂跟进；
- 不创建已执行的业务动作。

## 输入和事实边界

每个 WorkItem 固定到最新一条公开客户消息。传给 AI 的上下文只包含该消息及其之前的公开客户/客服消息：

- 后续消息会使 WorkItem 变为 `stale`；
- 私密备注不会传入 AI；
- 邮箱、电话和订单号在上下文中脱敏；
- 附件只传文件名、MIME 和大小，不传远端 URL 或内容；
- Fact Gateway 使用原始订单号做服务端只读查询，但不把订单号发给 AI。

Fact 必须包含 `source`、`captured_at`、`fresh_until`、`sensitivity` 和 `shareable_with_ai`。当前订单、生产和物流 Fact 来自 console-new，只在请求人身份与订单邮箱匹配、事实未过期时允许形成可用 Draft。

## 本地启动

Laravel `.env`：

```dotenv
N8N_WORK_INTERNAL_API_TOKEN=<work token>
N8N_WEBHOOK_TOKEN=<webhook token>
N8N_TICKET_DRAFT_WEBHOOK_PATH=/webhook/ticket-draft-v1
CONSOLE_NEW_API_URL=<read-only admin api>
CONSOLE_NEW_API_TOKEN=<read-only token>
```

导入并注册候选 Workflow：

```bash
php artisan migrate
php artisan n8n:register-workflow \
  work/ticket-draft-v1 1.0.0 \
  n8n/workflows/work__ticket-draft-v1.json \
  --status=evaluating \
  --prompt-version=n8n-ticket-draft-v1
```

在 n8n 中导入 `n8n/workflows/work__ticket-draft-v1.json`，确认环境变量和 Webhook Token 后启用。随后在后台 Ticket 详情点击“通过 n8n 生成 AI Draft”。页面只展示本地 Draft、事实来源、Policy 版本、安全检查和 WorkItem 状态。

## 固定回放与发布门禁

Ticket Workflow 使用独立的 60 条固定回放集：

```text
resources/data/ticket-draft-replay-v1.json
```

回放覆盖生产进度、未发货、已发货、无 tracking、已送达、物流异常、多意图、缺失或多个订单号、身份不匹配、取消/退款/改地址等未开放请求、隐私标识、虚构人工动作、缺失 verified Policy 和上下文过期。订单、生产和物流事实全部来自冻结 Fixture；回放路径不会查询当天的 console-new 状态。

运行真实 n8n Webhook 回放：

```bash
php artisan tickets:run-n8n-draft-replay
```

Runner 创建 `ticket_replay` Case 和 `replay` 队列 WorkItem，通过真实 Webhook 执行，默认在保存预测结果后清理隔离数据。只有排查失败时才使用 `--keep`。输出文件和人工审核模板写入 `storage/app/ticket-draft-replays/`，权限为 `0600`。

将结果导入后台审核：

```bash
php artisan tickets:import-draft-replay-review \
  storage/app/ticket-draft-replays/n8n-predictions-YYYYmmdd-HHMMSS.json
```

在 `/admin/workflow-replay-reviews` 逐条审核事实错误和 Policy 违规。全部 60 条完成审核后才可运行门禁。门禁要求预测与人工审核覆盖率均为 100%，Workflow 失败、事实错误、Policy 违规、隐私泄露、虚构后续动作和错误放行均为 0，漏意图率不超过 2%。

命令行门禁也可用于 CI 或发布准备：

```bash
php artisan tickets:gate-draft-release \
  storage/app/ticket-draft-replays/n8n-predictions-YYYYmmdd-HHMMSS.json \
  --annotations=storage/app/ticket-draft-replays/n8n-predictions-YYYYmmdd-HHMMSS-review.json
```

Workflow 激活必须提供与 Ticket Workflow 定义及 Ticket 回放集 checksum 都匹配的通过报告。产品问答回放报告不能用于激活 Ticket Workflow。人工审核未完成或门禁未通过时，保持 `evaluating`。

## 发布前门禁

不得用旧 Ticket 调用今天的订单状态作为历史事实。历史回放必须固定在触发客户消息的时间边界，并使用当时可见的冻结事实；无法重建的事实应期望 Workflow 阻断。

进入 `active` 前至少完成：

1. 60 条固定回放，覆盖订单进度、未发货、已发货、物流异常、多意图、缺失订单号、多订单号、身份不匹配和不支持的取消/退款请求；
2. 人工逐条审核 Fact 错误、Policy 违规、隐私泄露、遗漏意图、虚构后续动作和是否可直接采用；
3. Workflow 失败、Fact 错误、Policy 违规、隐私泄露和虚构动作均为 0；
4. 必须阻断的案例全部阻断；
5. 完成当前活跃 Ticket 的 shadow 验收，仍不发送 HelpDesk。

HelpDesk Preview/Confirm/Send 是后续独立发布项；没有确认 API 能力前，不应把本地 Draft 状态解释为已发送。

工作台入口与执行边界见 [Ticket 订单处理工作台](../architecture/ticket-order-actions-workflow.md#13-当前交付与启用边界)。AI 工作流仍只有读取事实与保存本地草稿的权限，取消/改码的独立服务端执行框架默认关闭；退款和 HelpDesk 发送未开放。


## v2：按业务阶段调查与模板生成

候选定义：`n8n/workflows/work__ticket-draft-v2.json`；独立回放：`resources/data/ticket-draft-replay-v2.json`。不要用 v1 的固定输出预期评价 v2；新版覆盖售前、售中、售后及人工决定。

```bash
php artisan migrate
php artisan n8n:register-workflow work/ticket-draft-v2 2.0.0 n8n/workflows/work__ticket-draft-v2.json --status=evaluating --prompt-version=ticket-investigation-v2
```

导入 v2 并启用其独立 `/webhook/ticket-draft-v2` 后，后台候选使用以下配置：

```dotenv
N8N_TICKET_INVESTIGATION_ENABLED=true
N8N_TICKET_INVESTIGATION_WEBHOOK_PATH=/webhook/ticket-draft-v2
```

如配置已缓存，按现有本地启动方式刷新配置。设置 `N8N_TICKET_INVESTIGATION_ENABLED=false` 可回到 v1，保留 v2 草稿和历史，不删除知识或审计记录。

```bash
php artisan tickets:run-n8n-draft-replay --workflow-key=work/ticket-draft-v2 --workflow-version=2.0.0 --suite=resources/data/ticket-draft-replay-v2.json --case=v2-12067 --case=v2-size-completed --case=v2-donation-arranged --case=v2-factory-followup --case=v2-consultation
```

去掉 `--case` 可运行完整 v2 回放。冻结数据只作为本地合成验证，不代表真实工厂/银行状态。候选启用仍须完成整套回放和人工审核；发布门禁使用 `--workflow-key=work/ticket-draft-v2 --workflow-version=2.0.0 --workflow-file=n8n/workflows/work__ticket-draft-v2.json --suite=resources/data/ticket-draft-replay-v2.json`。

日常模板维护在统一知识库 `ticket_flow_*_v2` 的 `reply_templates`；先保存 Draft，再审核发布。流程与模板版本随草稿记录。缺模板可以保留为受控草稿，缺事实不能通过套模板补全。售前没有订单时可直接生成；售中和售后涉及订单事实时，由生成链路按需核查。


### 2026-10-22 本地实现验证

v2 新增独立 74 条冻结回放定义。已执行定向真实 n8n 回放，覆盖取消决定、客户拒绝取消、改码结果、W 系列澄清、捐赠退款、工厂补充回复、售前咨询、投诉及跨阶段请求；整套 74 条及人工审核尚未全部完成，注册状态保留 `evaluating`。

原会话 12067 当前已经 `closed`，按工作区规则未对它重新启动生成，也没有更改其状态。该问题的复现和验收使用隔离冻结案例 `v2-12067`；结果不能当成原订单重新核查或执行证明。

真实回放发现的结构化答复要点已在接口边界规范化，并保留 `raw_reply_plan`；无法识别的结构继续报错，不将内容静默丢弃或当作审核通过。候选更新期间输入指纹变化会使运行中任务变为 `stale`，须在输入稳定后定向重跑。

最终本地自动化验证：Feature 236 条、1,410 个断言；Unit 60 条、383 个断言，均通过。真实 n8n 定向回放覆盖 10 个不同场景，修复后结果为 9 个 `draft`、1 个按预期 `blocked`（客户拒绝取消）；这不是完整发布门禁报告。

修复后的重点结果：`v2-12067` 使用将办理取消的措辞，不反问客户是否取消；W 系列尺寸保持必要澄清；跨阶段请求分别回答投诉与商品能力问题；Afterpay 捐赠退款保留未来办理措辞，并明确不能确认剩余分期已取消。否定句和分期状态不再被后端误判为已经安排退款或已经取消订单。

回放证据保存在 `storage/app/ticket-draft-replays/investigation-v2-acceptance.json`、`investigation-v2-boundaries.json` 和 `investigation-v2-final-boundaries.json`。前两份包含修复前结果；W 系列以 boundaries 的定向重跑为准，跨阶段请求以 final-boundaries 为准。默认入口不自动切换，需完成完整回放和人工审核后按上述配置启用。

### 回应判断增量（2026-10-22）

详见 [回应判断架构复核](../architecture/ticket-response-assessment.md)。v2 评估输出增加 `case_progress` 与 `response_decision`。实质回复和简短确认继续保存待人工审核的本地草稿；暂不回复经过独立审核后，保存 `draft_status=no_reply`，`draft_id=null`。审核不通过保存 `needs_review`，不创建空邮件、不关闭 Ticket、不自动创建提醒。

模板之外，后台显示当前进度、等待对象、下一步及关联会话来源。新消息或关联历史变化后，旧草稿和旧等待建议均显示过期。关联指南仅是历史证据，当前指南仍须客服明确填写。

冻结回放现在为 78 条。定向实测记录：`storage/app/ticket-draft-replays/response-assessment-v2.json`（四种新增场景）及 `response-assessment-cancellation.json`（取消回归）。回放的 `prior_agent_reply` 表示本次客户回复之前的已发信；原有 `last_agent_reply` 继续表示客户问题之后、等待客服补充指南的跟进场景。

增加门禁指标 `response_decision_error_rate=0`，使用固定案例预期判断，不依赖模型自报审核通过。正式发布仍须覆盖全部案例并完成人工事实/政策审核。
