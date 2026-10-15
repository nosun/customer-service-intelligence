# 通用客服知识研究工作流

## 目标

使用程序完成可重复的筛选、证据校验和持久化，使用 AI 完成 Ticket 的语义切分和跨案例归纳。整个过程以小批量迭代，逐步形成分类、知识库、决策树、回复策略和业务操作流程；在知识成熟前不批量自动发送或执行写操作。

## 数据流

```text
最近 180 天 Ticket
  -> 分层选择 20 张（探索初期可降至约 10 张）
  -> 每张生成 Decision Case
  -> 拆成 0..N 个 Decision Node / Episode
  -> 程序校验证据、枚举和 Ticket 边界
  -> 跨案例归纳场景和知识候选
  -> 输出决策树、回复策略、操作和待确认问题
  -> 人工确认或修正
  -> 下一批 20 张
  -> 分类趋于饱和后逐步扩大批量
```

## AI 与程序的职责

程序负责：

- 按 `created_at_external` 控制研究窗口；
- 排除没有公开客户消息的 Ticket；
- 避免重复选择已经成功研究的 Ticket；
- 保存每次模型调用、版本、输入哈希、耗时和 Token；
- 校验 Episode 引用的事件确实属于当前 Ticket；
- 校验知识结论引用本批真实 Decision Node；
- 保存批次、场景版本、业务问题和报告。

AI 负责：

- 判断 Ticket 中有几个独立诉求或处理阶段；
- 使用开放分类识别新问题，而不是把问题强行塞入旧标签；
- 提取所需事实、历史决策、回复、动作、结果和不确定性；
- 从同批 Episode 中归纳场景边界、决策树和回复策略；
- 遇到冲突时提出业务问题和临时安全处理方式。

## 当前评审批次约定

- 时间范围固定为最近 180 天；
- 常规批量为每批 20 张，问题定义尚不稳定时可以先抽约 10 张；
- 跨案例归纳按 3 张 Ticket 一组，降低超长上下文和结构化输出失败风险；
- 使用可重复的 `batch` 和 `seed`，但同一个批次不得重复启动；
- 单张 Ticket 失败时保留成功结果，失败 Ticket 通过新 retry 批次单独处理；
- Ticket 拆分成功而归纳失败时，只重跑 `synthesize`，不重新分析 Ticket；
- 一旦批次已经发生人工确认或发布，禁止重新归纳覆盖，后续变化必须创建新批次。

## 批次命令

```bash
php artisan helpdesk:research:run \
  --limit=20 \
  --since-days=180 \
  --batch=pilot-001 \
  --seed=pilot-001
```

当前推荐显式使用较小归纳分组：

```bash
MINIMAX_RESEARCH_SYNTHESIS_TICKET_CHUNK_SIZE=3 \
php artisan helpdesk:research:run \
  --limit=20 \
  --since-days=180 \
  --batch=pilot-001 \
  --seed=pilot-001
```

`--seed` 使候选排序可重复。选择器尽量覆盖不同现有分类；未分类 Ticket 进入 `unknown` 抽样桶，并允许 AI 创建新的 `other` 或具体问题类型。

批次内部的单张 Ticket 失败不会删除已经成功的 Decision Case。批次记录失败原因并返回失败状态，后续新批次仍可再次选择失败 Ticket。

结构化输出首次未通过证据校验时，解析器会把具体错误和原输出交给模型修正一次。仍失败时使用定向重试，避免加入新的 Ticket：

```bash
php artisan helpdesk:research:run \
  --limit=20 \
  --batch=pilot-001-retry \
  --retry-batch=pilot-001
```

归纳阶段结构校验失败、但本批 Decision Case 已经生成时，使用：

```bash
MINIMAX_RESEARCH_SYNTHESIS_TICKET_CHUNK_SIZE=3 \
php artisan helpdesk:research:synthesize --batch=pilot-001
```

该命令只允许用于尚未人工审核的批次。只要 finding 已被发布，或问题已经获得全部或阶段性业务结论，程序会拒绝覆盖；需要重新归纳时创建新批次。

## 持久化结果

- `knowledge_research_batches`：窗口、批量、模型、状态、统计和完整结构化输出。
- `knowledge_research_batch_items`：每张 Ticket 的处理状态和 Decision Case。
- `knowledge_research_findings`：每个批次观察到的场景知识版本。
- `knowledge_research_questions`：需要业务负责人裁决的问题、选项和临时处理。
- `decision_nodes`：增加生命周期、意图、所需事实、动作、回复策略、知识缺口和禁止承诺。

每个批次保存独立场景版本，不覆盖以前的研究证据。累计报告选择每个 `scenario_key` 的最新候选，同时显示它跨批次出现的次数。

## 知识状态

- `observed`：历史案例观察或 AI 归纳，默认状态。
- `verified`：业务负责人确认，可以作为正式流程依据。
- `conflicted`：案例、政策或系统事实存在冲突。
- `deprecated`：已经废弃，不再用于新 Case。

批次生成器只创建 `observed/candidate`，不能自动升级为 `verified`。

## 人工评审与确认流程

每批按以下顺序评审：

1. 先检查 Ticket 完成数、失败数、Decision Node 数、场景数和新增场景数；
2. 用现有 `verified` 知识逐条处理待确认问题，已经完整覆盖的直接复用已有知识；
3. 只有真正缺少业务规则的内容才提交业务负责人确认，避免反复询问同一问题；
4. 提交确认时必须附对应 Ticket 的客户真实原文和当时客服真实回复；没有公开客服回复时明确写“无对应公开消息证据”；
5. 历史客服回复只能视为 `observed`，即使反复出现也不能自动升级为政策；
6. 业务负责人确认后，通过本地 JSON 和 `helpdesk:research:confirm` 事务写入；
7. 重新生成批次报告和累计知识库，核对问题状态及文章版本，再进入下一批。

确认文件的选择原则：

- 结论被某一现有 verified 文章**完整覆盖**时使用 `existing_article_key`，只解决问题，不改文章；
- 确认内容对现有规则有实质补充时，使用 `article` 并沿用原 key，形成新版本；
- 只确认安全边界、仍有例外或参数待定时使用 `keep_open: true`；
- 不确定、没有足够经验或需要客服/工厂/合规进一步确认时保持 `open`，不得猜测；
- 一个确认文件中任一项不合法时，事务整体回滚。

示例：

```bash
jq empty storage/app/private/knowledge-research/confirmations/batch-confirmations.json

php artisan helpdesk:research:confirm \
  storage/app/private/knowledge-research/confirmations/batch-confirmations.json \
  --reviewed-by=1

php artisan helpdesk:research:report --batch=pilot-001
```

完成后至少核对：本批 `open/resolved` 数量、累计 verified 文章、批次报告中的真实上下文，以及 `git diff --check`。

## 扩量门槛

保持每批 20 张；如果问题边界仍不清楚则临时退回约 10 张。满足以下条件后再继续扩大：

1. 连续三个批次没有出现新的主要场景；
2. `unknown/other` 已专项抽查且有明确处理边界；
3. 高频场景同时具有常规、复杂、失败或例外样本；
4. 关键决策都能追溯到消息或外部业务事实；
5. 开放业务问题已经确认，或者存在明确的安全人工兜底；
6. 决策树、回复策略和后续操作不再随少量新案例频繁变化。

满足后先扩大到每批 50 张左右，复核稳定性，再考虑最近 180 天的批量处理。

## 安全边界

- 批次报告会为开放业务问题附客户原文和当时客服回复，可能包含姓名、邮箱、订单号等个人信息，只能保存在 `storage/app/private` 本地私有目录，不得提交 Git 或对外共享；
- 累计知识库和正式流程文档不复制客户原文或不必要的个人信息；
- 历史客服回复只代表观察到的做法。
- 未核实的订单、生产、物流和退款状态不得进入确定性回复。
- 回复建议与业务写操作分离。
- 取消、退款、改单等动作必须另行实现审批、幂等、审计和结果回查。
- `verified` 且低风险、事实完整、无需写操作的场景，才可以进一步评估自动发送。

## 阶段小结（截至 2026-10-15）

- 已运行 47 个批次，选择 467 张 Ticket；439 张完成、28 张失败；
- 形成 686 个 Decision Node、480 条场景 finding、456 个不同场景；
- 业务问题累计 176 条，其中 159 条已解决、17 条保持开放；
- 知识文章 84 条，其中 79 条为 `verified`、3 条 `conflicted`、2 条 `observed`；
- 最近批次已经稳定使用 20 张 Ticket 和 3 张 Ticket 的归纳分组；
- 已验证人工确认可以复用现有知识、发布新知识、升级文章版本，并对阶段性结论保持问题开放；
- 当前仍属于知识建设和流程校准阶段，不代表已经授权自动回复或自动执行取消、退款、改址、重做等订单写操作。

这些数量来自本地研究数据库快照，会随新批次和后续确认变化；它们用于阶段评审，不作为固定产品指标。
