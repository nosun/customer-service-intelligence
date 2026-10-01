# ChatBot API 能力与接入边界

## 结论

当前使用的是 Text 产品体系中的 ChatBot.com，与已经接入的 HelpDesk.com 属于同一产品生态，但 API、凭据和数据范围相互独立。

官方 ChatBot API 可以完成本阶段最重要的两项只读接入：

1. 读取全部聊天归档，包括没有生成 HelpDesk Ticket 的 Bot 会话。
2. 读取 Story 的完整 JSON；其中 `diagram` 是拖拽编辑器生成的流程树，包含节点类型、节点 ID 和父子关系。

官方文档没有公开 AI Knowledge 网站、文章或文件的数据源管理接口，也没有公开写入 Story `diagram` 的接口。现阶段可以采集、备份和分析这些资料，不能把网页内部接口当成稳定公开 API 来自动修改知识库或流程树。

## 已确认的官方接口

| 数据 | 接口 | 能力与限制 |
| --- | --- | --- |
| Chat 列表 | `GET /v2/chats` | 每页最近 40 条；使用最后一条 `createdAt` 的毫秒时间戳作为 `after` 游标 |
| Chat 详情 | `GET /v2/chats/:chatId` | 返回 Story ID、用户、来源、属性及完整消息/响应结构 |
| Story 列表 | `GET /v2/stories` | 返回 ID、名称、版本、发布状态和集成信息 |
| Story 详情 | `GET /v2/stories/:storyId` | 返回设置、状态、历史和完整 `diagram` 树 |
| Story 创建 | `POST /v2/stories` | 只公开名称和描述字段 |
| Story 更新 | `PUT /v2/stories/:storyId` | 官方文档只声明名称和描述，没有 `diagram` 写入契约 |
| Training 列表 | `GET /training` | 返回未匹配短语、Story ID 和出现次数 |
| Training 写入 | `PUT /training/train`、`PUT /training/train/text` | 可把短语加入指定 User Input 节点；属于写操作，本阶段不调用 |
| Webhook | `/v2/integrations/webhook` | Bot 在运行中调用自有后端并接收回复或属性，不是会话完成通知 |

认证使用 ChatBot Developer Access Token：`Authorization: Bearer <token>`。官方说明该 Token 对账号有完整管理权限，必须只保存在本地环境变量或凭据系统中。

官方文档：

- <https://www.chatbot.com/docs/introduction/>
- <https://www.chatbot.com/docs/archives/>
- <https://www.chatbot.com/docs/stories/>
- <https://www.chatbot.com/docs/training/>
- <https://www.chatbot.com/docs/webhooks/>

## 与 HelpDesk 的数据关系

本地 HelpDesk 全量数据中已识别 899 个 `source.detailedSource = chat` 的 Ticket，共 8,369 个事件。这部分代表进入 HelpDesk 的 LiveChat 会话，不代表 ChatBot 的全部聊天。

HelpDesk Ticket 保存了 LiveChat 的 `referenceID` 和 `integrations.livechat.externalID`；ChatBot Chat 保存 `chatId`、`storyId`、用户、来源及属性。真实 ChatBot 样本采集后，需要检查这些字段能否直接对应。没有稳定 ID 时，只能使用时间、访客属性和消息内容生成低置信度候选，不能自动合并。

## 本地采样实现

配置 `.env`：

```dotenv
CHATBOT_ACCOUNT_ID=primary
CHATBOT_DEVELOPER_ACCESS_TOKEN=
CHATBOT_API_URL=https://api.chatbot.com
```

执行：

```bash
php artisan chatbot:sample --chat-limit=20
```

命令只调用 GET 接口，输出 `storage/app/private/chatbot/api-sample.json`，文件权限为 `600`。样本包含：

- 所有 Story 的完整详情与 `diagram`。
- 最近指定数量 Chat 的完整详情。
- 当前未匹配 Training 短语。

## 本地模型

ChatBot 数据使用独立来源模型，避免把 Bot 的节点响应结构强行压成 HelpDesk 消息事件：

| 表 | 用途 |
| --- | --- |
| `chatbot_stories` | Story 当前名称、版本、发布状态和集成摘要 |
| `chatbot_story_snapshots` | 按内容哈希保存完整 Story、`diagram` 和设置的历史版本 |
| `chatbot_chats` | Chat 用户、来源、属性、时间和保留状态 |
| `chatbot_chat_events` | Chat 中的用户输入、Bot 响应和原始响应结构 |
| `chatbot_ticket_links` | Chat 与 HelpDesk Conversation 的候选或人工确认关联及证据 |

执行 `php artisan chatbot:sync` 后，Story 同时写入版本表并下载为私有 JSON 文件。相同内容不会产生重复快照，流程树变化会生成新快照，便于比较 Bot 调整前后的运行效果。

## Chat 价值与清理边界

所有 Chat 初始为 `retention_status = unreviewed`。后续分析至少区分：

- 客户提出了有效售前问题，适合提炼 FAQ 或调整 Story。
- Bot 未匹配、重复兜底或中途退出，适合诊断 Story。
- 已生成 HelpDesk Ticket，需要保留关联和完整审计证据。
- 只有欢迎消息、测试流量、机器人流量或无用户输入，可列为低价值候选。

`retention_reason` 保存判定依据，`purge_after` 保存最早允许清理时间。正式清理任务必须先有只读报告、保留窗口和人工确认过的分类规则；当前同步过程不执行删除，也不调用 ChatBot.com 删除接口。

AI Knowledge 资料先人工导出清单，确认网页、文章、文件的实际结构与更新频率后再决定同步方式。
