# 第一阶段 Ticket 智能辅助：运行与验收

实现日期：2026-10-24。代码已实现，真实模型质量、线上队列及 WeKnora 实例仍需分别验收。

## 交付范围

复用现有 v2 调查、生成和独立复核。Laravel 负责自动派工、输入指纹与结果过期、人工反馈审计、知识审核和发布。没有增加另一条串行的完整 Agent 调查链；Agents SDK 保持候选，后续以同一批案例对照后替换调查节点。

Ticket 详情新增情况分析、处理意见，并连接现有邮件草稿和独立复核。没有安全新邮件时保留前两项，说明暂不回复或待补依据。列表显示待分析、调查中、可审核、需核实、失败和待更新。最近 20 次任务及最近 30 条反馈加载到详情，原始运行结果可展开查看；更早历史保存在数据库。

人工事实、纠正和个案决定只进入当前 Ticket。反馈具有操作人、时间、目标任务及原始结果快照；替换回复指南、人工保存和 AI 改稿都保留修改前后。个案决定不是已执行动作，不允许直接作为通用知识归纳。原样采用、编辑采用和拒绝继续使用原审核入口；不发送邮件，也不自动执行订单业务动作。

## 数据库与运行条件

先在目标环境检查现有迁移状态。此次新增两项可独立部署的迁移：

```sh
php artisan migrate --path=database/migrations/2026_10_24_000063_create_ticket_assistance_feedback.php
php artisan migrate --path=database/migrations/2026_10_24_000064_create_knowledge_publications.php
```

新代码读取反馈表，因此应在切换应用代码/worker 前完成兼容的加表迁移。不要为了本次上线无条件运行所有历史待执行迁移。输入指纹新增 feedback revision，升级前排空或停止旧工作流任务，避免旧输入结构的运行在中途被判为过期。

工作流 `work/ticket-draft-v2@2.0.0` 需在本地 registry 为 evaluating 或 active，并在实际 n8n 实例具备可用 webhook。开发环境可继续 evaluating 进行人工审核试验；不是正式采用认证。现有 n8n base URL、webhook token、内部 API token 和 v2 webhook 路径沿用现有配置。

队列 worker 与 Laravel scheduler 必须实际运行。默认数据库队列 retry_after=90 秒；新增派工 job timeout=30 秒，WeKnora 同步 job timeout=50 秒，尝试次数为 1。模型归纳先走手动 CLI，不放入这条短任务队列。

Mac 开发环境可使用仓库中的 `ops/macos/com.dressafford.customer-service-intelligence.worker.plist` 安装队列 worker，与现有 scheduler LaunchAgent 配合；worker 日志在 `storage/logs/worker.log` 和 `worker-error.log`。配置文件使用此仓库的绝对路径和 Homebrew PHP 8.4，其他机器需调整。

```sh
cp ops/macos/com.dressafford.customer-service-intelligence.worker.plist ~/Library/LaunchAgents/
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.dressafford.customer-service-intelligence.worker.plist
```

本地 `artisan serve` 是单进程服务，模型请求会占用 HTTP 处理进程。开发 compose 默认 `N8N_CONCURRENCY_PRODUCTION_LIMIT=1`，使生产 webhook 触发的执行逐个完成，避免多个流程的 start/policy 请求在模型调用后排队并超过 30 秒。此限制对同一 n8n 实例的其他生产工作流也生效，不限制手动编辑器执行。修改后须重新创建 n8n 容器；目标环境改用多进程 Web 服务后再按实际容量调整。批量入队上限与执行并发是不同设置，等待执行也计入 WorkItem 的 30 分钟截止时间，应控制积压并监测过期任务。

## 自动派工

默认 `TICKET_ASSISTANCE_ENABLED=false`。配置 `TICKET_ASSISTANCE_ACTOR_ID` 为专用有效管理员，默认每轮最多创建 20 个任务。开启后 scheduler 每分钟扫描 unresolved tickets，原有增量同步每 10 分钟取回新消息；这两者延迟分别计入端到端时延。

```dotenv
TICKET_ASSISTANCE_ENABLED=true
TICKET_ASSISTANCE_ACTOR_ID=1
TICKET_ASSISTANCE_BATCH_SIZE=20
```

先用单次进程开关做单 Ticket 验收，再决定是否持续开启；如使用配置缓存，环境覆盖需以实际配置装载结果为准：

```sh
TICKET_ASSISTANCE_ENABLED=true TICKET_ASSISTANCE_ACTOR_ID=1 php artisan tickets:assist --ticket=123 --limit=1
```

同输入跨操作人复用 queued/running/completed 任务。failed/cancelled 输入也不会在每次扫描重新创建；现有任务由原 reaper 有界恢复，最终失败后由客服明确点击重新生成。新消息、新个案反馈和依据变化允许新任务。创建任务按 conversation 行锁串行化；网络派工在提交后执行。派工投递失败由既有 reaper 恢复。有效的 30 分钟 v2 租约不会因为超过默认 10 分钟而被提前回收。

关闭/归档 Ticket 不自动派工。最新公开消息是客服回复且没有后续人工补充时跳过；之后补充事实/决定可以生成后续通知草稿。不自动轮询工厂进展或创建业务提醒。

## 知识候选与审核

客服保存反馈时勾选“可作为知识改进候选”。初期由负责人选择同主题、同站点的反馈 ID，手动运行归纳；`--feedback` 可重复，最多 30 条。输入只取脱敏后的反馈正文，不取整案原始快照。当前个案 decision、未选择的反馈和不同站点输入会被拒绝。

更新既有主题：

```sh
php artisan knowledge:synthesize-ticket-feedback 42 --feedback=101 --feedback=108 --actor=1
```

建立新主题：

```sh
php artisan knowledge:synthesize-ticket-feedback tracking_exception_review --title='物流异常核查流程' --site=test.example --feedback=101 --feedback=108 --actor=1
```

新主题先为 observed/v0，归纳只创建 draft/v1；不会伪造已发布初版。默认 manual_only，需负责人检查适用范围与自动化层级。失败可能留下无生效内容的 observed 主题，可继续归纳；不因此影响正式知识。

归纳使用现有 DraftCompletionClient 的模型供应商配置。模型输出不能改站点、自动化层级、知识状态、版本或绑定；只能提出受限内容字段的修订。生成期间正式版本发生变化时拒绝保存候选。新候选不会影响既有已审核规则。

在 `/admin/knowledge-articles/{id}` 查看字段差异、完整快照和 source_notes 中反馈 ID，回到对应 Ticket 核查原始证据，然后发布具体版本或驳回。需修改候选时可驳回后按修订内容重新创建下一版本。正式知识是否适用仍由消费绑定、站点和已有门禁决定。

旧 `helpdesk:research:publish` 遇到已启用版本管理的文章会拒绝覆盖，必须改用版本审核入口。旧命令对尚未进入版本管理的历史研究流程维持兼容。

## WeKnora 同步和检索

默认关闭。按实际实例配置两个独立 key：查询 key 仅 retrieve；发布 key 为限定目标库的 ingest。它们只供服务器请求使用，不进入模型上下文。

```dotenv
WEKNORA_BASE_URL=https://knowledge.example
WEKNORA_READ_KEY=
WEKNORA_WRITE_KEY=
WEKNORA_KNOWLEDGE_BASES='{"global":"kb-global","test.example":"kb-site"}'
WEKNORA_PUBLISH_ENABLED=true
WEKNORA_RETRIEVAL_ENABLED=true
```

站点文章必须匹配显式映射；没有站点映射不能回退到 global 写入。发布器仅导出当前 verified、具有人工审核者且内容等于已发布版本快照的文章；source_notes 和 Ticket 快照留在本地。检测到明显邮箱、电话或订单标识时拒绝导出，要求在审核版本中移除。自动检测不能替代人工对姓名、地址、个案例外的脱敏审核。

人工发布后提交短同步 job；手动重试与检查：

```sh
php artisan knowledge:sync-weknora 42
```

使用 Markdown manual create/update API；更新正文走 `/api/v1/knowledge/manual/{id}`。记录 article、KB、远端 ID、版本、正文及 hash。重复同步会读取远端并比较内容，相同内容不新增文档。HTTP 写入成功后仍为 indexing，须 GET 确认正文/目标库/解析完成，再用实际检索命中该文档的当前版本标记，才标为 published。scheduler 每 5 分钟检查 indexing/stale；最多 24 次未验证通过后标 failed，交人工诊断，不无限轮询。远端正文若既不等于本地批准稿、也不等于上次同步正文，标记 conflicted，保留远端编辑供人工核对。

新建超时、写入中进程退出或返回缺少远端 ID，状态为 uncertain/writing 时禁止盲目再 POST。先在 WeKnora 确认是否已经建立了对应正文的文档，取得 ID 后对账：

```sh
php artisan knowledge:sync-weknora 42 --adopt-id=REMOTE_KNOWLEDGE_ID
```

仅接受目标库和当前审核正文完全一致的文档。已知远端 ID 的更新超时可重新 GET 对账。解析失败显示 failed；处理实际解析故障后再运行同步检查。未核实远端结果前不要删除本地映射或手动重复新建。

v2 调查可用 WeKnora 对已发布映射进行语义检索，返回的文档只能筛选当前同站点、同 intent 绑定的已审核本地知识。实际完整规则仍从 Laravel 读取，避免截断命中替代政策全文。未知文档、其他站点、旧版本、deprecated/conflicted 和未审核候选不会进入这条通道。检索服务不可用时保留原本的本地正式知识流程，并记录技术错误。

停用知识后，本系统查询立即按现有 verified/绑定门禁排除它；本轮未实现自动物理删除 WeKnora 文档。其他直接使用同一知识库的客户端需独立处理撤回。更新正文的检索空窗及真实实例索引状态需另行验收。

## 首轮验收

先用固定合成案例检查物流、生产、退款/取消、缺证据投诉、不回复、客服回复后新进展、过期结果和工具失败。再小批量真实 Ticket 进行人工验收，记录三项产物正确性、遗漏、错误承诺、人工修改时间和模型成本；框架回归通过不代表已经达到实际业务可用率。

WeKnora 用独立测试主题验证：归纳→人工审阅→发布→解析→检索→下一次 Ticket 使用修订；重复同步只有一个远端文档，驳回稿无效，接口超时不重复新建。生产启用、真实邮件发送或订单业务写入不属于本次代码交付验收。

## 本轮实际验证记录

- 新增 27 个测试，覆盖自动派工去重/人工重试、新消息与反馈失效、个案隔离、客服回复后的跟进、AI/人工改稿历史、知识归纳与版本审核、WeKnora 创建/更新/超时对账/远端冲突/解析和检索验证。
- 全部 Laravel 测试：279 tests、1607 assertions，通过；Pint 与 `git diff --check` 通过。
- 确认开发环境使用工作区内 SQLite 后，仅执行本次 000063、000064 两个加表迁移，并验证两张表存在。未运行其他待执行迁移。
- 当前实际配置：自动派工、WeKnora 自动发布、WeKnora 检索均为关闭。未提交或部署代码；没有真实邮件发送、订单业务写入、真实模型请求或远端 WeKnora 写入。
- HTTP、模型与队列链路采用合成数据和 fake/mock 验证。真实模型建议质量、实际 n8n webhook、持续 worker/scheduler 运行，以及实际 WeKnora 索引与命中效果尚未完成验收。

## 2026-10-24 单次真实运行补充

以下结果补充上面的交付时记录；持续自动派工和 WeKnora 仍未启用。

- 现有 OmniRoute 模型配置可用，模型为 `codex/gpt-6.1-sol`，Node 和 Agents SDK 依赖已安装。分别运行 `tickets:agent-investigate` 的已签收未收到、运输中两份合成 fixture，实际模型调用均成功，耗时约 20 秒和 15 秒，各使用 2 次模型请求、3 次只读工具调用。两次结果均为 `needs_review`；这是要求人工复核的业务状态。SDK 仅读取冻结快照，不代表已查询实时物流。
- 使用单次进程配置开启派工，actor 为本地管理员 1，队列临时使用 sync，执行 `tickets:assist --ticket=12208 --limit=1`。未修改持久配置，也未开启全量扫描。真实 Ticket `94LOKQ` 创建 WorkItem 328，经 n8n execution 692 完成 `work/ticket-draft-v2@2.0.0`，从创建到生成草稿约 68 秒。草稿 242 为 `draft`，`requires_human_review=true`；无邮件发送和订单业务写入。
- 本例为婚纱加急售前咨询，产出情况分析、处理意见及英文草稿，引用 3 条业务知识和 1 条处理流程。建议确认目的地、11 月 11 日是发货还是收货期限，并区分加急生产与快递运输；没有承诺婚礼前送达。生产可行性、商品当前生产时间及加急费用仍为待核实项，本次没有实际完成商品页面查询或工厂调查。
- Agents SDK 实验与 v2 主工作流是两条独立验证路径。本轮真实 Ticket 使用 v2 主链，不能据此宣称 SDK 已接入自动派工。模型复核通过仅表示生成流程完成，仍需人工评估实际业务质量。
- 已验证单次模型及 n8n 链路；持续 scheduler、数据库 queue worker，以及实际 WeKnora 发布和检索仍需独立验收。

## 持续运行接通记录

用户开启自动派工后，实际读取配置为 enabled=true、actor_id=1、batch_size=20、database 队列。scheduler 在 08:28 UTC 自动创建 12 个任务，但数据库队列没有消费者。安装并启动上述 worker LaunchAgent，确认 launchctl 状态 running，队列投递成功。

首次同时投递 12 张时，本地单进程 HTTP 服务在模型调用期间阻塞，11 张任务的 30 秒内部请求超时。已将开发 n8n 的生产执行并发设为 1 并重新创建容器；原任务 329 完成，生成草稿 243。随后重新派工两张，确认 341 完成并生成草稿 244，342 在 341 完成时才开始，验证串行执行生效。其余 9 张超时案例已通过现有任务创建入口重新派工为 343–351，保留旧失败记录，不覆盖审计历史。后续任务结果以数据库和 Ticket 页面为准。

这些任务仍只生成本地分析、处理意见和待人工审核草稿。WeKnora 远端发布及检索尚未验收。
