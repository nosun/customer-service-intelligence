# 产品问答智能化工作进展交接

## 文档目的

本文记录截至 2026-10-17 的产品问答工作现状、已经确认的业务边界、当前本地数据、已完成能力、验证结果和遗留问题，供后续会话继续开发时直接使用。

本文只描述当前本地代码和本地数据库快照，不代表已经部署到生产环境。

## 当前目标

产品问答工作分为两条相互关联但职责不同的链路：

1. **知识研究链路**：分析历史问答，形成意图、问题分类、回复模式、Policy、事实需求和工厂待确认事项。
2. **在线 Draft 链路**：针对新问题读取当前商品和实时业务事实，选择已验证 Policy，生成待人工审核的回复草稿。

当前安全边界保持不变：

```text
生成 Draft
  -> 人工审核和编辑
  -> Preview
  -> 人工 Confirm
  -> 站点回复
```

系统不得直接自动发送问答回复。

## 产品边界重新梳理

当前代码同时承担日常客服工作和离线研究分析，两类功能使用相同的模型、后台菜单、命令和数据表，已经开始互相增加复杂度。后续应明确拆成两个有联系但职责不同的中心。

### 工作中心（Customer Service Workbench）

工作中心面向客服的日常操作，核心要求是稳定、清晰、低学习成本和可追溯：

- 邮件、站内问答、Chat、Ticket 等渠道数据；
- Conversation、Case、负责人、优先级、SLA 和处理状态；
- 当前商品、订单、生产和物流事实；
- verified Policy、决策树和操作指引；
- AI Draft、人工编辑、Preview、Confirm；
- 需要其他团队处理的 Work Item；
- 业务 Action Proposal、审批、执行和结果回查；
- 客服最终处理结果和客户反馈。

工作中心只消费已经发布的 Policy 和稳定工作流，不直接展示大量实验参数、研究批次和模型中间输出。

### 分析中心（Service Intelligence Lab）

分析中心面向业务分析、策略研究和系统优化，可以小批量试验，但不能直接产生业务授权：

- 历史问答和 Ticket 分类、聚类、质量评估；
- Decision Case 和 Decision Node；
- 回复模式、事实需求和 Action Flow 研究；
- Policy 候选、冲突和开放问题；
- 工厂能力归纳；
- Benchmark、回放集、模型和 Prompt 对比；
- Draft 与最终人工回复的差异分析；
- 新 Worker、新工具和新工作流的实验；
- Policy 修订建议和覆盖率分析。

分析中心只能发布候选。候选经过证据校验和人工审核后，才能成为工作中心可使用的 verified Policy、稳定工作流或工具契约。

### 两个中心的连接方式

```text
工作中心积累真实 Case、人工修改和 Outcome
                   |
                   v
分析中心发现模式、缺口、冲突和改进机会
                   |
                   v
人工审核并发布 Policy / Workflow / Tool 新版本
                   |
                   v
工作中心按明确版本使用
```

双方通过版本化契约交换数据，不直接依赖对方内部表结构：

- `CaseSnapshot`：经过脱敏的案例和结果；
- `FactEnvelope`：事实值、来源、捕获时间和新鲜度；
- `PolicyVersion`：已审核策略及适用范围；
- `WorkflowVersion`：稳定流程定义；
- `WorkerResult`：事实、决策、Draft、缺失和安全标记；
- `ReviewFeedback`：原 Draft、最终文本、差异和可选修改原因。

### 代码分离策略

短期先使用同一 Laravel 仓库中的模块化单体，不立即拆成微服务：

```text
app/Work
  - Channels
  - Cases
  - ProductQuestions
  - CustomerService
  - Logistics
  - Actions

app/Intelligence
  - Analysis
  - DecisionCases
  - KnowledgeResearch
  - PolicyResearch
  - Benchmarks
  - Evaluations

app/Shared
  - Contracts
  - Facts
  - Policies
  - Audit
  - AIProviders
  - Sites
```

这只是目标边界，不要求下一次开发立即移动全部文件。迁移时应按一个完整能力纵向移动，保持现有测试通过，不能进行一次性目录重构。

满足以下条件后再考虑拆仓库或独立部署：

- 两个中心发布节奏明显不同；
- 分析任务影响工作中心响应或稳定性；
- 权限、数据保留或资源需求明显不同；
- n8n/Temporal Worker 需要独立扩容；
- 模块只能通过稳定 API/事件契约交互。

### 当前代码的初步归属

| 当前能力 | 目标归属 |
|---|---|
| Conversation、Product Question、Draft、Preview、Confirm | 工作中心 |
| 商品、订单、ETA、配送、物流事实读取 | 工作中心的 Fact Gateway，共享契约 |
| Analysis、Decision Case、Knowledge Research | 分析中心 |
| Product Question Policy Research、Factory Capability Aggregation | 分析中心 |
| Benchmark、回放和模型比较 | 分析中心 |
| verified `knowledge_articles` 和 Policy 版本 | Shared，由分析中心发布、工作中心只读消费 |
| MiniMax/OpenAI 等 Provider Adapter | Shared，两个中心分别配置用途和预算 |
| 站点、权限、审计和 Action Gateway | Shared/工作中心 |

## Git 与部署状态

- 当前分支：`master`。
- 最近一个已提交的问答相关提交：`03397d4 feat: refine product question policy workflows`。
- 多站点问答基础提交：`2d352a7 feat: add multi-site product question intelligence`。
- 当前 Draft、事实契约、Policy 合并与后续优化仍有大量本地未提交修改。
- 当前修改没有在本次工作中执行 push 或部署。
- `.playwright-cli/` 是无关的本地目录，不应纳入后续提交。
- 后续提交必须按目标文件精确暂存，不得使用 `git add .` 或 `git add -A`。

## 当前本地数据快照

以下数量来自 2026-10-17 的本地 SQLite，不是固定产品指标：

| 数据 | 数量 |
|---|---:|
| 已配置站点 | 2 |
| 已同步问答 | 6,099 |
| 已同步商品 | 727 |
| 已分析问答 | 1,808 |
| Policy 候选 | 33 |
| 已发布 QA verified knowledge | 19 |
| 已生成 Draft | 62 |

站点问答快照：

| 站点 | 问答 | 未回复 | 最近本地同步时间 |
|---|---:|---:|---|
| `dressafford` | 611 | 74 | 2026-10-16 10:31:37 |
| `dorriswedding` | 5,488 | 48 | 2026-10-16 10:31:25 |

Draft 状态：

| 状态 | 数量 |
|---|---:|
| `generated` | 30 |
| `blocked` | 29 |
| `failed` | 3 |

上述失败和阻止记录包含开发期间的真实验证结果，不能直接用作生产质量指标。

## 已完成能力

### 多站点配置

`commerce_sites` 已用于独立配置：

- 站点名称和 `site_key`；
- tradeManager Admin API 地址；
- 加密保存的 API Token；
- `public_base_url`；
- `brand_name`；
- `customer_service_email`；
- 门店、试穿、在线客服等站点能力字段；
- 启用状态和增量同步游标。

策略模板不应硬编码域名、品牌和客服邮箱，应使用：

```text
{{ site.public_base_url }}
{{ site.brand_name }}
{{ site.customer_service_email }}
```

### 问答同步与后台管理

已实现：

- 多站点完整同步和增量同步；
- 按站点、本地状态和远端状态查看问答；
- 问题、回答、SN、商品名称、客户名称和邮箱关键词检索；
- 问答详情、同步来源和远端存在状态展示；
- 商品快照同步；
- 手工生成 Draft；
- 人工审核 Analysis；
- Preview 与 Confirm 分离；
- Confirm 使用 `confirm_token` 和唯一 `Idempotency-Key`；
- Confirm 失败时保留原 Payload 和幂等键，支持安全重试。

主要命令：

```bash
php artisan product-questions:sync
php artisan product-questions:sync-products
php artisan product-questions:analyze
php artisan product-questions:classify-batch
php artisan product-questions:aggregate-factory-capabilities
php artisan product-questions:research-policies
php artisan product-questions:draft
```

### 分析和 Policy 研究

历史问答分析已包含：

- 主分类和次分类；
- 意图；
- 问题作用域；
- 事实来源；
- 工作队列；
- 可回答性和风险等级；
- 所需事实和缺失事实；
- 工厂能力问题；
- 历史回复模式和质量评估；
- 自动化建议和置信度。

Policy 研究遵守以下生命周期：

```text
历史回答 observed
  -> AI 归纳 candidate
  -> 人工解决冲突和开放问题
  -> confirmed
  -> 发布 verified knowledge article
```

历史回答即使重复出现，也不能自动升级为 verified Policy。

### 商品事实和实时接口

当前 Draft 可使用以下事实组：

- `site`：站点公开配置；
- `product`：当前 SN 的商品、属性、图片、颜色、尺码、库存规格和可编辑选项；
- `eta`：指定商品、国家和当前 Rush/Fast Dispatch 条件下的 ETA；
- `shipping`：目的国家支持情况、运输方式、天数、费用和税费信息；
- `localization`：站点支持的语言和货币；
- `order`：console-new 订单、生产工单和物流信息，必须使用私密回复和人工核验。

实时接口失败、关键事实缺失或公开回复包含私人订单标识时，Draft 必须进入 `blocked`。

### 已确认和整理的 QA Policy

当前本地已确认的主要全局 Policy 包括：

- 退货、退款和投诉边界；
- 配送国家、运输时间、费用和进口税；
- 标准 ETA、Rush 和 Fast Dispatch；
- 尺码建议和 Custom Size 网站操作；
- 商品生产周期；
- 联系方式和线上经营边界；
- 订单状态查询和私密订单处理；
- 商品产地；
- 定制长度；
- 商品是否自带口袋；
- 根据裙形增加口袋。

已驳回或合并的重复 Policy 保留历史记录，不物理删除。

### Draft 生成

当前 Draft 流程为：

```text
加载问题和 verified policies
  -> MiniMax 选择一到多个意图
  -> 程序校正部分高频误判
  -> 按 Fact Contract 读取商品和实时事实
  -> 组合 verified policies
  -> 生成规范英文回复
  -> 必要时翻译
  -> 语义和安全检查
  -> 保存 generated / blocked / failed Draft
```

每条 Draft 保存：

- 模型和 Prompt 版本；
- 意图；
- Policy ID；
- 使用的事实和来源；
- 缺失事实；
- 安全标记；
- 英文和本地化答案；
- 可见性建议；
- 置信度；
- 输入、Prompt 和 Provider Request 标识；
- Token Usage。

所有 Draft 都保持 `requires_human_review=true`。

## 已确认的重要业务规则

### 产品问答回复原则

- 产品问答是单轮公开问答，应优先一次回答完整，不依赖客户继续回复。
- 问题已绑定商品，不重复完整商品标题；需要识别时可以保留 SN。
- 售前产品和网站使用问题不默认添加联系客服段落。
- 只有私密订单、售后投诉、事实无法安全获得或 Policy 明确要求时才引导客服。
- Policy 先形成规范英文模板，非英语问题在英文答案完成后统一翻译。

### Custom Size

- `type=dress` 且 `customizable=1` 时，商品页尺码选择中可以使用 `Custom Size`。
- 客户询问如何填写尺寸时，直接引导：`Size -> Custom Size -> 填写出现的尺寸字段`。
- 不要求客户在公开问答中提交身体尺寸。
- 不无依据增加费用、工期或退换货承诺。

### ETA 与 Rush

- 总到货时间必须使用当前商品、目的国家和实时 ETA；不能只引用固定生产天数。
- Rush 是动态能力，生产繁忙时可能关闭，回复前必须查询。
- 客户未提供国家时，产品问答不要求其继续回复；引导其选好商品选项并进入结账流程查看当前预计到达时间。
- 不保证到货，只能陈述当前估算和风险。

### 口袋

- `ask_pockets` 表示询问商品默认是否自带口袋。
- `request_add_pockets` 表示要求增加口袋。
- 业务确认：所有 `product.type=dress` 的商品默认不带口袋，不再把商品目录缺少 pocket 字段解释为“无法确认”。
- 产品标题和 SN 已由问答页面提供上下文，回复中不重复这些信息。
- 工艺确认：当前目录中的 `Mermaid/Trumpet` 对应鱼尾包臀裙，不适合增加口袋。
- 其他已知裙形基本可以免费增加口袋，在结账时通过 `Order Notes` 备注。
- 暂不把 `Sheath`、`Bodycon`、`Pencil` 自动扩大为例外。
- 不是 dress，或客户要求增加口袋但当前裙形缺失、冲突时，仍需进入事实核实，不能承诺增加。

真实问题 `dressafford #886`、SN `732349` 已验证：

- 当前商品裙形为 `A-Line`；
- Draft 同时组合 `ask_pockets` 和 `request_add_pockets`；
- 新 Draft 为 `generated`，直接说明裙子默认不带口袋、可以免费增加，并在 `Order Notes` 备注；回复不包含商品标题或 SN；
- 验证过程中没有调用 Preview 或 Confirm。

## 当前主要问题

### Draft 质量仍需持续回放优化

当前已经完成完整链路，但草稿存在以下问题：

- 有时只说明事实缺失，没有提供已有 Policy 支持的可执行方案；
- 多意图问题容易遗漏其中一部分；
- 对商品事实和 Policy 的组合不够自然；
- 可能添加不必要的客服引导；
- 同一个问题需要反复修改 Prompt 或加入程序分支；
- 当前模型的直接草稿质量与人工使用 Codex 综合判断仍有差距。

### 在线编排开始出现硬编码

目前部分规则分散在：

- 意图枚举；
- 批量分类映射；
- Fact Contract；
- Draft Prompt；
- `ProductQuestionDraftGenerator` 中的正则和特殊分支；
- Policy 数据迁移。

例如默认口袋缺失时组合增加口袋 Policy，当前仍有专门的 PHP 分支。继续为每个问题增加类似分支会降低扩展效率。

### Policy 修改方式需要改善

当前部分业务 Policy 通过数据迁移创建和修正，便于审计和部署，但不适合频繁调整回复内容。后续应逐步转为：

- 后台编辑 Policy Draft；
- 结构校验；
- 人工 Review；
- 发布新版本；
- 保留旧版本和证据；
- 运行中的 Draft 记录实际使用的 Policy 版本。

### Draft 专用回放集已建立，仍需补充真实预测输出

已建立 50 条固定案例的 `resources/data/product-question-draft-replay-v1.json`，并提供 `product-questions:evaluate-draft-replay`。目前可以统一计算：

- 不同模型；
- 不同 Prompt；
- 当前 Draft 与 n8n Agent Draft；
- 无修改采用率；
- 平均人工修改量；
- 错误事实率；
- 错误阻止率。

下一步是把候选 Workflow 的真实预测 JSON 接入每次发布验收，而不是继续增加新的评估框架。

### 实验代码缺少生命周期

当前仓库中存在多批探索性实现，包括不同渠道分析、ChatBot Story、Decision Case、Benchmark、Cancellation Verifier、WeKnora 集成和多代 Product Question Prompt。它们具有研究价值，但不代表未来都进入工作中心。

后续不能凭印象直接删除，也不能无限保留。每项实验能力应登记：

- 目标和假设；
- 负责人；
- 输入和输出；
- 依赖的数据表、命令和页面；
- 当前状态：`experimental / evaluating / adopted / deprecated`；
- 回放或业务指标；
- 最近一次运行时间；
- 是否被工作中心使用；
- 退出和数据保留方式。

建议建立一次只读代码和运行数据盘点，把功能分为：

1. 工作中心生产能力；
2. 分析中心长期能力；
3. 仍在评估的实验；
4. 已被替代、可以安全移除的能力。

删除前必须确认路由、命令、Scheduler、数据库、文档和外部调用方，不因“看起来没用”直接删除历史代码或数据。

### 当前只覆盖完整客服工作的约四分之一到三分之一

现有工作主要覆盖渠道同步、问答/邮件分析、Policy 研究、部分商品和订单事实以及回复草稿。仍未形成完整生产能力的领域包括：

- Ticket 分配、SLA、协作者和交接；
- 生产中异常和工厂协作；
- 物流异常调查、等待、升级和客户通知；
- 售后质量、退款、补偿和拒付；
- 跨渠道客户和 Case 关联；
- 多 Worker 协作和长时间等待；
- Action Proposal、审批、执行和补偿；
- Outcome、业务指标和持续改进。

因此后续架构不能只围绕 Product Question 的意图列表设计，应围绕通用 Case、Work Item、Fact、Policy、Worker、Action 和 Approval 契约扩展。

## 最近验证结果

2026-10-17 最后一次完整本地回归：

```text
138 tests passed
801 assertions passed
Pint passed
git diff --check passed
```

该结果证明当前本地代码通过自动测试，不等于已经完成部署或线上验收。

## 下一阶段结论

### 2026-10-17 n8n Phase 0 落地

已在同级目录 `../n8n` 落地本地 n8n 服务和两个可导入工作流：

- `analysis__qa-analysis-v1`：租取问答并调用现有 `ProductQuestionAnalyzer`；
- `work__qa-draft-v1`：早期批量工作流，租取未回复问答并调用现有 `ProductQuestionDraftGenerator`；后台当前使用下文的 Agent 工作流。

Laravel 新增独立 Bearer Token 保护的 `/api/internal/n8n/*` 边界，以及 `n8n_workflow_executions` 审计表。每个问题按 `workflow_key + workflow_version + execution_id + question_id` 关联 n8n Execution 并幂等复用完成结果。

当前 n8n 编排 AI 与 Laravel 受控能力；MiniMax、tradeManager、console-new 凭据仍只在 Laravel，工作流不访问业务数据库，也没有 Preview / Confirm 路径。Draft 仍只写入本地并要求人工审核。

后续实现更新：后台“生成 Draft”已改为异步触发 `work/qa-draft-agent-v1`，不再在 Web 请求中直接调用 PHP Draft Generator。n8n 画布现在明确展示分类、受控事实解析、verified Policy 检索、简洁英文生成、目标语言翻译、Review/Repair 和回写节点。Laravel 用 `product_question_draft_requests` 保存 queued/running/completed/failed、Execution ID、错误和最终 Draft 关联；页面轮询状态，10 分钟未完成会转失败并允许重试。

P0/P1 稳定化已经完成：

- `ai_workflows` 注册并激活 `work/qa-draft-agent-v1@1.1.0`；`1.0.0` 已 deprecated；导出定义 checksum 为 `fb5aa01bb6ef2acd57d19ea76d086b1608c69fadbc6c567d0b7b7243d527e9bd`；
- 普通生成会按问题、Analysis、商品快照、站点、全部 verified Policy、Workflow、Prompt 和模型组成的输入指纹复用结果；强制重新生成保留为显式操作；
- 执行开始和完成都会重新校验输入，变化的请求转为 `stale`；Scheduler 每分钟重试滞留 queued，并回收超时 running；
- n8n 已拆分 `Review Draft -> Reviewer Approved -> Repair Draft Once -> Final Review Draft`，Repair 最多一次；关键节点错误统一回写 failed；
- Laravel 最终防线会阻止模板变量、私密标识、缺少关键事实、无 Policy 支持的通用客服引导，以及重复商品标题/SN；
- Planner 无匹配 Policy 时不再抛 500，而是形成 `unsupported_request:*` 并保存为 blocked，交给人工处理；
- Preview/Confirm 会写入 `product_question_draft_feedback`，保存原 Draft、最终文本、Diff、相似度、原因和操作人。

发布命令：

```bash
php artisan n8n:register-workflow work/qa-draft-agent-v1 1.1.0 ../n8n/workflows/work__qa-draft-agent-v1.json \
  --status=active --n8n-id=HMj4IB3pA7uhmZFW --prompt-version=n8n-qa-draft-agent-v3
```

回放命令：

```bash
php artisan product-questions:evaluate-draft-replay predictions.json
```

`predictions.json` 以案例 `id` 为键，至少提供 `status`、`intents`、`answer_en`、`duration_ms`，可选 `cost`、`fact_error`、`policy_violation` 和 `accepted_as_is`。

本地 `work__qa-draft-agent-v1` 已导入并激活，n8n 只监听 `127.0.0.1:5678`，Webhook 使用独立 Token；AI 节点有有限重试和 120 秒单次超时。运行说明见 `../n8n/README.md`。这些结果仍是本地验收，不代表已经部署生产。

真实问题反复验收结果：

- `dressafford #886`：口袋业务规则更新后复验为 Execution 19，Draft 60，正确组合 `ask_pockets + request_add_pockets`；状态 `generated`，回复为 `This dress does not come with pockets by default, but we’d be happy to add them at no extra charge. Simply write “Please add pockets” in the Order Notes section at checkout.`，不包含商品标题或 SN。
- Petite Size `dressafford #885`：Execution 21，Draft 62，正确组合 `ask_size_recommendation + request_custom_size`，不再误入 Plus Size；说明商品提供标准 US Size 4、没有独立 Petite Size 4，要求按当前 Size Chart 比较 bust、waist、hips、hollow-to-floor，并在 Petite 比例不匹配时选择 Custom Size，同时明确 Custom Size 不可退换。状态 `generated`，未复述商品标题或 SN。
- Custom Size `#887`：最终版工作流复验为 Execution 17，Draft 58；最终安全校验删除模型虚构的 `3–5 business days` 和多余客服引导，状态 `generated`。
- 德语颜色问题 `#761`：最终 Execution 15，Draft 56；正确识别 `request_custom_color`，不再误归为 `request_custom_length`。因为深绿和宝蓝缺少该商品的工厂可行性确认，安全状态为 `blocked`，英文和德语只说明需要工厂核实，不承诺可做、不列无关退货或尺码规则。
- 订单状态测试确认：只返回 `private_order_lookup` 缺失标记，不把私密订单事实发送给 AI，强制 `recommended_visible=false` 和 `blocked`。
- `ellenbridals #8`：Workflow `1.1.0` 最终复验 Execution 35，Planner 明确输出空 intents 和 unmatched request；流程实际经过 Reviewer → Repair → Final Review，并因回复仍复述商品 SN 而安全 `blocked`。该次运行验证了无匹配 Policy 不再抛错、页面 queued/running/completed、Execution ID 回写、单次 Repair 和最终程序守卫；未调用 Preview 或 Confirm。

上述真实运行均未调用 Preview 或 Confirm，问题仍保持 `unanswered`。

现有知识研究、Policy 审核、多站点、事实接口、Draft 持久化和 Preview/Confirm 都应保留。

下一阶段不重写这些能力，而是使用 n8n 快速实现一个可观察、易修改的 Draft Agent 和 Reviewer 工作流：

- Laravel 继续作为数据、Policy、事实权限、审核和业务写操作的唯一权威；
- n8n 负责流程编排、模型试验、Review/Repair 和通知；
- n8n 不直接连接业务数据库，不保存站点 Admin API Token，不执行 Confirm；
- 先并行运行新旧 Draft，不替换现有流程；
- 成熟后再评估把关键长流程迁移到 Temporal。

同时按工作中心与分析中心的边界开始收敛代码：新功能必须先明确归属，跨中心只通过版本化契约连接；实验能力必须具备评估和退出机制，避免继续增加单体内部耦合。

具体实施方案见：

[`docs/architecture/n8n-ai-workflow-and-temporal-evolution-plan.md`](../architecture/n8n-ai-workflow-and-temporal-evolution-plan.md)
