# Gmail 与 HelpDesk 重合度核对

## 目的和数据粒度

本次只判断 Gmail 历史邮件是否已经出现在 HelpDesk Ticket 消息中，不执行合并或删除。

- Gmail：107 个 Thread、118 封 Message。
- HelpDesk：按 Conversation/Event 保存的 Ticket 消息。
- Gmail 时间范围：2026-06-05 至 2026-09-29。

## 核对方法

采用两种独立证据：

1. 高置信度：标准化 Gmail `Message-ID`，与 HelpDesk Ticket 来源中的 `originalMessageID` 精确匹配。
2. 中等置信度：正文转纯文本、去除引用历史、统一大小写和空白后计算 SHA-256，并校验客户/客服方向一致。

正文哈希存在 3 个一对多碰撞，因此只能作为辅助证据，不能单独用于自动合并。

## 结果

| 指标 | 匹配数 | 覆盖率 |
| --- | ---: | ---: |
| Gmail Message-ID 精确匹配 | 73 / 118 | 61.9% |
| 正文和方向匹配 | 56 / 118 | 47.5% |
| 两类证据合并后的 Gmail Message 覆盖 | 88 / 118 | 74.6% |
| 两类证据合并后的 Gmail Thread 覆盖 | 80 / 107 | 74.8% |

未匹配的 30 封邮件包括：

- 2026-09 的入站邮件 8 封；
- 2026-06 至 2026-07 的出站邮件 22 封。

## 结论

“Gmail 转发进入 HelpDesk，因此 Gmail 大部分已体现在 Ticket 中”的判断有数据支持。当前样本约四分之三可以找到 HelpDesk 对应证据，但仍有四分之一无法可靠匹配，因此暂时保留 Gmail 来源表。

未来建立跨渠道关联时，证据优先级应为：

1. `Message-ID / originalMessageID` 精确相等；
2. 正文哈希、方向和时间窗口同时相符；
3. 主题、发件人和时间窗口仅作为待人工复核的弱证据。

跨渠道关联应保存证据类型、置信等级和人工复核状态，不直接把 Gmail Message 与 HelpDesk Event 强制去重。
