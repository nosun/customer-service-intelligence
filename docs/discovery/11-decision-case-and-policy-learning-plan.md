# 决策案例与客服策略学习实施计划

## 目标

把每张 HelpDesk Ticket 整理成一条可审核的主案例，并允许一条主案例包含多个按时间排列的决策节点。后续通过同类案例比较，逐步形成典型案例、处理原则、操作流程和经过审核的客服策略。

最终流程：

```text
Ticket
  -> 决策案例
       -> 决策节点
            -> 事实与缺失事实
            -> 客户诉求
            -> 客服判断与动作
            -> 处理结果
            -> 原始消息证据
  -> 同类案例归纳
  -> 典型案例与反例
  -> 策略和操作流程
  -> AI 分类、策略匹配和回复建议
  -> 人工审核或受控自动处理
```

## 核心原则

1. 每张 Ticket 只建立一条主案例，使用 `conversation_id` 保证唯一。
2. 一条案例可以包含多个决策节点，保留问题变化、反复交涉和处理升级过程。
3. 客户陈述、系统事实、客服动作、分析推断和人工结论分别保存，不互相覆盖。
4. 每个判断都要能回到原始 `conversation_event`，避免只保存无法核对的摘要。
5. 历史客服回复先作为观察证据，不自动视为公司政策。
6. 策略必须区分站点、适用时间、普通情况、反例和特批情况。
7. 所有生成过程可重复运行、可版本化、可审计；人工修正不能被下一次自动分析覆盖。
8. 第一阶段只分析和审核，不自动发送回复或执行退款、取消等业务动作。

## 为什么第一阶段需要 AI

仅靠确定性程序可以完成消息排序、角色识别、会话轮次切分、现有规则分类、证据关联和数据库校验，但无法可靠完成以下工作：

- 判断客户真正想解决的问题，而不只是匹配某个关键词。
- 识别客户在后续回复中改变了诉求，或者同时提出多个诉求。
- 从上下文中提取隐含条件，例如“婚礼临近”“客服以前承诺过”“商品已经修改”。
- 区分客服是在确认事实、给出建议、正式作出决定，还是执行了具体动作。
- 判断客服拒绝或同意的理由，以及该理由是否明确写出。
- 判断客户是否接受结果、继续反对、升级投诉或转为另一个问题。
- 识别普通处理、边界处理、客服失误和主管特批。

因此 Decision Case 采用混合解析：

```text
确定性程序
  -> 准备完整且有顺序的 Ticket 上下文
  -> 标记消息角色、时间、事件 ID 和已有规则标签
AI 语义解析
  -> 识别一个或多个决策节点
  -> 提取诉求、事实、缺失事实、判断、理由、动作和结果
确定性程序
  -> 校验 JSON 结构、证据 ID、节点顺序和字段约束
  -> 保存分析版本和审计记录
人工审核
  -> 确认、修正或拒绝 AI 结论
```

程序生成的会话轮次只作为 AI 的候选边界。最终节点数量和含义由 AI 根据上下文提出，再由人工审核校准。

第一阶段使用通用大模型进行结构化提取，不需要先训练专用模型。每次分析必须使用固定的 Prompt 版本和 JSON Schema，并要求模型对关键结论返回原始事件 ID。模型找不到证据时必须返回 `null` 或 `unknown`，不能补全不存在的业务事实。

## 概念定义

### 主案例 Decision Case

主案例对应一张 Ticket，描述该 Ticket 的整体问题、参与站点、时间范围、主要类型、风险和最终处理状态。

主案例本身不强求只有一个问题。例如客户最初要求取消，取消失败后转为询问退货，这仍然是一条主案例，但包含多个决策节点。

### 决策节点 Decision Node

决策节点表示一次相对完整的“客户提出诉求，客服判断或执行，客户接受、反对或继续补充”的过程。

典型节点：

```text
客户请求取消订单
  -> 客服查询生产状态
  -> 告知已经开始制作
  -> 拒绝直接取消并提供替代方案
  -> 客户接受或继续交涉
```

同一 Ticket 中出现新的诉求、客服改变处理方式、处理升级或客户提出反对时，可以形成新的节点。

### 证据 Evidence

证据是节点与原始消息事件之间的关联。证据角色包括：

- `request`：客户诉求。
- `fact`：订单、商品、时间、物流、支付等事实。
- `decision`：客服同意、拒绝、有条件同意或升级。
- `reason`：客服说明的判断依据。
- `action`：退款、补发、重做、取消、修改等实际动作。
- `outcome`：客户接受、继续追问、投诉、拒付或工单结束。
- `exception`：特批、客服失误、历史承诺等例外依据。

第一版只保存原始事件引用和证据角色，不重复复制完整客户正文。

## 第一阶段数据模型

### decision_cases

建议字段：

| 字段 | 说明 |
|---|---|
| `id` | 本地主键 |
| `conversation_id` | 唯一关联一张 Ticket |
| `case_key` | 稳定案例标识，例如 `helpdesk:{ticket_id}` |
| `primary_category` | 当前主分类 |
| `primary_problem_type` | 当前主问题类型 |
| `urgency` | 当前规则紧急度 |
| `case_summary` | 对整个 Ticket 的候选摘要 |
| `node_count` | 当前决策节点数量 |
| `review_status` | `candidate/reviewed/needs_revision` |
| `extractor_version` | 案例生成器版本 |
| `extracted_at` | 最近生成时间 |
| `reviewed_at` | 最近人工审核时间 |

`conversation_id` 必须唯一。站点、Ticket 状态和原始消息继续以 `conversations` 与 `conversation_events` 为事实源，避免不必要的数据复制。

### decision_nodes

建议字段：

| 字段 | 说明 |
|---|---|
| `id` | 本地主键 |
| `decision_case_id` | 所属主案例 |
| `sequence` | 节点在案例中的顺序 |
| `problem_type` | 节点问题类型 |
| `scenario_key` | 后续审核形成的具体场景 |
| `request_summary` | 客户诉求摘要 |
| `observed_facts` | 消息中明确出现的事实 |
| `missing_facts` | 做出判断仍缺少的事实 |
| `observed_decision` | `accepted/rejected/conditional/alternative/escalated/unknown` |
| `observed_action` | 历史客服实际采取或声称采取的动作 |
| `stated_reason` | 历史回复中明确说明的理由 |
| `observed_outcome` | 客户后续反应和可观察结果 |
| `is_exception` | 是否可能属于例外处理 |
| `extraction_confidence` | 结构化提取置信度，仅用于审核排序 |
| `review_status` | `candidate/confirmed/corrected/rejected` |
| `extractor_version` | 节点生成器版本 |

`observed_*` 表示历史记录中观察到的做法，不代表已经批准的处理规则。

### decision_node_evidence

建议字段：

| 字段 | 说明 |
|---|---|
| `decision_node_id` | 所属决策节点 |
| `conversation_event_id` | 原始消息事件 |
| `evidence_role` | request/fact/decision/reason/action/outcome/exception |
| `source` | rule/ai/human |
| `extractor_version` | 证据提取版本 |

同一节点可以关联多条客户消息和客服消息。数据库约束需要确保引用的事件属于同一条 Conversation。

## 第一阶段上下文准备与节点切分

程序先采用可解释的会话轮次切分，为 AI 提供候选节点：

1. 忽略私有消息、非消息事件和已识别噪音。
2. 客户连续发送的公开消息组成当前请求片段。
3. 后续连续的客服公开消息组成当前处理片段。
4. 客户再次发言时，默认开始下一个候选节点。
5. AI 根据完整上下文判断相邻轮次属于同一决策节点还是新的决策节点。
6. 如果问题类型变化、客服处理升级或客户反对原决定，AI 应提出独立节点并给出证据事件 ID。
7. 没有公开客户消息的 Ticket 仍建立主案例，但 `node_count = 0` 并记录原因。

候选轮次不会直接成为业务结论。它用于控制上下文规模、保留消息结构，并帮助 AI 和审核人员定位原始证据。

## AI 解析契约

AI 对每张 Ticket 独立阅读，输出结构化结果。建议输出结构：

```json
{
  "case_summary": "",
  "primary_category": "",
  "primary_problem_type": "",
  "case_risk": "normal|high|critical",
  "nodes": [
    {
      "sequence": 1,
      "problem_type": "",
      "scenario_key": null,
      "request_summary": "",
      "observed_facts": [
        {
          "name": "",
          "value": "",
          "evidence_event_ids": []
        }
      ],
      "missing_facts": [],
      "observed_decision": "accepted|rejected|conditional|alternative|escalated|unknown",
      "observed_action": null,
      "stated_reason": null,
      "observed_outcome": null,
      "is_exception": false,
      "evidence": [
        {
          "event_id": "",
          "role": "request|fact|decision|reason|action|outcome|exception"
        }
      ],
      "uncertainties": [],
      "extraction_confidence": 0.0
    }
  ]
}
```

程序必须检查：

- 输出符合固定 Schema。
- 引用的事件 ID 确实存在且属于当前 Ticket。
- 节点顺序连续且符合消息时间顺序。
- `observed_decision` 等枚举值合法。
- 有决定或理由的节点必须提供相应证据。
- AI 不得把私有消息、其他 Ticket 或外部未知信息作为证据。
- 低置信度、缺失事实和互相矛盾的内容自动进入人工审核。

## 第一阶段实施步骤

### 1. 建立表和模型

- 新增 `decision_cases`、`decision_nodes`、`decision_node_evidence`。
- 建立唯一约束和必要索引。
- 不修改现有 `conversations`、`conversation_events`、分析和回复候选数据。

验证：迁移可以正常执行和回滚，外键检查无错误。

### 2. 实现 Ticket 上下文构建器

上下文构建器负责：

- 按时间顺序读取完整 Ticket。
- 过滤不参与分析的事件。
- 保留事件 ID、消息角色、时间和公开正文。
- 加入当前规则分类和回复候选作为辅助信息。
- 控制超长 Ticket 的输入规模，同时保留首条诉求、所有决策相关消息和最近结果。
- 生成可以稳定复现的输入哈希。

验证：相同 Ticket 和相同构建器版本生成相同输入；不得串入其他 Ticket 内容。

### 3. 实现 AI 决策案例解析器

建议命令：

```bash
php artisan helpdesk:cases:build
php artisan helpdesk:cases:build --problem-type=production_cancellation
php artisan helpdesk:cases:build --problem-type=after_sales_return_refund --limit=100
```

要求：

- 使用 `updateOrCreate` 或等价幂等方式。
- 每张 Ticket 独立调用 AI，输出必须符合固定 JSON Schema。
- 保存模型、Prompt 版本、输入哈希、输出、耗时和错误状态。
- 所有关键判断都要关联原始事件证据。
- 自动生成的数据带 `extractor_version`。
- 重新运行不会产生重复案例、节点或证据。
- 重建候选节点时保留已经人工确认或修正的数据。
- 支持按问题类型和数量分批运行。
- 单张 Ticket 失败时记录错误并继续下一张，支持失败重试。

验证：连续运行两次后，各表记录数和唯一键保持稳定。

### 4. 生成第一批案例

第一批范围：

- 全部 `production_cancellation` Ticket。
- 分层选择 100 张 `after_sales_return_refund` Ticket。
- 分层维度包括站点、是否有候选回复、紧急度、Ticket 状态和是否需要复核。

验证：每张选中 Ticket 恰好一条主案例；所有非空节点至少关联一条客户消息证据。

### 5. 输出本地审核数据

审核数据至少展示：

- Ticket 标识和站点。
- 决策节点顺序。
- 客户诉求摘要。
- 已知事实和缺失事实。
- 历史客服判断、动作和理由。
- 客户后续反应。
- 原始证据链接。
- 人工确认、修正、拒绝和备注字段。

审核文件保存在 `storage/app/private/`，权限使用 `600`，不提交真实客户数据到 Git。

### 6. 第一轮人工校准

审核重点：

- 节点切分是否合理。
- 是否遗漏问题变化或处理升级。
- “客服说了什么”与“实际执行了什么”是否被混淆。
- 已知事实与推断是否分离。
- 取消和退货需要哪些新增事实字段。

第一轮审核完成后再冻结 Decision Case v1 数据结构。

## 第一阶段验收标准

- 当前 `tickets + archive` 范围内，每张 Ticket 恰好对应一条主案例。
- `decision_cases.conversation_id` 无重复。
- 一条主案例支持零个、一个或多个有顺序的决策节点。
- 每个非空节点至少有一条 `request` 证据。
- 所有证据事件都属于主案例对应的 Conversation。
- 私有消息、spam 和 trash 不进入第一阶段案例分析。
- 案例生成器重复运行不会产生重复记录。
- 自动重建不会覆盖人工审核结果。
- AI 输出全部通过 JSON Schema 和证据引用校验。
- 无证据的理由、动作和结果不能被保存为确定结论。
- 取消订单全部案例和100个退货/退款案例可以导出并审核。
- 数据库 `quick_check` 和外键检查通过。
- 测试覆盖单节点、多节点、无客户消息、问题变化和重复运行。

## 后续阶段

### 阶段二：案例归纳与典型案例

- 按场景、处理结果、理由和结果聚类。
- 找出标准案例、反例、边界案例和特批案例。
- 找出相同条件但处理结果不同的矛盾案例。
- 比较 DressAfford、EllenBridals 和不同时期的处理差异。

### 阶段三：策略与操作流程

- 从经过审核的案例中形成策略草案。
- 每条策略记录适用条件、禁止条件、操作步骤、回复块、升级条件和证据案例。
- 策略使用 `draft -> reviewed -> approved -> retired` 生命周期。
- 只有 `approved` 策略能够进入正式 AI 回复流程。

### 阶段四：AI 影子运行

- AI 对新 Ticket 生成案例、节点、分类、所需事实、策略匹配和回复草稿。
- AI 不发送，人工确认并记录所有修改。
- 建立固定评测集，统计分类、事实提取、策略选择和回复修改情况。

### 阶段五：人工批准与受控自动化

- 先进入人工一键批准模式。
- 只有低风险、事实完整、策略唯一且经过充分验证的场景可以自动回复。
- 退款、取消、补偿、交期承诺和争议处理继续受权限规则控制。

## 机器学习建设时点

第一阶段不搭建独立机器学习平台。先记录足够完整的训练和评测信息：

- 原始事件引用。
- 自动提取版本。
- 自动分类结果。
- 人工修正结果。
- 使用的策略和相似案例。
- AI 草稿与最终回复差异。
- 最终业务结果。

当分类体系稳定、人工审核数据达到可评测规模，并且规则与通用模型仍存在明确瓶颈时，再评估监督学习、Embedding 检索或模型微调。模型自己的置信度不能单独作为自动发送依据。

## 当前执行状态

- [x] 完成 HelpDesk 全量采集和增量同步基础。
- [x] 完成 Ticket/消息级初步分类和回复候选提取。
- [x] 明确“一张 Ticket 一条主案例，多个决策节点”的方向。
- [x] 建立 Decision Case v0 数据表和模型。
- [x] 接入 MiniMax OpenAI 兼容接口并固化 Prompt v1。
- [x] 实现 Ticket 上下文、AI 输出校验、证据校验和可重复运行的案例生成器。
- [ ] 使用有效 MiniMax API Key 完成第一张真实 Ticket 的端到端解析。
- [ ] 生成全部取消案例和首批100个退货/退款案例。
- [ ] 完成第一轮人工审核并冻结 Decision Case v1。
- [ ] 开始典型案例和策略归纳。
