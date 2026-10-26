# Return 流程维护

第一版覆盖普通实物退货的六个沟通阶段及例外入口，实际接入现有 SDK 调查、拟稿、审核和保存链路。它是一份可迭代的基础流程；“80%”是范围取舍，不代表经过统计的正确率、业务覆盖率或可自动执行比例。

## 到哪里查看和修改

| 内容 | 唯一维护来源 | 校验/用途 |
| --- | --- | --- |
| 节点、路由、能力限制、中性模板 | resources/data/ticket-return-playbook-v1.json | TicketReturnAssessment 的可执行结构校验与路由 |
| 正式政策、站点策略与特批 | 现有已审核 KnowledgeArticle、固定官网来源及本案批准 | 保留实际版本、引用与适用范围；示例邮件不构成政策 |
| 可读图和节点说明 | docs/workflows/return-flow.md | 从定义生成，禁止独立维护 |
| 确定性路由案例 | resources/data/ticket-return-routing-cases-v1.json | 离线执行真实阶段选择器 |
| 语境案例 | resources/data/ticket-return-stage-eval-v1.json | 真实 SDK/model 回放；预期阶段不提供给模型 |
| 个案结果 | WorkItem.result / ConversationDraft.metadata / 私有审计文件 | 保存依据、路由、版本、原始证据及独立复核 |

流程内模板只规定中性沟通结构。具体金额、邮费减免、地址、表格和时限由适用政策及个案证据决定。知识库的具体方案措辞需符合流程节点，不得与 JSON 独立维护同一条经济政策。官网抓取时间不是政策生效时间；历史适用性或知识冲突需明确核实。

## 修改与验收

1. 先记录具体问题：脱敏上下文、错误判断、证据、正确下一步，以及属于事实/流程/模板/语气/本案特批的哪一种。
2. 在对应数据集中添加用例。语境应覆盖此前客服的问题和客户回答，不只保留最后一句。多商品、换方案、日期边界和已寄回状态尤其要保留上下文。
3. 修改定义或解释规则，并提升 version；路由条件只使用已支持的 positions、condition_statuses、exception_present、最终 always。按数组顺序匹配，条件项之间为 OR。新字段/阶段需同步更新执行器、Node 合约和测试。
4. 执行以下命令，先看文档差异，再执行与改动相关的真实模型回放。

```bash
php artisan tickets:return-flow-check --write-doc
php artisan tickets:return-flow-check --json
php artisan test tests/Feature/TicketReturnAssessmentTest.php tests/Feature/TicketReturnFlowMaintenanceTest.php tests/Feature/TicketAgentWorkflowTest.php
npm --prefix agents/ticket-investigator test
php artisan tickets:agent-evaluate-returns
```

只重放部分语境时可指定 `--case=confirmed_context --case=accepted_offer`。模型回放使用配置的提供方并产生调用费用，固定合成证据不访问真实 Ticket、不创建真实邮件或执行业务操作。模型只读取业务视图，节点内的测试案例索引与维护字段不会提供给模型，以避免泄漏预期阶段。报告位于 storage/app/private/ticket-agent-evaluations/，保留失败记录；其通过只说明阶段、引用及审核通过，不代表含完整可发送指引。

5. 业务负责人核对适用范围、例外权限、既有约定、金额和每个必要输出；不得仅为让测试通过而修改预期。发布前保留旧定义和评估报告；新定义使旧输入指纹失效，旧个案记录仍保留原版依据。
6. 本地验证、代码发布、部署和真实 Ticket 验收分别记录。当前实现不发送邮件、不执行退款；三层完成标准不能互相替代。

## 反馈积累

复用 Ticket 页面已有反馈及 knowledge_candidate 选择。事实/流程/模板纠正经业务确认后，可通过已有 `knowledge:synthesize-ticket-feedback` 生成未批准知识候选；本案 decision 不允许上升为通用规则。候选审核后才可发布。流程路由的变化仍需修改版本化定义并通过回归，不由知识生成器直接改写代码。

每个确认的失败至少增加一个脱敏用例，记录哪个节点/规则失败、缺少什么证据、预期是什么。语气修改不应改变金额、资格或阶段。单次成功或“客服采纳草稿”不证明退款完成，也不足以证明一条策略适合所有订单。

## 自动检查边界

`tickets:return-flow-check --json` 可以由现有 CI、Laravel 调度或 n8n 调用：结构不合法、节点/工具引用失效、路由回归或文档过时都会返回非零退出码。它不调用模型、抓取政策或自行发布修改。本轮不新增后台定时任务；确定运行位置与频率后可直接接此命令。

Agent 当前每次调查可读取固定官网政策，现有知识反馈可生成候选；尚未建立历史政策档案、跨版本政策变更监控和自动候选更新流水线。后续监控应比较来源哈希并保留旧版本，生成影响范围和候选修改，经过语境回归及审核后发布，不能因页面文字变化自动覆盖已约定条款。

## 已知能力缺口

第一版明确保留：完整币种/折扣/费用分配、专用退货地址核验、单据生成及附件准备、实际退件承运商查询、仓库收货/质检、支付渠道执行/到账查询。节点中的 owner 是跟进职责，不代表系统已经创建工单或通知了该人员。

后续阶段的历史资格字段可标为 missing，但六项条件仍必须完整记录。评估表输入无效时会返回可纠正的错误，并清空旧阶段选择；SDK 只允许一次修正，不能循环猜测或绕过模板校验。定义本身损坏、工具故障和模型调用错误仍然失败留痕。

多商品和质量争议可以识别并进入具体例外，但尚无逐商品状态机或完整质量争议处理流程。捐赠、保留商品补偿、免退回退款等需本案授权，目前交例外处理，不自动套用历史优惠。分析结构完整性通过不代表上述接口已经实现。
