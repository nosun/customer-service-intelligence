# 当前工作流与路线图

更新：2026-10-26。当前产品目标是 Ticket 的情况分析、处理意见、邮件草稿；按人工调查及改稿时间、事实正确性与建议可用性验收。

## 当前路径

| 路径 | 入口与职责 | 维护状态 | 扩展或退役条件 |
| --- | --- | --- | --- |
| n8n Ticket v2 | 自动派工配置指定的路径；Laravel 保存事实、审核与草稿 | 对照基线，是否运行由环境开关和工作流准入决定 | 与 SDK 使用同一冻结案件和证据对照后，按场景决定保留或迁移 |
| Ticket Agents SDK 1.2.0 | Ticket 详情页手动启动完整调查、拟稿与独立复核，background Job 执行 | 手动候选；尚未进入统一发布登记 | 扩大自动使用前补齐候选准入、人工逐案评价、启用范围与回退条件 |
| n8n Ticket v1 | 兼容已有工作流与历史任务；定向反馈保留原版本 | 兼容维护 | 核实外部工作流、在途任务与旧 payload 均不再依赖后退役 |
| 早期 SDK investigator PoC | 独立调查实验与历史验证记录 | 冻结，不继续增加功能 | 确认没有调用方或必要调试用途后，连同专属入口和测试一起移除 |
| 商品问答 | 当前 decision plan 与旧固定回复分支共存 | 保留现行能力，优先核实旧分支调用 | 追踪外部 n8n payload 和共享方法，完成回归后收缩旧分支 |
| WeKnora | 经审核知识的发布与检索 | 独立集成验收 | 完成审核→更新→索引→检索→新案件引用后，再扩大使用 |
| Benchmark / 历史研究 | 离线研究与质量分析 | 低优先级 | 与日常 Ticket 运行分别衡量，不按代码行数删除资产 |

自动派工仍使用 v2。反馈指定 SDK 任务时沿用 SDK 和该任务的 provider，以当前 SDK 版本与配置生成新任务；指定 n8n 任务时沿用其工作流及版本，并重新检查准入。未指定任务的反馈默认 v2。无法启动时保存反馈并报错，不静默改换路径。个案反馈不自动成为跨案件政策。

## 已实现的运行治理

- `tickets:draft-workflow-health` 同时观察 ticket_draft 与 ticket_agent，并按 workflow/version 分组。报告窗口用于统计；窗口外仍活动的任务继续接受卡住检测。
- `healthy` 保留为技术异常布尔值；`technical_status` 为 healthy / unhealthy / disabled / no_data。停用自动派工不会掩盖手动 SDK 的异常。`automatic_dispatch_enabled` 单独说明开关状态。
- blocked、人工审核覆盖率、采纳率和耗时分别报告。`business_status` 的 no_data / awaiting_review / reviewed 说明是否有业务观察，reviewed 表示已有人工评价，不能据此认定案件已解决；具体覆盖率仍需查看。
- SDK 使用 deadline，不要求 n8n lease。定时 reaper 将超过 deadline 的 queued/running SDK 任务原子标记失败，保留调查证据和 checkpoints，由人工重跑；缺少 deadline 的旧任务按最后更新时间与 timeout + 120 秒收尾。迟到进程不得写入工具进展、checkpoint 或草稿，也不得覆盖收尾原因。
- 新 SDK 任务保存非敏感运行规格：workflow/version、provider/model、端点哈希以及 assistant、进程入口和依赖锁文件哈希。实际提示词变化直接影响 assistant 文件哈希，无需重复手填提示词版本。策略、消息和附件继续参与输入指纹。
- 不同规格不复用活动任务。没有运行规格的历史任务与旧结果保留为历史，不能继续标为当前；升级后需重新分析。

## 统一验证

先安装 `composer install` 和 `npm --prefix agents/ticket-investigator ci`，然后运行：

```bash
composer check
```

顺序为 Feature + Unit → Node SDK → Return 路由和生成文档一致性 → Pint → diff 空白检查。任一组失败停止并返回非零。PHP/Node 管道测试使用本地 fixture 子进程，覆盖消息分段、工具往返、checkpoint、错误退出、错误关联与超时，不调用模型。真实模型回放与人工验收单独执行和记录。

## 后续批次及完成条件

| 批次 | 交付 | 完成条件 |
| --- | --- | --- |
| A | 本地运行治理、运行规格失效、统一检查与当前路径文档 | 自动化回归与统一检查通过；本地进程故障验证通过。机器/父进程强杀及部署调度需在运行环境另验 |
| B | 反馈重跑保持路径已实现；完成 v2 / SDK 同案件对照、SDK 候选准入 | 建议首轮 20–30 个去重案件，冻结同一证据截面，覆盖多商品、附件不可读、身份不符、条件接受、客服回复后新进展及接口故障；人工记录遗漏、错误承诺、改稿分钟数、时延、成本；据此确定场景范围和回退 |
| C | 依据调用证据退役 PoC、QA 旧分支和专属测试 | 确认调用方、在途任务与兼容 payload；保留关键业务回归。当前只冻结，不据猜测删除 |
| D | 按真实 blocked 原因补齐费用/币种、退运、收件/质检只读事实，完成知识闭环 | 每项接口对应实际阻塞案例并减少人工调查；知识审核、有版本、可回滚且可被新案件引用 |

[健康审查](../reviews/2026-10-26-project-health-and-roadmap.md) 是审查时点证据；[第一阶段方案](ticket-assistance-phase-one.md) 保留设计背景；[SDK 流程](ticket-agent-sdk-workflow.md) 说明候选实现。当前文档不代表已完成真实模型对照、统一发布准入或生产验收。
