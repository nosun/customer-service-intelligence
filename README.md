# Customer Service Intelligence

基于 Laravel 13 的本地客服邮件采集与分析 MVP。当前只读取 Gmail 邮件，不发送邮件，也不执行退款、取消、改地址或订单修改。

## 环境

- PHP 8.4+
- Composer
- SQLite
- Google Workspace CLI (`gws`，仅真实 Gmail 同步时需要)

## 初始化

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
chmod 600 database/database.sqlite
php artisan migrate
```

数据库默认保存在 `database/database.sqlite`，不会提交到 Git。

## 管理后台

首次初始化数据库后，创建管理员账号：

```bash
php artisan admin:create-user
```

启动项目后访问 `/admin`。Ticket 后台位于 `/admin/conversations`，支持列表、关键词与字段筛选、详情查看，以及按时间顺序查看消息和状态事件。当前后台只读，避免本地修改被下一次 HelpDesk 同步覆盖。

## 使用测试样本验证

```bash
php artisan gmail:sync \
  --mailbox=support@dressafford-service.com \
  --fixture=tests/fixtures/gmail-threads.json

php artisan gmail:analyze

php artisan gmail:review-export

php artisan knowledge:export \
  --output=storage/app/private/demo-candidate-reply-pairs.csv

php artisan test
```

`gmail:sync` 可以安全重复执行；同一 Gmail Thread 和 Message 会更新，不会重复创建。

## Gmail 授权完成后的只读同步

首次授权由邮箱账号持有人执行：

```bash
gws auth login --readonly --services gmail
gws auth status
```

然后执行：

```bash
php artisan gmail:sync \
  --mailbox=support@dressafford-service.com \
  --query='in:anywhere' \
  --page-limit=10
```

如果本机需要隔离多个 Gmail 账号，请分别指定配置目录：

```bash
php artisan gmail:sync \
  --mailbox=support@dressafford-service.com \
  --gws-config-dir="$PWD/storage/app/private/gws/dressafford" \
  --page-limit=2
```

第一次真实同步建议先限制日期或 Gmail 标签，并把 `--page-limit` 设为较小值。

`--page-limit` 限制 Gmail 结果页数，每页最多 100 个 Thread；它不代表只同步一个 Thread。首次联调可以额外使用 `--thread-limit=3`，确认流程正常后再移除该参数。

网络中断时，命令会自动重试 3 次。如果仍然失败，使用完全相同的查询参数并追加 `--resume`，即可跳过已经完整保存的 Thread，从断点继续：

```bash
php artisan gmail:sync \
  --mailbox=support@dressafford-service.com \
  --query='in:sent newer_than:180d' \
  --page-limit=3 \
  --thread-limit=50 \
  --resume
```

`--resume` 只应用于中断任务。日常增量同步不要默认使用它，因为已存在的 Thread 可能产生了新回复，需要重新读取并更新。

## 当前分析能力

`gmail:analyze` 使用可审计的关键词规则完成初步分类，并提取“客户来信 → 客服回复”的候选回复对。结果只是待审核的知识素材，不会自动成为 SOP。

`gmail:review-export` 会重新分析当前入站邮件，过滤系统通知和营销邮件，提取问题类型、紧急程度与订单号，并在 `storage/app/private/reports` 生成待人工审核的 CSV 和汇总 JSON。CSV 会隐藏正文中的邮箱和电话号码，文件权限为 `600`。

当前分类：

- `presales`
- `production`
- `logistics`
- `after_sales`
- `unknown`

`knowledge:export` 将候选回复对导出为 CSV，供人工审核和后续规则归纳。邮件模板 CSV 暂不预设固定格式；拿到真实文件后，再按实际表头编写导入映射，避免丢失信息。

## HelpDesk 只读采样

在 HelpDesk Developer Console 创建 Personal Access Token 后，在 `.env` 中配置：

```dotenv
HELPDESK_ACCOUNT_ID=
HELPDESK_PERSONAL_ACCESS_TOKEN=
```

先采集少量 Ticket 验证真实字段：

```bash
php artisan helpdesk:sample --silo=tickets --status=open --limit=20
```

样本默认保存在 `storage/app/private/helpdesk/ticket-sample.json`，权限为 `600`。该命令只读取 HelpDesk，不修改 Ticket，也不写入当前业务表。数据库结构会在真实样本核对完成后再确定。

将 Ticket 幂等同步到统一渠道表：

```bash
php artisan helpdesk:sync --silo=tickets --status=open --limit=100
```

首次完整采集主 Ticket 列表时，按全部业务状态依次执行：

```bash
for ticket_state in open pending onhold solved closed; do
  php artisan helpdesk:sync \
    --silo=tickets \
    --status="$ticket_state" \
    --limit=10000 \
    --page-size=100
done
```

命令按 Ticket、Event 和 Attachment 外部 ID 幂等更新，可安全重复执行。大量历史数据按 API 页逐页写入，不会一次全部载入内存。`archive`、`spam` 和 `trash` 需要通过 `--silo` 单独采集。

首次全量完成后，使用 `updatedAt + Ticket ID` 游标增量同步：

```bash
php artisan helpdesk:sync-incremental
```

核对 API 报告总数与本地四个 silo、五种状态的记录数：

```bash
php artisan helpdesk:audit
```

Laravel Scheduler 每 10 分钟同步活跃 Ticket，每小时同步全部目录，并在每天 02:30 重新生成 `tickets + archive` 的规则分类和回复候选：

```bash
php artisan schedule:list
```

服务器需要每分钟调用一次 Laravel Scheduler：

```cron
* * * * * cd /path/to/customer-service-intelligence && php artisan schedule:run >> /dev/null 2>&1
```

当前 Mac 使用仓库内的 LaunchAgent 配置持续运行 Scheduler：

```bash
cp ops/macos/com.dressafford.customer-service-intelligence.scheduler.plist \
  ~/Library/LaunchAgents/
launchctl bootstrap gui/$(id -u) \
  ~/Library/LaunchAgents/com.dressafford.customer-service-intelligence.scheduler.plist
```

手动运行 HelpDesk 分类和回复候选提取：

```bash
php artisan helpdesk:analyze
```

分析结果同时保存三个层级：客户消息分类、Ticket 主分类与多标签、客户消息到下一条客服回复的候选对。Ticket 可按问题类型、紧急程度、是否多问题、是否需要复核、是否缺少回复候选进行研究。

## MiniMax 决策案例解析

Decision Case 流程使用 MiniMax 的 OpenAI 兼容接口逐张读取 HelpDesk Ticket，提取一条主案例及多个有证据的决策节点。在本地 `.env` 中配置：

```dotenv
MINIMAX_API_KEY=
MINIMAX_API_URL=https://api.minimax.io/v1
MINIMAX_MODEL=MiniMax-M3
MINIMAX_TIMEOUT=180
MINIMAX_MAX_COMPLETION_TOKENS=12000
```

API Key 只保存在本地环境文件。运行命令会把所选 Ticket 的公开客户和客服消息发送给 MiniMax 进行语义分析；私有消息、spam 和 trash 不进入该流程。

先运行规则分析，再选择一张 Ticket 联调：

```bash
php artisan helpdesk:analyze
php artisan helpdesk:cases:build --ticket-id=HELPDESK_TICKET_ID
```

按问题类型分批处理：

```bash
php artisan helpdesk:cases:build \
  --problem-type=production_cancellation

php artisan helpdesk:cases:build \
  --problem-type=after_sales_return_refund \
  --limit=100
```

每次调用保存模型、Prompt 版本、输入哈希、结构化输出、Token 用量、耗时和错误。相同输入重复运行会跳过；人工已经确认或修正的案例不会被自动重建覆盖。使用 `--force` 只会重新分析尚未人工审核的案例。

命令会先显示匹配数和实际处理数，然后逐张输出当前进度、Ticket ID、处理状态、节点数、API 耗时与 Token 用量；结束时汇总完成、跳过、失败、总节点、API 调用、Token 和平均耗时。输出不包含客户正文。

AI 输出必须引用当前 Ticket 的原始事件 ID，并通过本地结构和证据校验后才会写入 `decision_cases`、`decision_nodes` 和 `decision_node_evidence`。这些结果仍是候选案例，不会自动成为客服政策或触发外部业务动作。

一个 HelpDesk License 同时包含 Dressafford 与 Ellenbridals。Conversation 优先按当前 Team 识别站点，再使用来源 URL 和消息中的品牌证据；证据不足时保留 `site_key = null`，不强行归属。

也可以先导入本地样本验证，不调用外部 API：

```bash
php artisan helpdesk:sync \
  --fixture=storage/app/private/helpdesk/ticket-sample.json
```

## ChatBot 只读采样

ChatBot.com 与 HelpDesk 使用不同的凭据。进入 ChatBot 的 Developer 设置页创建 Developer Access Token，并只在本地 `.env` 中配置：

```dotenv
CHATBOT_ACCOUNT_ID=primary
CHATBOT_DEVELOPER_ACCESS_TOKEN=
CHATBOT_API_URL=https://api.chatbot.com
```

采集 Story 完整 JSON、最近聊天会话和未匹配训练短语：

```bash
php artisan chatbot:sample --chat-limit=20
```

样本保存在 `storage/app/private/chatbot/api-sample.json`，权限为 `600`。Story 详情包含拖拽编辑器生成的 `diagram` 树，可用于读取和备份 Bot 运行逻辑。当前官方 API 只公开 Story 名称和描述的更新接口，没有公开写入 `diagram` 的接口，因此本项目暂不通过 API 修改 Bot 流程。

ChatBot 的聊天归档覆盖 Bot 全部会话；HelpDesk 中只能看到转成 Ticket 的聊天。两者需要用 ChatBot Chat ID、LiveChat 引用 ID 和时间等证据做关联，不能假设每个 Chat 都有 Ticket。

真实字段确认后，将 Story 版本和 Chat 写入专用表：

```bash
php artisan chatbot:sync \
  --account=primary \
  --site-key=dressafford.com \
  --chat-limit=200
```

每个 Story 的最新完整 JSON 同时下载到 `storage/app/private/chatbot/stories/{storyId}.json`，历史变更按内容哈希保存在 `chatbot_story_snapshots`。Chat 使用独立的 `chatbot_chats` 和 `chatbot_chat_events`，通过 `chatbot_ticket_links` 与 HelpDesk Conversation 建立可审核关联。

Chat 默认标记为 `unreviewed`。保留策略使用 `retention_status`、`retention_reason` 和 `purge_after` 记录，先区分有效问题、可优化 Story 的失败会话、已关联 Ticket 和纯噪音，再启用定期清理；同步命令不会删除本地或 ChatBot.com 上的数据。

## 代码结构

- `app/Services/Gmail`：`gws` 对接、Gmail 内容解析和幂等同步
- `app/Services/HelpDesk`：HelpDesk Ticket API 只读采样
- `app/Services/ChatBot`：ChatBot Story、聊天归档和训练短语 API 只读采样
- `app/Services/AI`：MiniMax 等模型客户端
- `app/Services/DecisionCases`：Ticket 上下文、Prompt、输出校验和案例生成
- `app/Services/Analysis`：规则分类和候选回复对提取
- `app/Console/Commands`：同步、分析和导出命令
- `app/Models`：邮件及知识候选数据模型
- `database/migrations`：SQLite/MySQL 可迁移的数据结构
- `tests/Feature/GmailWorkflowTest.php`：完整样本工作流回归测试
