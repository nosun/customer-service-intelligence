# OpenAI Agents SDK 接入开发方案

日期：2026-10-24。状态：最小实验已实现，SDK 工具循环与三例真实模型调用已通过；详见 [首轮验证记录](../reviews/2026-10-24-agents-sdk-poc.md)。目标：验证 Agent 自主选择调查工具的收益，保留现有业务权威、知识审核和工作流职责。首轮为本地实验，不发布工作流。

## 1. 文档阅读与结论

本方案依据 OpenAI 官方文档的 SDK 主线：Quickstart、Agent definitions、Models and providers、Running agents、Orchestration and handoffs、Guardrails and human review、Results and state、Integrations and observability，以及 Tools、Agent evals。另阅读 Sandbox 和 Voice 概览以判断适用边界。没有把 Python、TypeScript 的每个 API 参考或所有沙箱供应商的文档算作已经验证的能力；具体代码以锁定依赖的实际接口和测试为准。

| 官方文档 | 对本项目的约束或应用 |
| --- | --- |
| [SDK overview](https://developers.openai.com/api/docs/guides/agents/sdk) | SDK 在自己的服务中运行；部署、存储、工具和授权由应用负责。 |
| [Quickstart](https://developers.openai.com/api/docs/guides/agents/quickstart) | 从一个专职 Agent 和少量工具开始。 |
| [Agent definitions](https://developers.openai.com/api/docs/guides/agents/define-agents) | 本地运行上下文不会自动发给模型；只有输入、指令和工具结果进入模型。结构化输出仍需后端业务校验。 |
| [Models](https://developers.openai.com/api/docs/guides/agents/models) | 显式指定模型；第三方兼容端点必须实际验证工具与输出协议，不推断功能相等。 |
| [Running agents](https://developers.openai.com/api/docs/guides/agents/running-agents) | Runner 执行模型与工具循环；设置轮数与时间上限。会话保存不等于业务任务调度。 |
| [Orchestration](https://developers.openai.com/api/docs/guides/agents/orchestration) | Handoff 转移当前处理权；Agent as tool 保留主 Agent 的处理权。未来专项调查优先后者。 |
| [Guardrails and review](https://developers.openai.com/api/docs/guides/agents/guardrails-approvals) | 输入/输出 Guardrail 不覆盖所有工具；权限和范围校验放在工具/业务 API。审批中断要保存状态，再恢复同一次运行。 |
| [Results and state](https://developers.openai.com/api/docs/guides/agents/results) | 中断不算完成；finalOutput、history、RunState、业务 Case 分开管理。 |
| [Integrations and observability](https://developers.openai.com/api/docs/guides/agents/integrations-observability) | 默认云追踪可能包含工具输入输出；本实验关闭云追踪，仅记录本地工具名、参数与证据引用。 |
| [Tools](https://developers.openai.com/api/docs/guides/tools#usage-in-the-agents-sdk) | 现有 Laravel 服务可以封装为 function tools，不要求先引入 MCP 或向量库。 |
| [Agent evals](https://developers.openai.com/api/docs/guides/agent-evals) | 先检查轨迹与失败，再用固定案例比较质量、成本与时延；模型自评不替代事实复核。 |
| [Sandboxes](https://developers.openai.com/api/docs/guides/agents/sandboxes) / [Voice](https://developers.openai.com/api/docs/guides/voice-agents) | 当前是文本与受控查询，不需要 Shell、文件工具或语音运行时。 |

## 2. 当前系统与衔接位置

现有 `Conversation -> CustomerCase / WorkItem -> n8n -> Facts / Policies -> ReplyPlan -> Draft -> Review` 保持原有用途。`TicketRuntimeContext` 提供脱敏公开消息；`TicketFactResolver` 提供带来源和时间的事实；`TicketPolicyRepository` 经 `KnowledgeResolver` 筛选 verified、站点与用途；工作流注册、输入指纹、lease、回放与发布门禁继续由现有系统负责。

### 职责表

| 组件 | 负责 | 首轮不承担 |
| --- | --- | --- |
| Laravel 工作中心 | 案件/订单范围、事实读取和脱敏、知识筛选、输入版本、业务授权、审计和结果验收 | 把业务判断全部搬进 Agent 提示词 |
| n8n | 事件触发、固定业务步骤、外部连接、既有生成/复核/修订流程 | 自己维护第二套订单和政策状态 |
| Agents SDK Worker | 在给定范围内选工具、读取证据、形成有引用的调查建议 | 决定案件归属、读业务 DB、发送回复、执行取消/退款/改码 |
| 知识/RAG | 提供有版本和适用范围的知识 | 用历史回复代替现行政策或实时订单状态 |
| 分析中心 | 案例比较、候选知识、运行效果分析 | 自动把实验结果发布为正式政策 |
| 未来 Temporal | 必要时接管跨天等待、事件恢复和业务重试 | 第一轮替换 n8n 或 SDK 的内部循环 |

## 3. 最小实验：物流调查建议

成功标准：真实使用 SDK Runner 完成“模型选工具 -> 工具返回 -> 模型继续 -> 结构化调查结果”，能够说明来源、缺口和下一步，且不能引用未查询证据、不能把客户说法当系统事实。

采用 Node.js ESM 独立小包，锁定 `@openai/agents` 版本与 lockfile；不向 Laravel 前端 package.json 添加运行依赖。首轮通过 Laravel Artisan 调用 Node 子进程，JSON 经 stdin/stdout 传递，避免为一次实验引入常驻 HTTP 服务、认证及端口。相同 Worker 可在后续阶段包装成服务。

```text
tickets:agent-investigate --conversation=<本地 ID> [--prepare-only]
  -> Laravel 固定当前公开消息与本地知识版本
  -> TicketFactResolver 只读核查当前订单身份和物流
  -> 过滤非白名单、过期和 private/restricted 事实
  -> 固定一个脱敏调查快照与 input_hash
  -> Node / Agents SDK Runner
       -> read_facts(group=order|shipment)
       -> read_policy(key=本次已审核目录中的 key)
       -> 最多 6 轮模型调用，最多 6 次工具调用
  -> 核验证据引用、有效期、输入指纹与必查步骤
  -> 本地 JSON 调查报告（0600），供人工比较
```

### 冻结快照的边界

首轮工具读取的是 Laravel 刚生成的快照，并非 Agent 每次调用工具时都刷新外部 API。这样先验证 SDK 工具循环和系统契约，避免同时重做现有事实接口。报告要记录事实时间，不称为“已重新联系承运商”。即使模型调用成功，也不等于调查已经完整或客户问题已解决。

另提供纯合成 fixture，验证模型效果时不需要发送真实客户资料。`--prepare-only` 只生成输入，适合先检查当前 Ticket 的脱敏与事实边界。

### 输入/输出

输入包含 `schema_version`、`case_key`、`input_hash`、`captured_at`、公开消息、事实列表、缺口、安全标记、verified 政策。模型初始只收到消息与政策目录；完整事实和政策保存在 Worker 本地，按工具调用返回。凭据只通过子进程环境传递。

输出包含 `status=needs_review|blocked`、调查结论、逐条证据引用、缺口、建议下一步、工具轨迹、模型/SDK/提示词版本和 token 使用量。没有 `approved`、执行成功或可自动发送状态。证据引用格式为 `fact:<key>` 和 `policy:<key>@<version>`；引用必须来自本次实际工具返回。引用存在只能证明可追溯，不能自动证明自然语言结论正确。

### 事实与身份

仅允许订单找到/身份匹配/订单状态/发货状态，以及承运商、是否有单号、物流状态、发货和签收时间。订单号、追踪号、客户姓名邮箱地址、原始响应、退款与生产资料不进入这次物流调查工具。

身份未确认或不匹配时不暴露订单事实，并直接返回 blocked，不调用模型。事实过期或输入在运行中变化也阻断。政策只使用本地已审核且绑定物流意图（或通配）的条目；未知 key 不可读取。客户消息、政策正文均作为证据文本，不可扩大工具能力。

## 4. 为什么首轮不直接替换 Ticket v2

当前 Ticket v2 已有逐项计划、模板、补查、审核修订和后端保护；本地还有大量未提交修改。直接插入正式链路会混合模型效果与流程回归。本实验只新增调查入口与 Worker，独立输出报告，不写 Draft、WorkItem 或 Workflow，不改发布状态。

因此这一轮可以验证工具选择、证据引用、缺口识别、失败与时限；尚不能证明 Agent 比现有 v2 更好、动态补查外部 API 更高效，或支持跨天恢复。正式 A/B 需要相同案例、输入时间、知识版本和人工标签。

## 5. 后续接入 n8n 的阶段

1. 本轮通过后，给 Worker 增加经过认证的调用入口；Laravel 为一个 WorkItem 签发短期、单案件、只读能力，凭据不进入模型上下文。
2. n8n 固定执行 Start/lease，再调用调查 Worker；Agent tools 经专用内部 API 复用 `TicketFactResolver`/`TicketPolicyRepository`。不直接复用具有 complete/fail 权限的广域 n8n token。
3. Worker 返回调查建议；Laravel 校验其案件、lease、input_hash、事实版本与引用，再转换为既有 reply-plan 输入。保留模板、生成、独立审核、一次修订与终审。
4. 通过已有 Registry 注册独立 `experimental/evaluating` 版本，冻结回放并人工复核，再决定发布；不因为一次 SDK 成功就绕过现有门禁。

状态所有权：WorkItem/Case 在 Laravel；n8n 保存编排 execution；SDK RunState 仅保存一次 Agent 运行/审批中断。业务失败与 Agent 技术失败分开，重试以 WorkItem+input_hash+工具调用幂等键约束。业务写执行仍由 Laravel 的 ActionProposal/审批/执行与回查承接。

只有单 Agent 遇到明确工具或上下文边界时，才增设生产/物流专项 Agent；主 Agent 以 agents-as-tools 收集结构化结果，复核阶段重新读原始证据。分析中心的 Agent 可单独迭代，但产物仍是待审核候选。

## 6. 验证与验收

| 层级 | 验证方式 |
| --- | --- |
| Laravel | 自动化测试：身份不符、脱敏、private/过期/未知事实过滤、未审核及错站点政策排除、无业务写、输入变更失效 |
| SDK | 真实 Runner + 本地假模型：多轮工具调用、未知工具/政策、伪造引用、未查询直接完成、轮数和工具预算、失败传播 |
| 真实模型 | 合成案例：已签收未收到、在途、缺政策/缺证明；记录调用链、质量、token、耗时与失败 |
| 既有回归 | Ticket runtime / investigation / response assessment 与知识筛选相关用例；必要时全套测试 |
| 发布 | 首轮不激活或部署；下一轮以固定案例和人工事实/政策审核评估接受率、错误声明率、遗漏率、耗时和成本 |

对照报告必须区分：测试通过、真实模型运行成功、人工事实复核通过、正式工作流发布。价格不按 SDK 包估算，按实际 Provider、模型 token 和工具账单统计。

## 7. 本轮运行方法

安装、命令与配置见 `agents/ticket-investigator/README.md`。实验状态保持 experimental；运行结果保存于 `storage/app/private/agent-investigations/`，不提交客户资料。SDK 依赖升级需重新运行工具协议与回放测试。
