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

当前只支持：

- `production_order_status`：当前订单及生产进度；
- `logistics_tracking_delivery`：当前发货、物流和交付进度。

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

Ticket Workflow 使用独立的 50 条固定回放集：

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

在 `/admin/workflow-replay-reviews` 逐条审核事实错误和 Policy 违规。全部 50 条完成审核后才可运行门禁。门禁要求预测与人工审核覆盖率均为 100%，Workflow 失败、事实错误、Policy 违规、隐私泄露、虚构后续动作和错误放行均为 0，漏意图率不超过 2%。

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

1. 50 条固定回放，覆盖订单进度、未发货、已发货、物流异常、多意图、缺失订单号、多订单号、身份不匹配和不支持的取消/退款请求；
2. 人工逐条审核 Fact 错误、Policy 违规、隐私泄露、遗漏意图、虚构后续动作和是否可直接采用；
3. Workflow 失败、Fact 错误、Policy 违规、隐私泄露和虚构动作均为 0；
4. 必须阻断的案例全部阻断；
5. 完成当前活跃 Ticket 的 shadow 验收，仍不发送 HelpDesk。

HelpDesk Preview/Confirm/Send 是后续独立发布项；没有确认 API 能力前，不应把本地 Draft 状态解释为已发送。
