# Alberich Bot Story 初步分析

## 分析范围

本分析基于 2026-09-30 通过 ChatBot API 下载的 `Alberich Bot` Story JSON，以及同次读取的 40 条未匹配 Training 短语。Story API 返回流程结构和节点名称，没有返回每个 Bot Response 的实际展示文案，因此可以判断覆盖范围、分支和升级路径，暂时不能核对政策原文是否准确。

## 整体结构

`Alberich Bot` 是当前主要运行 Story，已发布，并连接 HelpDesk、OpenWidget 和 Widget。流程包含：

| 节点 | 数量 |
| --- | ---: |
| User Input | 89 |
| Bot Response | 113 |
| Ask a Question | 18 |
| HelpDesk Ticket | 11 |
| Close Chat | 15 |
| Go To | 4 |

Story 使用英文、Production 模型，匹配置信度为 `0.81`，Training 置信度为 `0.84`。`knowledgeExtended` 和 `knowledgeUrl` 均为 `false`，表明当前 API 快照没有启用扩展知识或 URL 知识标志。

## 已覆盖的售前流程

售前覆盖较广，主要包括：

- 商品选择：找礼服、Prom、Wedding Guest、Homecoming、Bridesmaid、Graduation、Catalog。
- 尺码：尺码表、如何确定尺码、定制尺寸、测量视频、Junior Size、其他尺码问题。
- 颜色：色卡、色样、颜色可用性、网页展示颜色、黑色礼服。
- 商品细节：是否支持定制、颜色问题、真实图片、面料弹性、内衣和支撑结构、拖尾及局部结构。
- 价格与下单：Coupon、Payment、Credit Card、PayPal、Order Process。
- 履约预期：可配送国家、配送时间、生产与周转时间、商家所在地。

其中“About an Item”和“Other Sizing Issues”可以进入人工留言或创建 HelpDesk Ticket，说明复杂商品问题已经具备升级路径。

## 已覆盖的售后与订单流程

订单和售后流程包括：

- 登录用户和访客分别查看订单、修改订单、查询物流。
- 未收到物流邮件时可创建 HelpDesk Ticket。
- Cancellation Policy 和 Return Policy 均可创建 Ticket。
- Refund 提供政策说明，并允许留言生成 Ticket。
- 信用卡支付失败经过 PayPal 建议、补充问题、收集客户信息后生成 Ticket。
- Human Support、Other Questions 和默认兜底均可生成 Ticket。

这套流程的主要定位是“静态自助回答 + 必要时收集信息并生成 HelpDesk Ticket”。它没有表现出直接查询订单、生产、物流或退款实时状态的能力。

## 发现的流程问题

### 1. 订单分支存在不一致

89 个 User Input 中有 5 个显示为未配置，全部集中在访客订单和未收到物流邮件的路径：

- `track my order-no emails`
- `View My Order-guest`
- `change my order-guest`
- `track my order-guest`
- 第二个 `track my order-no emails`

这些正是容易进入人工支持的关键路径，需要在拖拽编辑器中确认它们是否只作为按钮入口存在，还是确实缺少训练表达或配置。

### 2. 生成 Ticket 的逻辑重复

Story 中存在 11 套 HelpDesk Ticket 节点。取消、退货、支付失败、物流、访客改单、其他问题、人工支持、商品问题、退款和默认兜底都复制了“收集信息 → 创建 Ticket → 关闭 Chat”流程。

重复流程容易出现字段、提示语和路由规则不一致。若编辑器支持 Go To 或可复用步骤，建议统一成一个 Ticket 创建子流程，并传入问题来源和分类属性。

### 3. 默认兜底直接创建 Ticket

Default Fallback 最终进入 Ticket 创建流程。这样可以避免客户完全失联，但也可能把无意义输入、机器人流量和简单问候转换成工单。后续应结合 Chat 归档统计兜底后真正创建 Ticket 的比例和内容质量。

### 4. 多数回答是静态路径

订单查看、订单修改、物流查询和退款目前主要依赖静态提示、邮件或人工 Ticket。Story 没有 Webhook 节点，也没有看到调用订单、生产或物流系统的实时查询流程。

因此它能降低重复问答，但无法回答“我的订单现在到哪一步”“最新物流状态是什么”这类需要实时事实的问题。

### 5. 多语言与商品编号识别不足

Training 中有 40 条未匹配短语，共出现 45 次。主要问题包括：

- 带商品编号的颜色、结构、实拍图和定制问题。
- 明确的退货、退款进度和退货政策问题。
- 法语、波兰语、西班牙语等非英语输入。
- `live agent`、`help`、`stop` 等导航或转人工意图。
- 只有商品编号或短词的上下文不足输入。

其中一条单字符输入重复 3 次，两个短输入各重复 2 次；其余大多只出现一次。当前重点应放在意图归一化和携带商品编号的问法，而不是逐条增加完全相同的训练短语。

### 6. 知识能力可能未启用

Story 设置显示 `knowledgeExtended=false`、`knowledgeUrl=false`。如果账号页面确实配置了网站政策、文章或文件，需要核对这些资料是否绑定到了正在发布的 `Alberich Bot`，以及 AI Assist 是否实际使用它们。

## 优先建议

1. 在 ChatBot 编辑器中核对 5 个未配置的订单节点，优先修复访客订单、改单和物流邮件缺失路径。
2. 把 11 个 Ticket 创建流程统一，给 Ticket 写入 `story_id`、入口节点、Chat ID、问题类别和客户已填写字段，方便与 HelpDesk 自动关联。
3. 将 Training 短语先归入商品定制、颜色、尺码、退货退款、转人工、多语言和噪音，再补充 User Input 训练；保留原始短语与审核结果。
4. 为订单状态、生产进度和物流查询增加 Webhook，只返回现有业务系统中的只读实时事实；修改订单、取消和退款继续进入人工 Ticket。
5. 统计每个入口的 Chat 数、兜底率、Ticket 转化率、退出率和未匹配率，再决定精简哪些低价值分支。

## 能力边界确认

以下结论根据 2026-09-30 查阅的 ChatBot.com 官方帮助文档，并结合当前 `Alberich Bot` API 快照得出。

### 多语言

ChatBot.com 支持大多数常用语言，但实现方式取决于 Bot 的类型：

- 使用网站或帮助中心知识源自动创建的 AI Bot，可以根据用户开始聊天时使用的语言调整回复语言。
- 从零搭建的可视化流程只会理解创建和训练时使用的语言。固定的 Bot Response 不会自动翻译，需要为每种语言配置回复，并通过用户选择、网站语言属性或 LiveChat Group 属性进入相应分支。
- 网站有明确语言版本时，可以通过 Chat Widget API 的 `setSessionAttributes` 写入语言，再由 Story Filter 选择对应回复。
- System entities 和部分模板仅提供英文版本。

当前 `Alberich Bot` 的 Story 语言为英文，知识标志为关闭，Training 中已有法语、波兰语和西班牙语未匹配记录。因此平台具备多语言能力，但当前 Bot 不能视为已经可靠支持自动语言识别和多语言回答。

### 业务 API

ChatBot.com 的 Webhook Action 可以向外部系统发送当前聊天、用户和会话属性，并接收后端返回的消息及属性。返回属性还可以用于后续 Filter 分支。因此可以接入本站业务 API，处理：

- 订单真实性校验和订单状态查询。
- 生产进度、预计发货时间、物流单号和最新物流状态。
- 取消、退货和退款资格的规则判断。
- 根据订单、站点和问题类型补全 HelpDesk Ticket 上下文。

建议由本项目提供统一的 ChatBot Webhook 网关，再调用订单、生产、物流和退款系统。查询类操作可以直接回复；改单、取消、退款批准等写操作应继续创建 Ticket 或交给人工确认。Webhook 需要校验验证令牌、限制超时、隐藏内部错误，并避免把完整客户资料返回聊天窗口。

当前 Story 没有 Webhook 节点，所以这些能力尚未在 `Alberich Bot` 上启用。官方 Shopify 集成已有通过订单号和邮编查询订单状态的现成流程，也证明订单类实时查询是平台支持的使用方式。

### 多站点

技术上可以把同一个 Widget/Bot 安装到多个网页，并通过 `site_key` 或语言属性分流。但站点政策、品牌语气、订单格式、知识来源和后端 API 不同时，共用一个 Story 会增加误用其他站点政策或数据源的风险。

建议每个站点配置独立 Bot/Story，复用统一的设计规范和 Webhook 后端：

- 每个 Story 只绑定本网站的政策、FAQ、训练短语和 Widget。
- 后端根据不可由访客任意伪造的 Bot/连接标识选择站点配置，不只信任前端传入的 `site_key`。
- 通用意图、Ticket 字段和节点命名保持一致，便于跨站分析。
- 用一个基础 Story 复制新站点流程后再调整政策和接口。

官方复制功能只复制 Bot 结构，不会复制 AI Knowledge、Training、应用集成；复制后的 Go To 节点也需要重新选择。因此复制可以作为初始化手段，但每个新站仍需单独检查知识、训练、集成和全部 Go To。

### AI 提升空间

平台原生 AI Assist 可以基于网站、文章、KnowledgeBase 或 Zendesk 知识生成动态回答，也会参考 Training；Bot 会优先匹配可视化结构，结构没有答案时再搜索 AI 模块。不同 Bot 不共享 AI Knowledge 和 Training，需要分别配置。

适合 `Alberich Bot` 的演进顺序是：

1. 先修复订单关键路径、Ticket 重复流程和无效兜底，建立 Chat、Ticket、Training 的质量指标。
2. 为每个站点补齐经过审核的政策知识，启用 AI Assist 处理开放式售前问题。
3. 使用 Webhook 提供订单、生产、物流和退款的实时事实，让 AI 只负责理解问题和组织回复，不自行编造业务状态。
4. 从历史 Chat 和 Ticket 中提取未覆盖意图、低置信度回答和转人工原因，经过人工审核后回写 Story、Training 或知识文章。
5. 对退款资格、投诉、付款争议和任何改变订单状态的请求保留确定性规则及人工审批。

ChatBot.com 目前还推荐新配置使用 Text 的 AI Agent。该产品使用知识、指令、客户上下文和 Skills 回答问题及执行动作。现有 Visual Builder 和 AI Assist 仍可继续使用。是否迁移应在完成现有数据闭环和 Webhook 验证后单独评估，不应直接替换正在运行的 `Alberich Bot`。

## 官方资料

- [Supported languages](https://www.chatbot.com/help/overview/supported-languages/)
- [How to make your chatbot multilingual](https://www.chatbot.com/help/build-your-chatbot/how-to-create-multilingual-chatbot/)
- [How to set up webhooks](https://www.chatbot.com/help/webhooks/setting-up-your-webhook/)
- [Show Shopify order status](https://www.chatbot.com/help/shopify-integration/show-shopify-order-status/)
- [What is ChatBot.com AI Assist](https://www.chatbot.com/help/artificial-intelligence/what-is-chatbot-ai-assist/)
- [What is AI Knowledge](https://www.chatbot.com/help/artificial-intelligence/what-is-ai-knowledge/)
- [Duplicate your chatbot](https://www.chatbot.com/help/stories/clone-your-story/)
- [Install ChatBot.com using Chat Widget](https://www.chatbot.com/help/install-chatbot/widget-installation/)

## 当前证据限制

- 目前只成功读取 1 条完整 Chat，不能据此评估各分支的实际使用率或转化率。
- Training 是当前 API 返回的未匹配集合，不代表全部历史客户需求。
- Story JSON 没有 Bot Response 实际文案，政策内容仍需从 Chat 实际回复、编辑器或知识资料中核对。
