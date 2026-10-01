# HelpDesk 客户问题分类基线

## 数据范围

- 数据源：HelpDesk `tickets + archive`。
- Conversation：5,790。
- 客户消息：8,434。
- 排除私有消息以及 `spam + trash`。
- 分析方法：可审计关键词规则 `helpdesk-rules-v1`。

## 初次分类结果

| 类别 | 客户消息数 |
| --- | ---: |
| after_sales | 2,393 |
| presales | 1,732 |
| logistics | 462 |
| production | 276 |
| noise | 1 |
| unknown | 3,570 |
| 合计 | 8,434 |

已提取 5,029 组“客户消息 → 下一条客服回复”候选。候选状态默认为 `candidate`，不能直接视为已批准话术或 SOP。

## Ticket 级分类和标签

系统已把消息级规则汇总到 5,790 个 `tickets + archive` Ticket，并保存主分类、多问题标签、紧急程度、回复候选数量和待复核标记。

| Ticket 主分类 | Ticket 数 |
| --- | ---: |
| unknown | 2,145 |
| presales | 1,599 |
| after_sales | 1,522 |
| logistics | 337 |
| production | 186 |
| noise | 1 |

主要问题类型：

- 商品信息咨询：1,632 个 Ticket；
- 退货退款：1,611 个；
- 物流追踪与交付：376 个；
- 取消订单：119 个；
- 订单状态：73 个。

另外标记了 27 个 critical、2,498 个 high 紧急度 Ticket，186 个多问题 Ticket，3,319 个需要复核的 Ticket，以及 2,755 个没有形成回复候选的 Ticket。“没有回复候选”只表示当前配对规则未找到相邻客服回复，不能直接认定客服没有回复。

## 站点覆盖

| 站点归属 | 分类消息 | 回复候选 |
| --- | ---: | ---: |
| dressafford.com | 4,162 | 1,638 |
| ellenbridals.com | 819 | 629 |
| unknown | 3,453 | 2,762 |

站点归属使用 Team、来源 URL 和品牌文本证据。公共 Team 且缺少唯一证据的 Ticket 保留 unknown，避免错误归属。

## 当前限制

- `unknown` 占比较高，说明现有规则覆盖不足，不代表这些消息没有业务价值。
- 回复候选只表示时间顺序上的相邻问答，需要人工检查是否真正回答了客户问题。
- 历史处理方式不自动成为正式政策；退款、补偿、取消、交期承诺等内容必须单独审核。
- 下一轮应按站点分别抽样审核，并依据误判和 unknown 样本更新规则。
