# n8n AI 工作流与 Temporal 演进方案

## 目标

在不推翻现有产品问答、Policy、事实接口和人工确认能力的前提下，使用 n8n 快速搭建可视化、可观察、可调整的 AI 工作流，优先解决以下问题：

1. Draft 质量不稳定；
2. 调整 Prompt、策略组合和 Review 流程需要修改较多 PHP 代码；
3. 缺少 Generator、Reviewer 和 Repair 的自动闭环；
4. 缺少不同模型和工作流版本的批量对比；
5. 未来需要扩展到客服 Ticket、工单和物流异常，并协调不同 Worker。

成熟后，将需要长期等待、强一致重试、跨 Worker 协作和关键业务写操作的流程逐步固化到 Temporal；n8n 继续用于 AI 实验、外部集成、通知和非关键自动化。

当前问答和草稿只是完整客服工作的约四分之一到三分之一。本方案不能把 `ProductQuestion` 当成未来所有流程的中心，而应从一开始保留邮件、Ticket、生产协作、物流异常、售后处理和跨 Worker 协调的扩展边界。

## 两个有联系的产品中心

### 工作中心

工作中心负责“把事情处理完”：

- 接收邮件、问答、Chat 和 Ticket；
- 创建或关联 Case；
- 分配 Work Item 和负责人；
- 获取商品、订单、生产和物流事实；
- 使用 verified Policy 和稳定工作流；
- 生成并审核客户回复；
- 提出、审批和执行受控业务动作；
- 记录 Outcome 和后续任务。

工作中心的 n8n Workflow 必须版本锁定、输入输出稳定、错误可恢复，并且默认人工审核。实验节点不能直接加入正在使用的工作流程。

### 分析中心

分析中心负责“让下一次处理得更好”：

- 历史数据分类、聚类和问题发现；
- 回复、动作和 Outcome 评估；
- Policy、决策树和 Action Flow 候选；
- Benchmark 和回放；
- 模型、Prompt、检索和 Reviewer 实验；
- 工作流瓶颈、失败模式和知识缺口分析。

分析中心可以快速迭代，但只能产出候选和评估结果，不能直接执行客户回复或订单写操作。

### 发布边界

分析结果进入工作中心必须经过：

```text
Evidence
  -> Candidate
  -> Automated Validation
  -> Human Review
  -> Versioned Publish
  -> Work Center Consumption
```

需要版本化发布的对象包括：

- Policy；
- Prompt；
- Workflow；
- Tool Contract；
- Fact Contract；
- Safety Rule；
- Benchmark。

工作中心保存每次实际使用的版本，避免分析中心修改后无法解释历史回复。

## 架构策略：先模块化，再物理拆分

第一阶段继续使用一个 Laravel 仓库和数据库，减少迁移成本，但建立明确模块边界：

```text
Work Center
  -> Cases / Channels / Drafts / Reviews / Actions

Analysis Center
  -> Analysis / Research / Benchmarks / Evaluations

Shared Kernel
  -> Contracts / Facts / Policies / Audit / Sites / AI Providers
```

边界要求：

- 工作中心不得直接读取分析批次内部表来决定客户回复；
- 分析中心通过只读 Snapshot 或事件获取工作数据；
- 工作中心只消费已发布版本；
- Shared Kernel 保持小而稳定，不放具体场景的 Prompt 和流程分支；
- 新代码必须明确属于 Work、Intelligence 或 Shared；
- 不为尚未出现的业务提前建立大型通用框架。

当发布节奏、权限、资源和扩容需求真正分离时，再将分析中心、AI Worker 或 Temporal Worker 独立部署。物理拆分不能早于契约稳定。

## 核心原则

### Laravel 仍是业务权威

Laravel 继续负责：

- 站点和加密凭据；
- 问答、商品、Ticket、订单引用和业务数据；
- verified Policy、证据和版本；
- 事实接口白名单；
- Draft、审核和执行审计；
- Preview、Confirm 和其他业务写操作；
- 权限、幂等和结果回查。

n8n 不直接访问业务数据库，不直接持有 tradeManager 或 console-new Token，不直接执行 Preview、Confirm、退款、取消或改单。

### n8n 是编排器，不是知识库

n8n 负责：

- Trigger、定时和 Webhook；
- 调用 Laravel 提供的受控内部 API；
- 调用不同 AI Provider；
- Draft、Review 和单次 Repair 编排；
- 批量回放和模型对比；
- 错误分支和通知；
- 可视化每个节点的输入输出。

Policy、事实和业务状态始终保存在 Laravel。

### n8n 工作流分区

n8n 中从第一天区分工作和分析工作流，避免再次混成一个大画布：

```text
work/qa-draft-v1
work/ticket-draft-v1
work/logistics-triage-v1

analysis/qa-policy-research-v1
analysis/draft-evaluation-v1
analysis/workflow-coverage-v1

shared/load-context-v1
shared/resolve-facts-v1
shared/search-policies-v1
shared/save-run-result-v1
```

建议用 n8n Project、Tag 和 Credential Scope 隔离：

- `work`：稳定版本、最小权限、严格发布；
- `analysis`：只读数据、允许实验、不能调用 Action API；
- `shared`：无业务判断的稳定连接器和子工作流。

工作和分析工作流不能共用拥有写权限的 Credential。

### Workflow Registry

Laravel 保存工作流注册信息，而不是只依赖 n8n 中的名称：

- `workflow_key`；
- `workflow_version`；
- 所属中心和业务域；
- n8n Workflow ID；
- 输入/输出 Schema 版本；
- 状态：`experimental / evaluating / active / deprecated`；
- 允许的 Fact 和 Action；
- 默认 Provider、模型和预算；
- 发布人和发布时间；
- 回放结果；
- 替代版本和停止日期。

只有 `active` 工作流可以处理工作中心 Work Item。

### 人工确认边界不变

第一阶段仅允许：

```text
n8n 生成本地 Draft
  -> Laravel 后台人工审核
  -> 人工 Preview
  -> 人工 Confirm
```

n8n 不自动发送回复。

## 总体架构

```text
tradeManager / console-new / HelpDesk / Mail / Chat
                         |
                         v
                 Work Center
        Case / Work Item / Draft / Review / Action
                         |
            Snapshot / Feedback / Outcome
                         v
                 Analysis Center
       Research / Policy / Benchmark / Evaluation
                         |
              Versioned Publish Gate
                         v
                  Shared Kernel
       Facts / Policies / Contracts / Audit / Sites
                         |
                         v
                 Work Center 消费

n8n Work Project                  n8n Analysis Project
- 稳定工作流                      - 实验与批处理
- Generator/Reviewer              - Policy Research
- 只调用受控 Work API             - Benchmark/Evaluation
          \                         /
           \---- Laravel Internal API ----/

未来：工作中心中需要长时间等待、多 Worker 协作和关键写操作的流程迁入 Temporal。
```

## 第一阶段：产品问答 Direct Draft PoC

### 范围

第一阶段只处理产品问答草稿，不处理：

- 自动 Preview 或 Confirm；
- 订单写操作；
- 退款、取消、补偿；
- 自动发布 Policy；
- Ticket 或物流异常的生产流程。

### 主工作流

建议 n8n 工作流名称：

```text
qa-draft-v1
```

流程：

```text
Manual / Webhook / Schedule Trigger
  -> 向 Laravel 申请一批 eligible questions
  -> Split In Batches
  -> 获取 Question Context
  -> AI Planner：识别请求和需要的事实
  -> Laravel Fact Gateway：获取允许的事实
  -> Laravel Policy Search：检索 verified policies
  -> AI Writer：生成规范英文 Draft
  -> AI Reviewer：检查完整性、事实、Policy 和安全
  -> Reviewer 通过？
       | yes -> 保存 Draft
       | no  -> AI Repair 一次 -> 再校验 -> 保存 generated/blocked
  -> 返回批次统计
  -> 有失败或 blocked 激增时通知
```

### Planner 输出契约

Planner 不直接生成回复，输出结构化计划：

```json
{
  "intents": ["ask_pockets", "request_add_pockets"],
  "requests": ["确认默认是否带口袋", "如果没有，说明能否增加"],
  "requested_fact_keys": [
    "product.sn",
    "product.attributes.pockets",
    "product.attributes.field_silhouette"
  ],
  "policy_queries": [
    "商品是否自带口袋",
    "增加口袋的适用款式和例外"
  ],
  "requires_private_reply": false,
  "risk_level": "low"
}
```

Laravel 必须校验 `requested_fact_keys`，模型不能任意访问数据库或内部 API。

### Writer 输入

Writer 获得：

- 已脱敏的客户问题；
- 当前商品 SN 和必要商品事实；
- 站点公开变量；
- 检索到的 verified Policy 完整内容及版本；
- 实时 ETA、配送或本地化事实；
- 缺失事实；
- 回复渠道约束；
- 禁止声明；
- 当前日期。

Writer 只输出规范英文。客户语言不是英语时，在英文完成并通过 Review 后再翻译。

### Writer 输出契约

```json
{
  "intents": ["ask_pockets", "request_add_pockets"],
  "selected_policy_versions": [
    {"id": 103, "version": 2},
    {"id": 104, "version": 1}
  ],
  "used_fact_keys": [
    "product.sn",
    "product.attributes.field_silhouette"
  ],
  "missing_critical_facts": [],
  "answer_en": "...",
  "recommended_visible": true,
  "confidence": 0.91,
  "safety_flags": []
}
```

### Reviewer 检查项

Reviewer 必须逐项输出：

- 是否覆盖客户的每个独立问题；
- 是否直接回答，而不是只描述内部缺失；
- 是否引用了不存在的事实；
- 是否把字段缺失解释为否定事实；
- 是否违反 Policy 的禁止声明；
- 是否无依据承诺定制、费用、Rush、交付、退款或补偿；
- 是否重复完整商品标题；
- 是否不必要地引导联系客服；
- 是否适合单轮公开问答；
- 是否泄露邮箱、订单号或私密订单信息；
- 是否需要进入 `blocked`。

输出示例：

```json
{
  "approved": false,
  "severity": "medium",
  "problems": [
    "只说明默认口袋属性缺失，没有应用已验证的增加口袋策略"
  ],
  "repair_instructions": [
    "保留默认配置未确认的透明说明",
    "根据 A-Line 和 verified request_add_pockets 补充免费增加及 Order Notes 指引"
  ],
  "must_block": false
}
```

最多允许一次 Repair。Repair 后仍不合格则保存为 `blocked`，不能无限循环。

## Laravel 内部 API 边界

n8n 不应直接调用数据库或站点 Admin API。需要在 Laravel 增加专用的、可审计的内部 API，建议使用独立 Service Token、IP/网络限制和请求 ID。

建议接口：

### 申请工作

```http
POST /api/internal/work-items/product-question-drafts/lease
```

请求：

```json
{
  "site_key": "dressafford",
  "limit": 10,
  "workflow_version": "qa-draft-v1"
}
```

返回本批 `work_item_id` 和问题引用，不返回站点 API Token。

### 获取上下文

```http
GET /api/internal/work-items/{work_item_id}/context
```

返回脱敏问题、商品引用、现有 Analysis、允许的站点公开变量和输入哈希。

### 获取事实

```http
POST /api/internal/work-items/{work_item_id}/facts/resolve
```

请求只能包含允许的事实 Key。Laravel 负责调用 tradeManager、console-new 或本地商品快照，并返回来源、捕获时间和响应哈希。

### 搜索 Policy

```http
POST /api/internal/work-items/{work_item_id}/policies/search
```

只返回适用站点的 `verified` Policy，包含文章 ID、版本、适用范围、规则、决策树、模板、禁止声明和升级条件。

### 保存 Draft

```http
POST /api/internal/work-items/{work_item_id}/drafts
```

Laravel 重新校验：

- Work Item Lease；
- 问题 Input Hash；
- Policy 状态和版本；
- Fact Key 和来源；
- 模板变量；
- 私密标识；
- `generated/blocked/failed` 状态；
- Provider、Model、Prompt 和 n8n Execution ID。

### 完成或失败

```http
POST /api/internal/work-items/{work_item_id}/complete
POST /api/internal/work-items/{work_item_id}/fail
```

用于释放 Lease、记录错误类型和决定是否重试。

实际路径可以调整，但必须保持这些职责边界。

## 幂等、重试和并发

每个 Work Item 至少包含：

- `work_item_id`；
- `source_type` 和 `source_id`；
- `input_hash`；
- `workflow_name` 和 `workflow_version`；
- `n8n_execution_id`；
- `lease_owner` 和 `lease_expires_at`；
- `attempt`；
- `status`；
- `started_at` 和 `finished_at`。

推荐幂等键：

```text
product_question_id + input_hash + workflow_version + provider + model
```

重试分类：

| 错误 | 处理 |
|---|---|
| 网络超时、429、临时 5xx | 指数退避重试 |
| 模型 JSON 不合法 | 将校验错误交给模型修正一次 |
| 商品或实时 API 不可用 | 保存 `blocked` 或延迟重试 |
| Policy 缺失 | 保存 `blocked`，进入知识缺口队列 |
| Reviewer 不通过 | Repair 一次 |
| 鉴权失败、Schema 不兼容 | 立即失败并通知，不自动无限重试 |

AI 调用重试必须有上限，避免重复费用。

## 凭据和数据安全

- tradeManager、console-new、HelpDesk 凭据继续只保存在 Laravel 配置或加密字段中。
- n8n 只保存访问 Laravel Internal API 和 AI Provider 所必需的 Credential。
- n8n Execution 中不得保留不必要的邮箱、订单号、电话或完整私密 Ticket 内容。
- Laravel 在输出上下文前完成脱敏和渠道权限判断。
- 公开问答不得调用私密订单工具。
- n8n 数据保留周期应明确配置；生产前验证执行数据清理策略。
- Prompt、Workflow Export 和 Credential 必须分离，Git 中不得提交 Secret。

## 回放和质量评估

上线自动批量 Draft 前，建立 50 至 100 条真实问题回放集：

- 高频单意图；
- 多意图问题；
- 商品事实缺失；
- 商品定制；
- ETA、Rush 和配送；
- 退货和售后；
- 私密订单问题；
- 必须 blocked 的风险案例。

同一批问题同时运行：

```text
当前 PHP Draft
n8n Workflow A + MiniMax
n8n Workflow A + 更强 Draft Model
n8n Workflow B + Reviewer/Repair
```

记录指标：

| 指标 | 含义 |
|---|---|
| `ready_rate` | 生成且未被安全阻止的比例 |
| `accept_as_is_rate` | 人工无需修改直接接受的比例 |
| `average_edit_distance` | 人工修改量 |
| `fact_error_rate` | 错误或虚构事实比例 |
| `policy_violation_rate` | 违反 verified Policy 的比例 |
| `false_block_rate` | 本可安全回答却被阻止的比例 |
| `missed_intent_rate` | 遗漏独立问题的比例 |
| `cost_per_draft` | 单条 Draft 模型成本 |
| `p50/p95_duration` | 生成耗时 |

历史 Dorris 回复只能作为 observed 示例，不能自动作为标准答案。Golden Answer 应来自人工确认回复或明确 verified Policy。

## 人工反馈闭环

后台审核时保存：

- 原始 Draft；
- 最终 Preview 文本；
- 自动计算的文本差异；
- 使用的 Policy 和事实；
- Reviewer 结论；
- 可选的修改原因；
- 最终是否 Confirm。

“修改原因”不设为必填字段。

定期由分析工作流归纳：

- 高频删除内容；
- 高频补充内容；
- 错误 Policy 选择；
- 缺失事实；
- 新 Policy 候选；
- Prompt 改进建议；
- 应加入回放集的失败案例。

分析结果只能产生候选，不自动修改 verified Policy 或生产 Prompt。

## 第二阶段：Policy 研究自动编排

建议 n8n 工作流：

```text
qa-policy-research-v1
```

流程：

```text
定时触发
  -> Laravel 同步近期问答
  -> 批量分类
  -> 与 verified policies 比较覆盖关系
  -> 已覆盖：记录 Coverage，不创建新 Policy
  -> 未覆盖/冲突：小批量聚类和归纳
  -> 创建 candidate
  -> 通知人工审核
```

该流程复用现有 Analysis、Policy Candidate、Evidence 和 Review，不在 n8n 中另建知识库。

人工只审核：

- 新场景；
- 新例外；
- 与 verified Policy 冲突；
- 事实接口无法覆盖的问题；
- 工厂能力待确认事项。

Policy 研究属于分析中心。n8n 只负责批次编排和模型调用，Laravel 继续保存 Evidence、Candidate、冲突、开放问题和发布版本。即使分析 Workflow 自身达到 `active`，它的输出仍然只是候选。

## 第三阶段：扩展 Worker 类型

n8n PoC 成熟后，可以沿用统一 Work Item 和 Worker Result 契约扩展：

### Customer Service Worker

- Ticket 意图和阶段识别；
- 查询订单、生产和物流事实；
- 组合 verified Policy；
- 生成私密客服回复；
- 提出但不执行退款、取消和补偿 Action Proposal。

### Logistics Worker

- 查询物流轨迹；
- 识别长时间未更新、退回、清关、签收争议；
- 创建调查和等待节点；
- 将调查结果返回关联 Ticket；
- 生成客户通知建议。

### Policy Worker

- 分析新增案例；
- 检查现有 Policy 覆盖；
- 产生 Policy 修订候选；
- 生成新的回放案例建议。

### Review Worker

- 跨类型检查事实、Policy、渠道、风险和完整性；
- 输出结构化 Repair Instructions；
- 无权执行最终业务动作。

### Worker 协调契约

不同 Worker 不直接互相调用内部节点，而是返回统一结果，由主流程决定下一步：

```json
{
  "work_item_id": "...",
  "worker_key": "logistics-triage",
  "worker_version": "v1",
  "status": "completed",
  "facts": [],
  "decisions": [],
  "drafts": [],
  "action_proposals": [],
  "next_work_items": [],
  "missing_facts": [],
  "safety_flags": []
}
```

未来可以增加 Question、Customer Service、Order、Production、Logistics、After-sales、Policy 和 Review Worker，而不改变 Case 和 Work Item 的基本契约。

在 n8n 阶段，一个主工作流负责一个 Case 的当前编排；子工作流只返回结果，不拥有 Case 生命周期。这一限制可以降低未来迁移 Temporal 的难度。

## 实验治理和代码清理

### 实验必须有退出机制

每个 n8n Workflow、Prompt、模型方案和 Laravel 实验模块必须登记：

- 要验证的假设；
- 对照基线；
- 负责人；
- 输入输出 Schema；
- 成功指标和安全指标；
- 最大样本量或评估日期；
- 当前状态；
- 采用、继续评估或废弃的结论；
- 数据和代码清理方式。

实验不能因为“以后可能有用”永久留在主菜单、Scheduler 和生产 Worker 中。

### 采用流程

```text
experimental
  -> 回放验证
  -> evaluating
  -> 人工评审
  -> active
  -> 被新版本替代
  -> deprecated
  -> 完成依赖审计后移除
```

### 代码清理原则

- 先盘点路由、命令、Scheduler、数据库表、文档和外部调用方；
- 先停止入口和新数据写入，再观察是否仍有调用；
- 保留需要审计的历史数据，删除运行代码不等于删除历史记录；
- 已采用的能力迁入明确模块后，再删除旧的并行实现；
- 每次只清理一个完整能力，并运行现有回归；
- 不在 n8n PoC 同时进行大规模目录重构。

第一轮只读盘点应特别检查 Analysis、ChatBot Story、Decision Case、Benchmark、Cancellation、WeKnora 和 Product Question 多代实验，但在获得运行证据前不预判删除对象。

## 向 Temporal 演进

### 何时需要迁移

满足以下任一条件时，相关流程应评估迁移到 Temporal：

- 流程需要等待数小时或数天；
- 涉及客服、订单、生产、物流多个 Worker；
- Worker 或服务重启后必须从原位置恢复；
- 涉及严格超时、Heartbeat、补偿或取消；
- 涉及退款、取消、重发、改址等关键业务写操作；
- 需要强幂等和完整事件历史；
- n8n 画布和错误分支已经难以维护。

优先迁移的是工作中心核心流程。分析中心的大部分批处理、模型对比和临时研究仍可保留在 n8n；不要求为了技术统一全部搬到 Temporal。

### n8n 到 Temporal 的映射

| n8n 概念 | Temporal 概念 |
|---|---|
| 主 Workflow | Workflow |
| Sub-workflow | Child Workflow 或 Activity |
| API/AI Node | Activity |
| Wait Node | Durable Timer |
| Webhook 恢复 | Signal 或 Update |
| Queue Mode Worker | Task Queue Worker |
| Error Workflow | Retry Policy、Failure 和补偿分支 |
| Execution ID | Workflow ID / Run ID |
| 执行记录 | Event History + Laravel Audit |

### 为迁移提前保留的契约

n8n 实施时必须保持以下节点边界稳定：

```text
LeaseWorkItem
LoadContext
PlanWork
ResolveFacts
SearchPolicies
GenerateDraft
ReviewDraft
RepairDraft
SaveDraft
WaitForApproval
ExecuteApprovedAction
```

这些名称不是强制代码接口，但每一步应有清晰、版本化的输入输出。未来 Temporal Activity 可以直接复用 Laravel Service 或内部 API，而不重新定义业务数据。

### 未来职责分工

```text
Temporal
  - 核心 Case 生命周期
  - 多 Worker 协作
  - 长时间等待、重试和超时
  - 人工 Signal
  - 关键业务动作编排

n8n
  - AI Prompt 和模型实验
  - 非关键数据同步
  - 通知和运营自动化
  - 临时外部集成
  - 回放与质量评估

Laravel
  - 业务事实和权限
  - Policy 和知识版本
  - 人工审核后台
  - Action Gateway
  - 幂等和结果回查
```

同一个业务 Case 只能有一个主 Orchestrator，避免 n8n 和 Temporal 同时控制状态。

## 分阶段验收标准

### Phase 0：本地 PoC

- n8n 本地可运行；
- Credential 不进入 Git；
- 能处理指定的单条问答；
- 只保存 Draft，不调用 Preview/Confirm；
- 每个节点输入输出可观察；
- Laravel 能关联 n8n Execution ID。
- `work`、`analysis` 和 `shared` Workflow 已分区；
- Workflow Registry 能区分 `experimental` 与 `active`。

当前实现状态（2026-10-17）：

- 已在 `../n8n/compose.yaml` 固定 n8n 镜像和本地持久卷；
- 已提供 `analysis__qa-analysis-v1`，并已导入、激活 `work__qa-draft-agent-v1`；
- 已实现 Laravel Internal API、独立 Token、输入校验、Execution 关联和幂等；
- 后台生成 Draft 已异步触发 n8n；Planner、受控事实解析、verified Policy 检索、英文 Writer、目标语言翻译和 Reviewer/Repair 已成为可观察的独立节点；
- Laravel 仍保存 AI Provider 凭据并提供结构化 Completion API，n8n 不持有业务系统 Token、不访问业务数据库、不调用 Preview / Confirm；
- `product_question_draft_requests` 记录 queued/running/completed/failed/stale、Execution ID、输入指纹、重试次数、复用来源和 Draft 关联；Scheduler 每分钟重试滞留 queued 并回收超时 running；
- Workflow Registry 已发布 `work/qa-draft-agent-v1@1.1.0`，旧 `1.0.0` 已 deprecated；发布命令保存 n8n ID、Prompt 版本和定义 checksum，并自动废弃同 key 的旧 active 版本；
- Reviewer、单次 Repair、Final Review 已独立为可观察节点；程序守卫覆盖无依据客服引导、重复商品标题/SN、模板变量、私密标识和必需事实缺失；
- 已建立 50 条固定回放集、评估命令和 ready/采用率/漏意图/错误阻止/事实错误/Policy 违规/成本/p50/p95 指标；Preview/Confirm 已记录人工修改反馈；
- 已使用口袋、Custom Size 和德语颜色定制问题完成真实验收；颜色定制在缺少工厂确认时安全 `blocked`；订单状态自动测试确认不会把私密订单事实发送给 AI；
- `ellenbridals #8` 的真实 Execution 35 已走完整 Reviewer → Repair → Final Review 分支；无匹配 Policy 时形成 unmatched request，Final Review 后仍复述商品 SN 时被保存为 `blocked`，且未调用 Preview/Confirm；
- 仍未部署生产。回放框架已完成，但上线前仍需持续补充真实 Workflow 预测并达到 Phase 1 门槛。

### Phase 1：回放验证

- 建立至少 50 条代表性案例；
- 新旧流程可以并行运行；
- 每次变更自动输出指标；
- 关键事实错误为零；
- 必须 blocked 的案例全部被阻止。

### Phase 2：小批量自动 Draft

- 只处理指定站点和低风险公开问答；
- 每批有上限和 Lease；
- 所有 Draft 必须人工审核；
- 失败有明确状态和通知；
- 不重复生成相同输入和工作流版本的 Draft。

### Phase 3：扩展客服和物流实验

- 使用统一 Work Item/Worker Result；
- 业务写操作仍然只产生 Action Proposal；
- 人工批准和实际执行完全分离；
- 选定一个跨客服和物流的案例进行 Temporal 技术验证。
- 完成第一次实验能力盘点，形成 adopted/deprecated 清单，但不在同一阶段大规模删除代码。

## 新会话建议的第一批任务

后续新会话按以下顺序实施，避免同时铺开全部模块：

1. 确认 n8n 部署方式、版本、访问地址、Credential 保存和执行数据保留策略。
2. 建立 Laravel Internal Workflow API 的认证和审计骨架。
3. 实现 Work Item Lease、Context、Fact、Policy Search 和 Save Draft 五个最小接口。
4. 导出并版本化第一个 `qa-draft-v1` n8n Workflow；Workflow JSON 不包含 Secret。
5. 使用 `dressafford #886`、一个 Mermaid/Trumpet 口袋问题、一个 Custom Size 问题和一个 ETA 问题做四条冒烟验证。
6. 为现有 50 条固定回放集生成 Workflow `1.1.0` 预测 JSON，并保存评估结果。
7. 比较当前 PHP Draft 与 n8n Draft Agent，并根据事实错误、采用率和人工反馈决定是否进入小批量自动 Draft。

并行完成一份只读模块盘点，标记工作中心、分析中心、Shared 和实验状态；本阶段只形成清单和依赖关系，不立即移动大量文件或删除历史能力。

第一批工作完成前，不接入自动 Preview、Confirm、订单操作或物流写操作。
