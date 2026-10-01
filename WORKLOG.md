# Worklog

本文件记录已经落地并经过验证的阶段性工作。原始客户消息、API 凭据、本地数据库和分析报告保留在本机私有目录，不写入 Git。

## 2026-09-29 至 2026-10-01：多渠道采集、分析与案例学习基础

### Gmail

- 完成基于 Google Workspace CLI 的只读邮件同步，支持多邮箱独立授权目录、幂等写入、实时进度、失败重试和 `--resume` 断点继续。
- 修复格式化多行 JSON、NDJSON 分页以及 Unicode 内容的解析问题。
- 扩展规则分析：过滤系统与营销噪音，提取问题类型、紧急程度和订单号，生成脱敏人工审核 CSV 与汇总 JSON。
- 保留“客户问题到客服回复”为候选知识；当前 Gmail 历史邮件缺少可靠的往来关联时，不构造虚假回复对。

### HelpDesk

- 接入 HelpDesk API v1，完成 Ticket 样本读取、分页全量同步、四个 silo 采集、幂等导入和数量审计。
- 建立 `updatedAt + Ticket ID` 增量游标，并配置 Laravel Scheduler 每小时采集、每天重新分析。
- 建立统一渠道模型，保存 Ticket、事件、附件、同步运行和游标。
- 对 `tickets + archive` 生成问题分类、紧急度、多标签和回复候选；按 Team、来源 URL 和消息证据识别 DressAfford / EllenBridals，证据不足时保留未知站点。

### ChatBot

- 完成 ChatBot.com API 能力调研及只读客户端，能够读取 Story 列表与完整 `diagram`、聊天归档和未匹配训练短语。
- 建立 Story 快照、Chat/Event 和 HelpDesk Ticket 关联数据模型；同步过程不修改远端 Bot，也不自动删除会话。
- 形成 Alberich Bot 现状、多语言、Webhook、站点隔离和 AI 演进方案文档。

### 决策案例与 MiniMax

- 接入 MiniMax OpenAI 兼容接口，将每张 HelpDesk Ticket 转换为一条主案例和多个决策节点。
- Prompt 要求每个关键结论引用当前 Ticket 的事件证据；本地校验缺失事实、证据角色、枚举和风险等级。
- 保存模型、Prompt 版本、输入哈希、结构化输出、Token、耗时和失败原因；相同输入跳过，人工审核结果不被覆盖。
- 完善批处理输出，显示匹配数、逐 Ticket 进度、节点数、API 耗时、Token、失败原因和最终汇总。

### 管理后台

- 接入 Laravel-Admin，提供 `/admin/conversations` 只读 Ticket 列表与详情。
- 支持关键词、站点、状态、分类、渠道账户和时间筛选，并按时间展示消息及状态事件。
- 本地写操作保持关闭，避免管理后台修改被后续 HelpDesk 同步覆盖。

### 当前本地数据快照

以下数字仅用于记录 2026-10-01 的本机 SQLite 状态，不代表线上实时数据：

- Conversation：9,988（tickets 414、archive 5,383、spam 4,050、trash 141）。
- Decision Case：60；Decision Node：102。
- ChatBot 数据表已经建立；本地数据库尚未执行正式 ChatBot 全量同步。

### 验证与边界

- 31 个测试、148 个断言通过。
- Laravel Pint、Composer 配置、迁移状态、Admin 路由和 Git whitespace 检查通过。
- `.env`、`database/database.sqlite`、`storage/app/private`、生成报告和本地邮件模板源文件不提交。
- 规则分类和 AI 解析结果均为待审核候选，不能直接视为正式政策或触发退款、取消、改址等外部操作。

### 下一步

- 人工审核首批取消类 Decision Case，统一 `scenario_key`、节点切分和事实缺失表达。
- 增加本地案例审核界面，支持确认、修正、拒绝和证据回看。
- 对审核后的同类案例归纳典型情形、冲突做法、规则与处理流程。
- 执行 ChatBot 历史会话同步，并建立 Chat、Training 和 HelpDesk Ticket 的可审核关联。
