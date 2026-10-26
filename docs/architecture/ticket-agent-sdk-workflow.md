# Ticket Agents SDK 完整辅助流程

目标：单个 Ticket 自主判断请求、规划调查、读取背景/已审核策略/订单/附件，决定是否回复，生成中英文邮件，经独立复核和至多一次修订后保存到现有待人工审核入口。不发送邮件，不执行订单动作。

Laravel 持有案件、身份、工具权限、证据、输入指纹和最终持久化。Node 使用锁定版本的 OpenAI Agents SDK Runner。通过父子进程 NDJSON 双向管道按需调用 Laravel 工具，不开放 HTTP 服务，也不把业务凭据交给模型。工具只能查询当前案件；正文链接必须先从该案件公共消息中发现。附件下载限受支持的可信 HTTPS 文件域名，禁止重定向到任意主机。

阶段：评估和计划 → 主 Agent 按需调查并拟稿 → 独立 Agent 根据实际工具证据审核 → 必要时修订一次并重新审核 → Laravel 验证输入、引用、审核文本哈希和事实时效 → WorkItem / ConversationDraft 保存。no_reply 保存判断但不创建邮件；复核未通过的邮件保存 blocked；技术失败保留失败任务，不伪装为完整结果。

## 1.2 Return 可执行流程定义

业务流程定义为 return@1.1.0，SDK 工作流为 1.2.0，提示词为 ticket-assistant-v3-return-flow。六个节点和按顺序匹配的决策表共用 `resources/data/ticket-return-playbook-v1.json`，由 `TicketReturnAssessment::validateDefinition` 使用 Laravel Validator 和引用检查执行结构规范。沿用现有字段和模板，不引入独立编排平台。文件名中的 v1 表示这一格式系列，实际修订号以 version 字段为准。

每个节点包含进入条件、必须评估/可选证据、分析/草稿/业务三层完成标准、异常出口及负责角色、回复内容和对应语境案例。能力目录标明 available / partial / manual / unavailable；列为 required_evidence 不代表适配器存在，也不自动意味着必须阻塞所有回复。readiness 仍取决于当前邮件所需的证据，缺少最终地址不阻止方案沟通，但阻止完整寄回指引。

决策结果与草稿元数据保存实际 routing_rule、strategy_version、definition_hash、node_contract；输入指纹覆盖同一源文件。现有 SDK 会读取完整定义，并按选定节点调查、拟稿和复核。接受或寄回后发现新冲突时，阶段保留，requires_internal_review=true；Node 与 Laravel 都阻止将其标为 ready。客户位置中的 return_received / refund_issued 表示需调查的报告进度，不构成仓库或支付执行证明。 独立审核同时接收初始站点元信息与原始工具证据，避免拟稿和审核可见事实不一致。合成回放保留检查点及错误详情，技术失败和业务审核失败分别留痕。

可读流程、关系图和所有节点来自同一份定义：[Return 流程](../workflows/return-flow.md)。维护操作见 [维护说明](../runbooks/return-flow-maintenance.md)。

```bash
php artisan tickets:return-flow-check
php artisan tickets:return-flow-check --json
php artisan tickets:return-flow-check --write-doc
```

检查命令在本地校验结构、工具/节点引用、19 个确定性路由用例、全部规则覆盖、10 个语境案例登记和生成文档一致性。不调用模型或业务 API；语境登记通过不等于实际模型回放通过。文档只能从定义生成，检查会拒绝缺失/过时文档。 Agent 读取的是去除 maintenance 和 evaluation_cases 的业务视图，防止评估案例名称与预期阶段映射泄漏到模型；文档和离线检查使用完整定义。

后续阶段同样要记录完整六项条件，历史细节未知时显式标为 missing，不能为了填表重问客户。评估工具的输入校验失败返回 invalid_assessment，清空旧选择且不提供 assessment 引用；允许模型依错误纠正一次，连续两次无效则终止。工具故障仍按技术失败处理，不能用纠错机制伪装查询成功。

2026-10-25 本轮验收：PHP 全量 310 tests / 1838 assertions、Node 23 tests、19 个离线路由案例全部通过。去除评估索引后的十项真实 SDK/OmniRoute 合成语境最终均通过：条件确认、条件缺失、超期、有条件接受、接受方案、已寄回、内部延误、混合商品、客户报告送达、查询已承诺退款。每项实际读取的 playbook 均复查无测试映射泄漏；准确币种/金额只取合成证据，未访问真实 Ticket 或创建邮件。

“通过”允许正确输出 internal_review：接受方案但缺地址、缺仓库或支付确认、混合商品等不能直接发送执行指引。验收包括修复后重跑；首次字段遗漏、审核上下文不完整等失败报告均保留。最后一轮送达核查曾因审核 hash 不匹配而技术失败，原校验未放宽，独立重跑后通过，不能把最终 10/10 当作零失败率或线上覆盖率。对应十份报告及阶段/准备情况汇总于本地私有 `storage/app/private/ticket-agent-evaluations/return-flow-v1-acceptance-20261025.json`。

## 1.1 退货语境与阶段改进

SDK 执行现分为调查、专门拟稿、独立审核三个阶段。调查 Agent 读取完整往来，制定计划，按需取得当前官网政策、订单商品金额及已验证知识；将前封问题和客户回答配对，记录每项条件的 satisfied / missing / failed / conflict / not_applicable、解释和实际引用。不能用模板或流程说明冒充客户事实；适用时间窗口需要政策依据。

`TicketReturnAssessment` 根据条件判断及有证据的客户进度选择阶段，`read_return_template` 读取对应模板后由 Runner 自动结束调查阶段。专门拟稿 Agent 取得实际工具原文、阶段及模板，形成邮件；避免把反复查询和内部待办当作客户答复。独立审核重新阅读原始证据，必须通过 stage_transition 和 investigation_completion 两项检查。

| 当前语境 | 下一阶段与模板 |
| --- | --- |
| 必要条件确实未答 | collect_conditions / RET-01，只补影响决定的未知项 |
| 条件已充分确认，方案尚未接受 | offer_options / RET-02，提出适用方案、退款基础、费用责任及验收条件，让客户确认 |
| 有条件接受 | 保留 conditional_acceptance，继续协商或处理具体例外，不能当成 option_accepted |
| 已明确接受现有方案 | send_instructions / RET-03，提供核实后的正式指引，不重复确认资格 |
| 已报告寄回 | track_return / RET-04，核查实际退件状态，不把原配送当退件 |
| 退件签收、质检或退款结果待核实 | review_refund / RET-05，区分客户报告与业务证实 |
| 超期、实际条件冲突、特殊例外 | exception_review / RET-EX，具体解释和跟进，不自动拒绝或擅自豁免 |

策略位于 `resources/data/ticket-return-playbook-v1.json`，只规定沟通阶段和内容要求，不固化跨站退款比例、费用、地址或特殊方案。当前官网通过受信任固定 URL 读取，不接受模型传入 URL，不执行网页脚本；保留正文哈希和有效时间。已验证知识与公开政策冲突时明确交内部核实，不能静默覆盖。

资格与报价准备分开：缺少最终街道地址不阻止 RET-02；缺少准确币种或费用分配不能猜净额，可在政策明确时说明退款基础并保留内部金额核算项。RET-03 缺少可执行地址/文件时则必须标记内部待准备。`return_reply.readiness` 区分 ready / internal_review / holding，后两类只能保存 blocked，不能直接采纳。ready 仍须人工审核，不能代表已执行批准。

本地 Ticket 页面显示退货阶段、模板、逐项条件解释及回复准备情况。保存结果中保留 return_assessment、reply_readiness 和完整原始证据。

合成语境真实模型回放（不读取 DB 或调用业务 API，不创建真实 Ticket/邮件）：

```bash
php artisan tickets:agent-evaluate-returns
php artisan tickets:agent-evaluate-returns --case=confirmed_context --case=conditional_acceptance
```

固定数据集覆盖条件已答、状态缺失、超期、有条件接受、已接受、已寄回。预期阶段只供验收器比较，不提供给模型；有条件接受允许继续方案协商或具体例外处理，两者均须保留尚未达成协议。报告保存到 `storage/app/private/ticket-agent-evaluations/`，0600；失败记录保留，不能用重写预期代替业务审核。

2026-10-25 改进版验收：Ticket 12292 / WorkItem 369 / Draft 258，10 次调查工具调用，随后分别由 Ticket stage email writer 和 Independent Ticket reply reviewer 拟稿、复核。六项条件被判为已满足，进入 offer_options / RET-02，提出按商品费用退款、费用排除、客户承担寄回邮费、签收检查后办理并请求客户确认。未手工改写模型正文。准确币种及费用分配未由订单接口返回，保留内部核算项，未编造净退款金额。

同日六项真实模型合成回放均通过阶段及独立审核验收：confirmed_context→offer_options、missing_condition→collect_conditions、outside_window→exception_review、conditional_acceptance→exception_review（保留 conditional_acceptance，未当作无条件接受）、accepted_offer→send_instructions、already_shipped→track_return。合成 accepted_offer 无完整地址，输出明确标记内部待准备；通过阶段判断不代表可直接发送执行指引。初次有条件接受验收曾把“继续协商”和“例外核查”错误限定为单一路径，现按业务含义允许二者，仍禁止跳到已接受阶段。

优先复用 TicketRuntimeContext、TicketFactResolver、TicketPolicyRepository 和现有人工审核。现有 n8n 保留原用途，SDK 通过独立的手动入口运行，不自动切换全量派工。

验证：真实 SDK Runner 的确定性多轮/修订/失败/引用测试；Laravel 范围、文件、指纹、保存、人工审核回归。文档工具本地 OCR，仅返回邮寄单据类型、编号和证据局限；无法识别的图片明确交人工核实，不能据此声称完成视觉鉴定。无已配置的退运/仓库接口时明确返回能力缺口，不拿原始订单物流冒充退件轨迹。

2026-10-25 本地真实验收（使用已有 OmniRoute 通道，OpenAI Agents SDK 0.21.0）：

- Ticket 10949 / WorkItem 362：完成 10 次工具调用，读取独立附件事件中的 PDF，识别 USPS 邮寄参考号；两个受保护链接明确返回 access_required。最新客服已经充分回复，因此独立复核批准 no_reply，保存案件判断，不创建重复邮件。
- Ticket 12292 / WorkItem 363：完成 13 次工具调用，读取会话、订单、退货策略和模板；自主生成英文及中文邮件，独立复核通过，保存 ConversationDraft 254（source=openai_agents，待人工审核，sent_at 为空）。未手工改写模型输出。
- 实际运行中发现并修复：独立附件事件引用未纳入判断上下文；策略不适用导致整次中止；模型误写 message: 引用前缀；详情页只查询旧 ticket_draft 类型而隐藏 SDK 任务。格式问题允许一次受证据约束的修订，仍需独立复核，不能把不存在的引用转换成有效证据。

这些 ID 是本地验收记录，不是生产发布证明。OpenAI 直连通过独立 provider 配置支持，尚未用直连密钥实测。当前无发送、退款、取消订单或创建跟进任务的工具。
