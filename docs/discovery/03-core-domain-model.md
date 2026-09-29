# 核心数据模型草案

## 领域关系

```text
Channel Account
  -> Conversation
       -> Message
            -> Attachment

Customer
  -> Customer Identity
  -> External Entity Link -> Order / Order Item / Work Order / Shipment

Conversation <-> Case
Case -> Task / Action / Outcome / Knowledge Reference / AI Run
```

## MVP 核心实体

### channel_accounts

一个具体邮箱或聊天账号。保存渠道类型、站点、外部账号 ID、同步状态和凭据引用。实际 Token 不直接存明文。

### conversations

渠道中的完整会话，例如 Gmail Thread 或 Freshchat Conversation。保存外部会话 ID、主题、参与者和最后活动时间。

### messages

不可变的原始沟通记录。保存方向、发送者、接收者、正文、时间、外部 Message ID 和去重哈希。

### customers / customer_identities

Customer 是平台中的统一客户；Identity 保存 email、phone、站点用户 ID、LiveChat/Freshchat ID 等身份。身份自动合并必须可撤销。

### cases

客服业务问题。保存类型、状态、优先级、风险、负责人、SLA、摘要和解决结果。

### external_entity_links

把 Case 关联到已有系统实体，只保存系统名、实体类型、外部 ID 和必要显示快照，不在本平台重建订单模型。

### case_actions / case_outcomes

分别记录客服采取的动作和最终业务结果，用于后续 SOP 与效果分析。

### knowledge_items

区分 draft、reviewed、approved、retired。正式回复时只能把 approved 内容当成公司规则。

### ai_runs

记录任务类型、模型、Prompt 版本、输入引用、结构化输出、Token/成本、耗时、错误和人工修订结果。

## 必须保留的审计信息

- 谁查看、修改、审批或发送了内容。
- AI 使用了哪些订单、物流和知识证据。
- AI 草稿与最终发送版本的差异。
- 外部写操作的幂等键、请求结果和最终状态。
- 客户身份或 Case 关联被人工纠正的历史。

## 待确认的关键决策

- [ ] Customer 是跨站点统一，还是每个站点独立。
- [ ] 一个 Conversation 能否关联多个 Case。
- [ ] 一个 Case 能否关联多个订单。
- [ ] 邮件原始 MIME、正文和附件分别保留多久。
- [ ] 是否需要删除/匿名化客户个人数据的流程。
- [ ] 客服能否查看所有站点，还是按站点隔离。
- [ ] 哪些字段允许 AI 使用，哪些字段必须脱敏。

## 试点阶段默认假设

- `site_key` 使用 `dressafford.com`，但数据模型仍保留多站点字段。
- 第一批只接入 `support@dressafford-service.com` 的客户来信。
- 邮件同步与分析默认只读；发送能力不作为首个里程碑。
- 取消、退款、改地址、加急和补偿都先记录为“人工决策与动作”，不由 AI 或平台自动执行。
- 在客服交接完成前，不把历史处理方式自动提升为 Approved SOP。
