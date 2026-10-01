# 跨系统数据与 API 地图

## 原则

- 已有系统继续作为业务事实源，客服平台不复制其写模型。
- 客服平台保存外部 ID、必要快照、同步游标、Case 关联和审计记录。
- 查询与写操作分离；写操作必须具备权限、幂等键、状态查询和失败重试。
- MVP 优先只读聚合，退款、取消、改地址等操作后置。

## 已确认的初始地图

| 系统 | 主要事实 | 已发现能力 | MVP 用法 |
| --- | --- | --- | --- |
| dorrisbridal | 客户端订单入口、商品问答、客户操作 | PC 端统一业务 API；订单状态、物流查询、商品问答 | 识别站点来源，补充客户侧上下文 |
| tradeManager | 用户、商品、原始订单、邮件模板 | 用户/商品查询、订单读写、商品问答、邮件模板、智能邮件草稿与发送 | 查询客户、订单、商品；评估复用邮件能力 |
| console-new | 终端订单、生产工单、退款、投诉 | 订单查询/修改、Order Item 取消/拆分、退款、投诉、Work Order | MVP 只读获取生产与售后状态 |
| tradeExpress | 运单、物流轨迹、物流异常 | 运单查询、批量轨迹、费用、渠道 | 获取物流状态和异常证据 |
| Gmail | 邮件 Thread、Message、附件、标签 | Gmail API、watch、history.list、send | 第一批沟通渠道 |
| HelpDesk | Ticket、消息事件、客服、团队、标签 | 已完成 Ticket 全量与增量只读接入；能识别 LiveChat 来源和外部引用 | 客服工单与升级后的聊天事实源 |
| ChatBot.com | Bot 聊天归档、Story 流程树、未匹配训练短语 | 官方 API 可读 Chat、Story `diagram` JSON 和 Training；未公开 AI Knowledge 数据源 API，也未公开 Story 流程树写入接口 | 补齐未生成 Ticket 的售前聊天；备份和分析 Bot 运行逻辑 |

## 试点接入状态

| 项目 | 当前状态 | 下一步 |
| --- | --- | --- |
| `dressafford.com` | 已确定为第一批站点 | 确认站点 ID、订单号格式和客户匹配方式 |
| `support@dressafford-service.com` | 邮箱尚未完成交接 | 交接后先建立只读同步；在同步验证完成前不开放发送 |
| HelpDesk | 已完成首次全量与增量同步，含 899 个 LiveChat 来源 Ticket | 将 ChatBot Chat 与 HelpDesk Ticket 做可核验关联 |
| ChatBot.com | 官方接口能力已确认，本地只读采样客户端已完成；Developer Token 尚未配置 | 配置 Token 后采集 Story、Chat 和 Training 真实样本，核对字段及保留范围 |
| 测试环境 | 暂不具备 | 先做离线样本和只读集成；线上测试订单另行制定受控方案 |

## 客服平台需要的统一查询能力

建议最终形成以下内部接口；它们是统一语义，不要求现有项目直接采用这些路径：

```text
find_customer(email, phone, external_id)
find_orders(customer_id, email, order_number)
get_order_context(order_number)
get_production_context(order_number, order_item_id)
get_shipping_context(order_number, tracking_number)
get_refund_and_complaint_context(order_number)
```

统一返回结果需要包含：来源系统、外部 ID、读取时间和可用性状态，避免 AI 把缓存快照当成实时事实。

## 写操作边界

MVP 默认不直接执行以下操作：

- 取消订单或 Order Item。
- 修改地址、尺码、颜色。
- 创建退款或补偿。
- 重新发货或修改物流渠道。

后续接入时采用：

```text
提出动作 -> 业务规则校验 -> 人工审批 -> 幂等调用 -> 查询最终状态 -> 写入审计
```

## 需要业务方配合确认

- [ ] 每个系统的业务负责人和技术负责人。
- [ ] 各环境地址：本地、测试、生产。
- [ ] 是否已有专供系统间调用的账号或 Token。
- [ ] 哪个系统是客户、订单、生产、物流、退款的最终事实源。
- [ ] 同一订单号在不同站点是否可能重复。
- [ ] 客服目前在哪个系统执行取消、退款、投诉和改地址。
- [ ] 可用于联调的测试订单与测试邮箱。
- [ ] HelpDesk 当前账号套餐、团队可见范围及历史数据保留范围。
- [ ] ChatBot Developer Access Token、Story 数量、聊天归档保留范围和 AI Knowledge 资料来源清单。
- [ ] Gmail 邮箱的 Workspace/个人账号类型、管理员和可授权方式。
- [ ] `dressafford.com` 订单号格式及其在四个系统中的映射关系。
