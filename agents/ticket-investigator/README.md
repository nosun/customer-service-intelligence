# Ticket logistics Agent 实验

现已新增完整 Ticket 辅助流程，原有物流调查实验入口继续保留。新流程可自主读会话、记录计划、读取策略和订单、检查附件、判断是否回复、拟定中英文邮件，经独立复核及至多一次修订后保存到本地待人工审核入口。

Return 首版已采用可执行的六阶段定义、证据/能力目录、节点完成标准和异常出口。运行 `php artisan tickets:return-flow-check` 可离线检查结构、19 个路由案例、语境覆盖和生成文档；实际模型验证另用 `tickets:agent-evaluate-returns`。见 [流程图](../../docs/workflows/return-flow.md) 与 [维护规范](../../docs/runbooks/return-flow-maintenance.md)。

在 Ticket 详情页点击 **Agents SDK 调查并拟稿**，或从 Laravel 根目录运行：

```bash
php artisan tickets:agent-assist 10949 --actor=1 --provider=omniroute
```

`10949` 为本地 `conversations.id`，`--actor` 为本地管理员 ID。模型沿用已有 OmniRoute/MiniMax 配置；直接使用 OpenAI 时设置 `OPENAI_API_KEY` 与 `TICKET_AGENT_OPENAI_MODEL`，命令选择 `--provider=openai`。页面按钮默认使用 `DRAFT_AI_PROVIDER`。真实兼容通道和 OpenAI 直连需分别验收。

新流程使用 `assistant.mjs` / `assistant-cli.mjs`，Laravel 持有业务权限，Node 通过按案件限定的管道请求工具。每次最多 24 次工具调用、600 秒；云 Tracing 关闭。PDF 需本机 `pdftoppm` 和 `tesseract`，可通过 `TICKET_AGENT_PDFTOPPM_BINARY` / `TICKET_AGENT_TESSERACT_BINARY` 指定路径。邮寄文件只提取安全编号及单据类型，不提供通用图片鉴定。受保护链接、未接入的退件物流或仓库查询明确列为缺口。

退货流程现先形成有引用的条件与阶段判断，再读取 RET-01/02/03 等对应模板；调查在模板取得后结束，交专门拟稿 Agent 和独立 reviewer。页面显示每项判断和 ready / internal_review / holding 准备情况，后两种不能直接采纳。当前网站政策只从配置的固定 URL 读取；缺少金额、地址和执行结果时明确保留缺口，不编造。

可运行 `php artisan tickets:agent-evaluate-returns` 用真实模型回放六种合成语境，或 `--case=confirmed_context` 选择一项。回放工具只有固定合成证据，不连接真实业务系统，输出位于 `storage/app/private/ticket-agent-evaluations/`。

结果保存于 `WorkItem` 与 `ConversationDraft(source=openai_agents)`，原始证据及复核记录另存 `storage/app/private/ticket-agent-runs/`（0600）。`no_reply` 只保存判断，不生成重复邮件；`blocked` 草稿不能直接采纳。输入或策略变化、事实过期会令旧结果失效。此入口不会发送邮件或执行退款、取消订单等动作。完整说明见 [SDK 工作流](../../docs/architecture/ticket-agent-sdk-workflow.md)。

以下为原有物流调查实验说明。

职责与后续 n8n 接入见 [开发方案](../../docs/architecture/openai-agents-sdk-integration.md)。仅生成内部调查报告；工具读取当前调用冻结的快照。模型不会访问 DB、外部业务凭据或执行业务动作。

## 安装与测试

需要 Node.js 22+、现有 Laravel PHP 环境。

```bash
cd agents/ticket-investigator
npm ci --ignore-scripts
npm test
```

从 Laravel 项目根目录运行：

```bash
# 合成资料 + 真实模型，复用现有 DRAFT_AI_PROVIDER 配置
php artisan tickets:agent-investigate --fixture=resources/data/agent-logistics-delivered-synthetic.json

# 对照：运输中、缺少 ETA，不应要求提供签收证明
php artisan tickets:agent-investigate --fixture=resources/data/agent-logistics-in-transit-synthetic.json

# 使用当前已有的另一模型通道
php artisan tickets:agent-investigate --fixture=resources/data/agent-logistics-delivered-synthetic.json --provider=omniroute

# 先只检查某一真实 Ticket 的脱敏输入，不调用模型
php artisan tickets:agent-investigate --conversation=123 --prepare-only

# 同一 Ticket 重新查询并生成新的调查报告
php artisan tickets:agent-investigate --conversation=123
```

`--conversation` 是本地 `conversations.id`，不是外部 Ticket 编号。输入、输出保存于 `storage/app/private/agent-investigations/`，文件权限 0600；不会写入 Draft/WorkItem/Workflow 或发送回复。`--fixture` 只允许显式 `synthetic=true` 的合成快照，命令会刷新合成事实的时间；真实资料应走 `--conversation` 的身份及脱敏入口。

模型沿用 Laravel 的 `services.minimax` / `services.omniroute`，密钥经子进程环境注入，不写进输入、报告或命令参数。使用 Chat Completions 适配器，不改变既有模型客户端；工具调用与 JSON Schema 能力必须按实际通道验证。云 Tracing 关闭；本地报告保留工具轨迹和 usage。

运行状态 `needs_review` 表示技术链路已完成、报告待人工核查；`blocked` 表示身份、查询或政策不足。模型调用/协议错误返回失败退出码，不伪装成完整报告。默认 6 个模型 turn、6 次工具调用、180 秒总时限，每轮最多 3000 输出 token；不是美元费用硬限额。

没有开放 HTTP 端口；n8n 尚未接入此实验。下一阶段通过专用、单案件受控接口接到既有 Start/lease 与 reply-plan，见开发方案。
