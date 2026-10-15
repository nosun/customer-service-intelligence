# DressAfford 整单取消常规流程 v2

## 1. 目标与状态

- 版本：`dressafford-whole-order-cancellation-v2-draft`
- 范围：客户要求取消一个订单中的全部商品。
- 目标：收到包含 cancel 主题或内容的请求后，能够确定如何识别意图、查询事实、作出决定、执行取消与退款，并发送与当前状态一致的回复。
- 不在本流程内：取消订单中的部分商品、退货、单纯查询退款进度、询问订单为何被系统取消、订阅或预约取消。
- 审核状态：草案，执行写操作和自动发送前必须由业务负责人确认。
- 知识归属：本文是历史研究形成的领域设计输入；正式规则统一发布到通用知识研究流程的 `KnowledgeArticle`，本文不作为第二套知识库运行。

本流程只定义常规主干。订单号缺失、身份不匹配、站外订单不存在、历史承诺冲突、支付争议、接口异常和政策冲突进入例外流程，不得通过默认值继续执行。

## 2. 案例依据与限制

截至 2026-10-12，数据库中共有 420 个取消 Episode，其中 382 个被识别为整单取消；其中 15 个 Episode 所属 Ticket 因全部订单号无法匹配而被忽略，剩余 367 个整单取消 Episode 进入研究。常规整单样本中观察到：

- 178 个订单最终为已取消，99 个最终为未取消；其余缺少可判定的最终状态。
- 148 个案例记录为全额退款，39 个记录为部分退款。
- 170 个具有有效“请求到退款记录”时长的样本，中位数为 47.09 小时。
- 201 条有完整过程证据的历史回复中，162 条提到取消，146 条提到退款，58 条提到 8–12 天，55 条引用政策，44 条提到暂停处理。

以上数据说明整单取消、退款和暂停制作是高频动作，但只描述历史处理，不能证明该处理符合当前政策。当前 console-new 只读快照通常缺少可靠的历史制作开始时间和完整费用拆分，因此制作中订单暂时不能自动计算退款。

原始专项研究数据已冻结在本地私有归档 `storage/app/private/cancellation-research/archive-v1-2026-10-15.sqlite`。归档用于审计和回归，不再继续写入；新的 Ticket 研究、业务问题确认和知识版本统一进入通用 Decision Case / Knowledge Research 流程。

## 3. 核心原则

1. 邮件主题中的 `cancel` 只是进入取消队列的信号，不构成执行授权。
2. 必须读取正文并区分明确请求与条件式咨询。
3. “收到取消请求”“订单已取消”“退款已发起”“退款已完成”是四个不同事实，不得混用。
4. 决策使用客户首次明确提出取消的时间，而不是客服读取、回复或运行任务的时间。
5. 未取得订单、身份、发货和制作事实前，不承诺能否取消或退款金额。
6. 有可能产生扣款的取消必须先告知准确退款报价，并取得客户明确确认。
7. 每个取消、退款和通知动作都必须幂等、可审计、可重试。
8. 不能因为最终订单已发货，反推客户提出请求时订单已经发货。

## 4. 输入分类

### 4.1 请求类型

| 类型 | 示例 | 是否授权执行 |
|---|---|---|
| `active_request` | “Please cancel order DAD...” | 是，但仍需通过资格检查；产生扣款时需要二次确认 |
| `conditional_request` | “Can I cancel?” “How much would I get back?” | 否，只能查询并提供方案 |
| `ambiguous_request` | 主题为 cancel，但正文没有明确对象或意图 | 否，要求客户澄清 |
| `passive_status_inquiry` | “Why was my order cancelled?” | 否，转取消状态查询流程 |

### 4.2 整单范围判定

只有满足以下任一条件才能标记为 `whole_order`：

- 客户明确要求取消整个订单；
- 客户只提供订单号，没有指定保留或取消某个商品，且正文表达的是整单取消；
- 客户列出的待取消商品覆盖订单全部有效商品，并经业务系统复核。

如果客户点名某件商品、某个 SKU、某个数量，或表示其余商品继续制作，必须转入“订单商品取消”流程，不得调用整单取消动作。

## 5. 必须取得的事实

| 事实 | 来源 | 缺失时处理 |
|---|---|---|
| Ticket、客户消息和首次明确请求时间 | HelpDesk | 无法确定请求时点则转人工 |
| 订单号、站点 | Ticket + console-new | 请求客户补充；查无订单则记录并忽略研究案例 |
| 下单邮箱与请求人身份匹配 | HelpDesk + console-new | 转身份核验 |
| 当前订单状态及是否已经取消 | console-new | 不执行，重试或转人工 |
| 请求时是否已发货 | `shipped_at` 与请求时间比较 | 缺少可靠发货时间则转人工 |
| 请求时是否已开始制作 | 制作系统的历史事件 | 缺失时不得计算制作中退款比例 |
| 商品实付、运费、ShipSafe、加急费 | 订单费用明细 | 缺少拆分时不得自动报价 |
| 已退款金额、退款状态及退款记录 | console-new / 支付系统 | 防止重复退款 |
| 历史客服承诺、特批和争议 | Ticket | 有冲突时转人工 |

## 6. 常规决策树

```text
收到含 cancel 的 Ticket
├─ 正文不是主动或条件式整单取消 → 转其他流程
├─ 订单号/身份/范围不完整 → NEEDS_INFO
└─ 已确认整单取消意图与订单
   ├─ 订单已经取消
   │  ├─ 退款完成 → 回复已有结果
   │  └─ 退款未完成 → 进入退款核对/补偿，不重复取消
   ├─ 请求时已经发货 → 不可取消，转退货资格判断
   └─ 请求时未发货
      ├─ 尚未开始制作 → 全额退款方案
      │  ├─ 明确主动请求 → 批准后执行取消与退款
      │  └─ 条件式咨询 → 告知方案并等待确认
      ├─ 已开始制作 → 计算制作中退款方案
      │  └─ 告知准确退款金额，等待客户明确确认
      └─ 制作状态未知 → 暂停承诺，转人工核实
```

制作中退款仍受官网计时起点冲突影响。在业务方确认使用“订单确认时间”“制作通知时间”还是“实际制作工时”以前，该分支必须人工报价，不得自动应用 100%、80% 或 60%。

## 7. 操作状态机

| 状态 | 含义 | 允许的下一步 |
|---|---|---|
| `received` | 已保存客户请求及首次请求时间 | 识别意图、订单和范围 |
| `needs_info` | 缺少订单号、身份或范围 | 等待客户补充 |
| `facts_pending` | 正在查询订单、制作、物流和退款事实 | 继续查询或转人工 |
| `manual_review` | 存在证据缺口、政策冲突或历史承诺 | 人工给出决定/报价 |
| `quote_ready` | 已计算可退金额，但尚未获得所需授权 | 发送方案；等待确认或批准 |
| `awaiting_customer_confirmation` | 部分退款或条件式请求正在等待客户确认 | 确认后批准；拒绝或超时则关闭请求 |
| `approved` | 取消范围、金额和授权均已确认 | 执行取消 |
| `cancellation_in_progress` | 取消命令已提交 | 查询执行结果，禁止重复提交 |
| `cancelled_refund_pending` | 订单已取消，但退款尚未完成 | 创建或重试退款；通知客户当前状态 |
| `completed` | 取消和应退金额均已核对完成 | 发送最终结果并关闭 Ticket |
| `not_cancellable_shipped` | 请求时已经发货 | 转退货流程 |
| `failed_retryable` | 外部接口暂时失败 | 按同一幂等键重试 |

`completed` 必须同时满足：订单取消事实已确认、应退金额与已退款金额核对一致、退款状态达到业务认可的完成状态。仅有客服回复或订单状态变化不能单独构成完成。

## 8. 暂停制作/发货

取消核查是时间敏感流程。理想流程是在确认订单身份后立即申请一个有时限的履约暂停，避免订单在人工核查期间进入制作或发货。

当前系统尚未确认 console-new 是否存在安全、幂等、可自动解除的暂停接口。因此 v2 第一阶段只能：

1. 将 Ticket 提升为紧急取消队列；
2. 记录首次请求时间；
3. 提醒人工在业务系统暂停订单；
4. 在暂停事实得到确认前，不向客户声称订单已经暂停。

未来增加自动暂停时必须明确暂停对象、最长持续时间、自动解除条件及失败告警，避免客户未确认部分退款时订单被无限期搁置。

## 9. 取消与退款执行协议

### 9.1 执行前检查

- 再次读取订单版本、取消状态、发货状态和已有退款；
- 确认请求范围仍是整单；
- 确认报价未过期，且客户确认对应当前报价版本；
- 生成唯一幂等键，例如 `whole-order-cancellation:{order_id}:{request_id}`；
- 保存批准人、批准时间、政策版本、报价组成和证据快照。

### 9.2 推荐执行顺序

1. 向订单系统提交整单取消命令；
2. 回查并确认订单已进入不可继续制作/发货的取消状态；
3. 以批准报价为上限创建退款命令；
4. 回查退款状态及累计退款金额；
5. 将订单取消与退款分别记入操作日志；
6. 只有核对一致后才发送最终完成邮件。

如果取消成功但退款失败，状态进入 `cancelled_refund_pending`，不得回滚成“未取消”，也不得再次执行取消。退款重试必须使用稳定幂等键，并用累计已退款金额防止超额退款。

### 9.3 当前接口缺口

现有 customer-service-intelligence 对 console-new 只有 `order.read`：

- 能查询订单、商品、当前状态、物流和退款记录；
- 不能暂停履约、提交整单取消或创建退款；
- 当前只读返回缺少稳定的制作历史事件，以及商品价、运费、ShipSafe、加急费的完整拆分。

在补齐并审核写接口以前，本系统最多生成决定草稿和操作清单，由客服在现有后台人工执行，再通过只读接口回查结果。

## 10. 回复规则与模板

所有模板均为候选。变量必须来自当前 Ticket、报价和执行结果，不得从历史案例复制金额、日期或时限。

### T1：已收到明确取消请求

发送时点：已识别整单主动取消，但尚未完成资格检查。

> Subject: We received your cancellation request for order {{order_number}}
>
> Hi {{customer_name}},
>
> We have received your request to cancel order {{order_number}}. We are checking its production, shipping, and payment status before confirming the cancellation and refund details. This message confirms receipt of your request; it does not yet confirm that the order has been cancelled.
>
> We will update you once the review is complete.
>
> Best regards,
> {{agent_name}}

### T2：主题命中但意图不明确

> Subject: Please confirm your request for order {{order_number}}
>
> Hi {{customer_name}},
>
> We saw that your message concerns cancellation. Please confirm whether you want us to cancel the entire order {{order_number}}, or whether you are asking about the available cancellation and refund options only. We will not cancel the order until your request is clear.
>
> Best regards,
> {{agent_name}}

### T3：缺少订单信息

> Subject: Information needed for your cancellation request
>
> Hi {{customer_name}},
>
> Please send us the order number and the email address used to place the order. We need these details to locate the order securely and review the cancellation request.
>
> Best regards,
> {{agent_name}}

### T4：未开始制作，可全额退款

适用于事实已经核实、金额已经计算的主动取消请求。发送前不能只根据最终未发货状态推断“未开始制作”。

> Subject: Cancellation approved for order {{order_number}}
>
> Hi {{customer_name}},
>
> We have confirmed that order {{order_number}} can be cancelled before production. The approved refund is {{refund_amount}} {{currency}}, including {{refundable_components}}.
>
> {{execution_status_sentence}}
>
> Best regards,
> {{agent_name}}

`execution_status_sentence` 必须从以下状态化语句中选择，不能自由混写：

- 尚未执行：`We will send a separate confirmation after the cancellation and refund have been processed.`
- 已取消、退款待处理：`The order has been cancelled, and the refund is now being processed.`
- 退款已创建：`The order has been cancelled, and the refund was submitted to your original payment method on {{refund_submitted_at}}.`

### T5：制作中，部分退款报价待确认

> Subject: Please confirm the cancellation terms for order {{order_number}}
>
> Hi {{customer_name}},
>
> Order {{order_number}} has already entered production. Based on the reviewed cancellation terms, the refundable amount would be {{refund_amount}} {{currency}}. The non-refundable amount is {{non_refundable_amount}} {{currency}} for {{non_refundable_reason}}.
>
> Please reply explicitly to confirm whether you want us to cancel the entire order under these terms. We will not complete the cancellation until we receive your confirmation.
>
> Best regards,
> {{agent_name}}

在取消政策计时起点未确认前，T5 只能由人工审核后发送。

### T6：请求时已发货，不能取消

> Subject: Update on your cancellation request for order {{order_number}}
>
> Hi {{customer_name}},
>
> We confirmed that order {{order_number}} had already shipped when your cancellation request was received, so it can no longer be stopped as an order cancellation. If you would like, we can review whether the order is eligible for return after delivery under the return policy.
>
> Best regards,
> {{agent_name}}

### T7：订单已取消，退款处理中

> Subject: Order {{order_number}} has been cancelled
>
> Hi {{customer_name}},
>
> Order {{order_number}} has been cancelled. The approved refund is {{refund_amount}} {{currency}} and is currently being processed to the original payment method. We will confirm again when the refund record is complete.
>
> Best regards,
> {{agent_name}}

### T8：取消和退款均已核对完成

> Subject: Cancellation and refund confirmed for order {{order_number}}
>
> Hi {{customer_name}},
>
> Order {{order_number}} has been cancelled, and a refund of {{refund_amount}} {{currency}} was submitted to the original payment method on {{refund_submitted_at}}. Your payment provider may need additional time to post the funds to your account.
>
> Refund reference: {{refund_reference}}
>
> Best regards,
> {{agent_name}}

除非业务方已经确认统一时限，模板不得填写“8–10 天”或历史邮件常见的“8–12 天”。

## 11. 自动化等级

| 能力 | 当前建议 |
|---|---|
| 主题与正文识别、整单范围分类 | 可自动运行，结果可复核 |
| 订单只读查询与证据快照 | 可自动运行 |
| 非承诺式 T1/T2/T3 草稿 | 可自动生成；发送仍按现有审核机制 |
| 发货后不可取消判断 | 仅在 `shipped_at <= requested_at` 有可靠证据时自动形成草稿 |
| 未制作全额退款判断 | 需要制作历史事实和费用拆分后才能自动化 |
| 制作中退款报价 | 当前必须人工 |
| 暂停、整单取消、退款写操作 | 当前不支持；补齐写接口后仍需分阶段启用 |
| T7/T8 最终结果邮件 | 必须以取消和退款回查结果为发送条件 |

## 12. 业务确认清单

在把 v2 从草案提升为正式流程前，需要业务方明确：

1. 制作中退款的唯一计时起点及各时间窗口边界；
2. 明确主动全额取消是否可直接执行，还是也需要二次确认；
3. 部分退款是否一律要求客户以新回复明确确认；
4. 收到请求后是否立即暂停制作/发货，以及暂停最长时限；
5. 商品价、运费、ShipSafe、加急费的退款计算和舍入规则；
6. 取消成功但退款失败时的责任人、重试频率和升级时限；
7. 对外可以承诺的取消处理时限及银行入账时限；
8. 哪个系统是取消结果、退款金额和退款完成状态的最终事实来源。

## 13. 实施顺序

1. 先把整单取消与商品取消拆成两个明确类型，禁止共用执行动作；
2. 为整单取消增加上述状态机和报价快照；
3. 补充 console-new 制作历史和费用拆分的只读字段；
4. 用历史 Ticket 离线回放 v2 决策，不执行写操作、不发送邮件；
5. 人工审核误判、漏判、金额差异和回复草稿；
6. 先上线只读建议和人工执行后的结果回查；
7. 最后再分别评审暂停、取消和退款写接口。
