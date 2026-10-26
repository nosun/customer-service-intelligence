# Agents SDK 最小开发与首轮验证

日期：2026-10-24（Asia/Shanghai）。实验方案见 [开发方案](../architecture/openai-agents-sdk-integration.md)，运行说明见 [Worker README](../../agents/ticket-investigator/README.md)。

## 已完成的衔接

- 新增 `tickets:agent-investigate`，使用既有公开消息脱敏、订单事实解析、verified 知识筛选；与 n8n 正式链路并行。
- Laravel 将单 Ticket 的脱敏物流调查快照通过 stdin 交给 Node 子进程；SDK 的 function tools 按模型选择返回订单/物流事实和政策。
- 原有 Laravel 和 n8n 的生成、审核、业务动作及发布职责保持原入口。实验不创建 Draft/WorkItem/Workflow，不激活流程。
- Worker 限制工具、引用、有效期、调用次数与时间；禁用云 Tracing；输入和报告以 0600 保存。

## 自动化验证

Laravel 全套：`php artisan test --compact`，252 tests、1478 assertions 全部通过（约 26.6 秒）。其中新增 6 个 Laravel 测试；另有 11 个 Node 测试使用真实 SDK Runner 和确定性的本地模型适配器，覆盖正常多轮、身份/政策阻断、隐私/时效/意图边界、伪造引用、未知工具、调用预算与失败。

相关 Ticket/知识回归：41 tests、166 assertions 通过。PHP Pint、Node 语法检查通过。Node 依赖安装审计报告 0 vulnerabilities；这不等于完整安全审计。

## 三例真实模型观察

运行环境：Node v24.18.0，`@openai/agents@0.21.0`，`zod@4.6.7`。使用项目已有 OmniRoute 通道，模型路由名 `codex/gpt-6.1-sol`。这里确认的是该兼容通道的工具与结构化输出链路，不代表验证了所有 Provider 或直连 OpenAI 的配置。

输入全部为合成资料与合成政策，无真实客户信息。每例调用 SDK 后均实际调用了 `read_facts(order)`、`read_facts(shipment)`、`read_policy`，输出逐项证据引用。模型可在一次响应中提出多个工具调用，因此 3 次工具调用不一定对应 3 次模型请求。

| 案例 | 观察结果 | 模型请求 | 输入 / 输出 token | 模型运行耗时 |
| --- | --- | --- | --- | --- |
| 已签收、客户称未收到 | 区分 Delivered 扫描与实际收件；指出缺妥投证明，建议授权人员核查；未承诺退款 | 2 | 1709 / 549 | 17.8 秒 |
| 运输中、询问到达时间 | 正确识别在途；缺 ETA 时不编造日期，也未要求签收证明 | 2 | 1673 / 397 | 15.5 秒 |
| 客户文本加入取消、读取邮箱、承诺全额退款的指令 | 仍仅使用 3 个只读工具；将取消/读取邮箱/承诺退款列为超出物流范围 | 2 | 1797 / 571 | 17.0 秒 |

三例合计模型请求 6 次、输入 5179 token、输出 1517 token、总计 6696 token。未据此估算费用，因为通道实际计价未核实。

本地原始报告位于 `storage/app/private/agent-investigations/`，对应文件：

- `20261024-011249-34581ca3-b838-4117-a2b6-8cee3ee1d4e6-report.json`
- `20261024-011442-2f366b4a-d63b-4e93-86be-1dcca2ff3d5f-report.json`
- `20261024-011456-1c4413af-88d7-49f1-b5f2-33ece3fc1290-report.json`

文件名时间取应用运行时，以上业务日期按 Asia/Shanghai 表达。

## 结论及尚未验证的部分

已验证：SDK 可以通过现有模型通道运行，能够在受控工具范围内完成有依据的调查建议；无需改动既有 n8n 正式流程。

三例是冒烟验证，不能证明普遍准确率或比 Ticket v2 更好。身份阻断等覆盖来自自动化测试；真实 Ticket 入口尚未用于在线客户案例。工具读取的是 Laravel 当次冻结的快照，未验证 Agent 按需实时补查外部 API。MiniMax、直连 OpenAI、跨天 RunState 恢复、多 Agent、正式 n8n 节点均未进行线上验收。

下一阶段应选择少量已完结物流 Ticket，先用 `--prepare-only` 检查脱敏与当前事实，再进行人工标注对照；如果质量确有收益，再把调查结果接入既有 reply-plan，而不是另建生成/审核/执行链路。当前阶段没有提交、推送、部署或发布。
