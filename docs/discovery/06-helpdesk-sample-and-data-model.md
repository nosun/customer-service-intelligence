# HelpDesk 样本与数据库建设建议

## 真实样本结论

2026-09-30 已使用 HelpDesk API v1 完成只读采样。原始 JSON 仅保存在 `storage/app/private/helpdesk/`，文件权限为 `600`，不提交到 Git。

| Ticket 状态 | API 报告总数 | 本次样本数 |
| --- | ---: | ---: |
| open | 20 | 20 |
| solved | 108 | 20 |
| closed | 1 | 1 |

41 个 Ticket 样本包含 244 个事件：

- 115 个消息事件。
- 77 个状态变更事件。
- 29 个分配事件。
- 23 个附件事件，共 27 个文件。
- 事件作者包括客户和客服，能够建立可靠的“客户问题 → 客服回复”候选对。
- 40 个 Ticket 来源为 email，1 个来源为 integration/chatbot。
- 当前样本没有标签、自定义字段、评分或私有消息；这只能描述样本，不能认定账号全量数据不存在这些字段。

HelpDesk Ticket、事件和附件的数据完整度足以支持客服问题分析、回复知识提取、处理过程还原和后续 Case 建设。

## 首次完整采集结果

2026-09-30 已对 `tickets`、`archive`、`spam`、`trash` 四个 silo 的全部业务状态执行分页采集，并写入本地统一渠道表：

| silo | open | pending | onhold | solved | closed | 合计 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| tickets | 20 | 286 | 0 | 108 | 1 | 415 |
| archive | 102 | 949 | 0 | 4,302 | 22 | 5,375 |
| spam | 3,998 | 0 | 0 | 0 | 0 | 3,998 |
| trash | 142 | 0 | 0 | 0 | 0 | 142 |
| 合计 | 4,262 | 1,235 | 0 | 4,410 | 23 | 9,930 |

API 总数审计已覆盖四个 silo 与五种状态，20 个组合全部与本地数量一致。重复导入 open 样本后，Conversation、Event 和 Attachment 数量均未增长，幂等约束生效。后续分析默认排除 `spam` 和 `trash`，但原始事实仍按 silo 保留。

## 站点识别

同一个 HelpDesk License 中确认存在两个站点：

- `Dressafford` Team，以及 `dressafford.com` 来源和消息证据；
- `ellen bridals-桌面` Team，以及 `ellenbridals.com` 来源和消息证据。

当前识别为 Dressafford 7,164 个、Ellenbridals 463 个；另有 2,303 个 Ticket 位于 `General` 等公共 Team 且缺少唯一品牌证据，保留为 unknown。不能仅凭同一 HelpDesk 账号将这些 Ticket 全部归到 Dressafford。

## 建库原则

数据库分成“渠道原始事实”和“客服业务语义”两层。渠道层忠实保存 HelpDesk/Gmail 数据；业务层再组织 Customer、Case、订单关联、知识和 AI 结果。这样可以避免把 HelpDesk Ticket 直接等同于一个业务 Case，也避免未来接入新渠道时重复建表。

Token 不进入业务数据库，只保存环境变量或凭据管理系统中的引用。

## 第一阶段：统一渠道数据

### channel_accounts

保存一个具体的 Gmail 或 HelpDesk 账号。

主要字段：

- `channel`：gmail、helpdesk。
- `site_key`：例如 dressafford.com。
- `external_account_id`：HelpDesk license/account ID 或 Gmail 邮箱地址。
- `display_name`。
- `credential_reference`：凭据引用，不保存 Token。
- `last_synced_at`、`sync_status`、`sync_error`。
- 唯一键：`channel + external_account_id`。

### conversations

一条渠道会话。HelpDesk 中对应 Ticket，Gmail 中对应 Thread。

主要字段：

- `channel_account_id`。
- `external_id`、`external_short_id`。
- `subject`、`status`、`priority`、`silo`。
- `source_type`、`source_detail`。
- `requester_snapshot`、`assignment_snapshot`。
- `created_at_external`、`updated_at_external`、`last_message_at`。
- `raw_payload`：保留暂未建模的 Ticket 字段；Event 列表拆分保存，不在这里重复存储。
- 唯一键：`channel_account_id + external_id`。
- 索引：`status`、`last_message_at`、`updated_at_external`。

### conversation_events

保存不可变的消息、状态、分配和附件事件。事件顺序以外部时间和外部 ID 确定。

主要字段：

- `conversation_id`、`external_id`、`event_type`、`occurred_at`。
- `author_type`、`external_author_id`、`author_name`、`author_email`。
- `is_private`。
- `body_text`、`rich_text_html`、`rich_text_payload`。
- `status_value`、`assignment_snapshot`、`source_snapshot`。
- `raw_payload`、`content_hash`。
- 唯一键：`conversation_id + external_id`。
- 索引：`conversation_id + occurred_at`、`author_type`、`event_type`。

### conversation_attachments

附件属于事件，不直接属于 Ticket。

主要字段：

- `conversation_event_id`。
- `external_token`、`filename`、`mime_type`、`size_bytes`。
- `content_id`、`sha256`、`remote_url`。
- `local_path`、`download_status`。

远端 URL 可能失效，因此不能作为附件的永久标识；优先使用外部 token 和 sha256。

### channel_sync_runs

记录每次同步的范围和结果。

主要字段：

- `channel_account_id`、`source_resource`。
- `cursor_value`、`cursor_external_id`。
- `filter_snapshot`。
- `status`、`records_seen`、`records_saved`、`error_message`。
- `started_at`、`finished_at`。

## 第二阶段：客服业务数据

### customers / customer_identities

`customers` 表示统一客户；`customer_identities` 保存 email、phone、HelpDesk customer ID、站点用户 ID 等身份。身份合并必须保留来源并允许人工撤销。

### cases / case_conversations

Case 表示一个需要解决的业务问题。一个 Ticket 可能包含多个问题，因此使用关联表连接 Case 和 Conversation，不在 conversations 上直接固定单一 case_id。

Case 保存分类、问题类型、状态、优先级、风险、负责人、SLA、摘要、解决结果和人工审核状态。

### external_entity_links

关联订单、订单项、生产工单、运单、退款和投诉。仅保存来源系统、实体类型、外部 ID、读取时间及必要显示快照；订单事实仍由现有业务系统负责。

### case_actions / case_outcomes

分别记录客服做过的动作和最终结果，用于建立 SOP、分析处理效果和审计高风险操作。

## 第三阶段：知识与 AI

- `knowledge_items`：draft、reviewed、approved、retired 生命周期。
- `reply_candidates`：从真实客户消息和客服回复生成，必须人工审核后才能成为知识。
- `ai_runs`：记录模型、Prompt 版本、输入证据引用、结构化输出、成本和人工修订。

## 与现有 Gmail 表的关系

现有 `mailboxes`、`gmail_threads`、`messages` 和 `attachments` 已承载 Gmail MVP 数据。下一步不直接删除或重写这些表：

1. 先为 HelpDesk 实现统一渠道表和幂等导入。
2. 用适配层把 Gmail 与 HelpDesk 转换成相同的 Conversation/Event 语义。
3. 对比统一表与现有 Gmail 分析结果。
4. 验证完成后，再决定迁移历史 Gmail 数据或保留来源表作为原始落地区。

## 后续建设顺序与验证标准

1. 建立客户身份和 Case 关联；人工审核至少 20 个跨消息、多轮回复样本。
2. 生成回复候选；确认客户/客服方向、私有消息排除和附件引用准确。
3. 接入订单、生产和物流只读查询后，再建设 Case 动作、结果、知识和 AI 表。

在第一阶段验收前，不执行 HelpDesk 写操作，不把历史客服回复自动提升为 Approved 知识。

## 数据库选择

当前阶段继续使用 SQLite。采集程序在单机串行运行，当前约有一万个 Ticket、四万多个事件和两千多条附件元数据，SQLite 足以支撑采集、分类、人工复核和早期报表，并且无需维护独立数据库服务。

满足以下任一条件时迁移到 MySQL：

- Web、队列和定时采集开始并发写入；
- 多人或多台服务需要同时访问数据库；
- 出现持续的 `database is locked`，缩短事务后仍不能解决；
- 数据规模或查询耗时已经影响同步和报表，例如事件达到百万级且常用查询无法通过索引满足；
- 需要 MySQL 的在线备份、复制、权限隔离或高可用能力。

表结构使用 Laravel Migration、普通字段、JSON 和联合唯一索引，可直接在 SQLite 与 MySQL 之间迁移。切换时先停止写入，导出并校验各表数量，再修改 `DB_CONNECTION` 执行迁移和导入。

## 当前采集落地

1. 已用本地脱敏 Fixture 验证字段映射和幂等约束。
2. 已完成四个 silo、五种状态的首次全量采集。
3. 已对数据库执行完整性、外键和重复键检查。
4. 已使用 `updatedAt + Ticket ID` 建立每个 silo/status 的增量游标，并配置每小时同步。
5. 已对 `tickets + archive` 建立客户消息分类和回复候选提取，每天 02:30 重算。
6. Freshchat 接入时新增独立 API Client 和映射器，复用统一渠道表。
