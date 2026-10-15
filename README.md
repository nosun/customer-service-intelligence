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

`knowledge:export` 将候选回复对导出为 CSV，供人工审核和后续规则归纳。

邮件模板可以从 `docs/email.csv` 导入本地知识库：

```bash
php artisan knowledge:import-email-templates
```

导入按 `source + eid` 幂等更新，可在 CSV 增加或修改模板后重复执行。`name`、`type`、`description`、`title`、`content`、`status` 会映射到模板字段，完整 CSV 原始行同时保存在 `raw_row` 中。

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

## 通用知识研究批次

通用研究流程复用 Decision Case，把最近 180 天的 Ticket 以小批量方式拆成 Episode，再归纳分类、决策树、回复策略、操作步骤和待业务确认问题。默认每批 10 张：

```bash
php artisan helpdesk:research:run --limit=10 --since-days=180 --batch=pilot-001
```

若单张输出未通过证据校验，Decision Case 会自动要求模型修正一次。批次结束后仍失败的 Ticket 可以定向重试，不会混入新 Ticket：

```bash
php artisan helpdesk:research:run \
  --limit=10 \
  --batch=pilot-001-retry \
  --retry-batch=pilot-001
```

选择器会在售前、下单、生产、物流、售后、争议和未知分类之间尽量分层抽样；没有旧规则分类的 Ticket 也可以进入研究。已经在成功批次中处理过的 Ticket 不会重复选择，失败项可以在后续批次重试。每张 Ticket 仍由 `DecisionCase` 保存一条主案例，并以多个 `DecisionNode` 表达不同诉求或处理阶段。

每个批次保存：

- 选中的 Ticket 与逐张处理状态；
- 带原始事件证据的 Decision Node；
- 分类和场景候选；
- 所需事实、决策树、回复策略、候选模板与后续操作；
- 禁止承诺、不确定性和需要业务方确认的问题；
- 模型、Prompt 版本、结构化输出和 Token 用量。

批次报告写入 `storage/app/private/knowledge-research/{batch}.md`；累计知识库候选写入 `storage/app/private/knowledge-research/knowledge-base.md`。也可以单独重新生成累计报告：

```bash
php artisan helpdesk:research:report
```

如果 Ticket 拆分已经成功、但跨案例归纳输出未通过结构校验，可以只重跑归纳，不重复分析 Ticket：

```bash
php artisan helpdesk:research:synthesize --batch=pilot-001
```

所有自动归纳内容默认为 `observed/candidate`。历史客服做法不会自动成为政策；只有经过业务确认的 `verified` 知识才能进入稳定流程，涉及退款、取消、改订单等写操作时仍需独立审批、幂等和结果回查。

业务方审核某个场景后，显式发布到正式知识表；发布 `verified` 必须记录审核管理员：

```bash
php artisan helpdesk:research:publish \
  --scenario=production_status_inquiry \
  --status=verified \
  --reviewed-by=1
```

发布会保留来源批次、Decision Node、知识版本、决策树、回复策略和禁止承诺。后续研究批次只允许把 `verified` 知识作为政策依据。

一次确认多条业务问题时，可以准备本地 JSON 文件，通过事务批量关闭问题并发布知识；其中任一条不合法时整批不写入：

```bash
php artisan helpdesk:research:confirm \
  storage/app/private/knowledge-research/confirmations/example.json \
  --reviewed-by=1
```

完整批次流程和成熟度门槛见 `docs/discovery/14-general-knowledge-research-workflow.md`。

## 取消案例业务结果核验

console-new 使用只读 Admin API 提供订单、取消状态和退款事实。在 console-new 后台为本项目创建专用客户端，Token 只授予 `order.read`，然后在本地 `.env` 配置：

```dotenv
CONSOLE_NEW_API_URL=https://console.example.com/api/admin
CONSOLE_NEW_API_TOKEN=
CONSOLE_NEW_API_TIMEOUT=15
```

不要授予 `order.update`、`order.refund.create` 或其他写权限。若客户端启用了 IP 白名单，还需加入本项目运行机器的出口 IP。

可从 Ticket 详情点击“只读查询 console-new”，也可逐张运行：

```bash
php artisan helpdesk:cancellations:verify --conversation-id=2334
```

批量命令默认只处理一张取消 Ticket；确认单张结果后再逐步扩大：

```bash
php artisan helpdesk:cancellations:verify --limit=20
```

已经存在成功核验快照的 Ticket 默认跳过，因此重复批量运行会继续处理下一批。需要刷新已有结果时显式添加 `--force`：

```bash
php artisan helpdesk:cancellations:verify --conversation-id=2334 --force
```

系统会从 Ticket 提取订单号；识别出多个订单号时不会猜测，单张核验可用 `--order-no=` 明确指定。每次实际查询保存独立的本地审计快照，只保留取消分析需要的订单、状态、金额、商品、物流和退款字段，不保存 console-new 返回的地址、电话等无关信息。业务系统事实不会覆盖 Ticket 历史沟通或人工审核结论；当前取消政策的计时口径和历史版本仍需人工确认。

### 取消知识研究

取消场景不再维护独立的筛选、Episode、模板候选和知识升级流程；统一使用上文的 Decision Case 与通用知识研究工作流。console-new 接入只负责保存订单、取消和退款的只读事实快照，不会自动修改 Decision Case 或把历史处理升级为政策。

早期取消专项研究的本地数据已经冻结归档在 `storage/app/private/cancellation-research/archive-v1-2026-10-15.sqlite`，仅用于历史审计和回归比较；文件包含客户沟通数据，不得提交或外发。研究结论保留在 `docs/policies/dressafford-whole-order-cancellation-flow-v2.md`，政策原文依据保留在 `docs/policies/dressafford-cancellation-and-return-policy.md`。正式决策和回复策略以经过确认的 `KnowledgeArticle` 为唯一知识来源。

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

首次抓取全部可访问历史时执行：

```bash
php artisan chatbot:sync --all
```

完整抓取每页落库并保存检查点，中断后重复执行同一命令会从检查点继续。Laravel Scheduler 每 6 小时重读最近 80 条 Chat，通过外部 Chat ID 幂等更新近期新增或变化的会话。

每个 Story 的最新完整 JSON 同时下载到 `storage/app/private/chatbot/stories/{storyId}.json`，历史变更按内容哈希保存在 `chatbot_story_snapshots`。Chat 使用独立的 `chatbot_chats` 和 `chatbot_chat_events`，通过 `chatbot_ticket_links` 与 HelpDesk Conversation 建立可审核关联。

登录后台后访问 `/admin/chatbot-chats` 可以按 Story、来源、保留状态和关键词筛选 Chat；详情页展示用户与 Bot 的消息时间线、会话属性和已关联的 HelpDesk Ticket。

Chat 默认标记为 `unreviewed`。保留策略使用 `retention_status`、`retention_reason` 和 `purge_after` 记录，先区分有效问题、可优化 Story 的失败会话、已关联 Ticket 和纯噪音，再启用定期清理；同步命令不会删除本地或 ChatBot.com 上的数据。

## 人工标注基准集

从已经完成规则分析且存在回复候选的 HelpDesk Ticket 中，按售前、生产、物流、售后和未知五类确定性分层抽样：

```bash
php artisan benchmark:sample --count=10 --dataset=v1-pilot --split=pilot
```

相同数据集版本重复运行不会重复选择同一 Ticket，只会从尚未入选的候选中新增案例。登录后台访问 `/admin/benchmark-cases`，先在完整时间线上切分决策阶段，再进入阶段盲标。盲标只显示截至触发客户消息的上下文；提交个人标注后，才显示历史客服回复用于对照。

阶段标注覆盖生命周期、客户目标、意图、事实、决策、回复策略、业务动作、依赖知识、知识缺口、禁止承诺、理想回复与自动化等级。标注人可以关联现有知识卡，或者创建 `observed` 知识候选；只有经过业务确认的 `verified` 知识才能支撑自动发送。

标注定义、自动化边界、双人标注与裁决规则见 `docs/benchmark/annotation-guideline-v1.md`，案例 #1 的两阶段样板见 `docs/benchmark/case-1-stage-annotation-example.md`。抽样和标注只写入本地数据库，不发送邮件、不调用 AI 或站点业务 API。

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
