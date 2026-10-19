# Customer Service n8n

本目录与 Laravel 代码位于同一 Git 仓库，运行产品问答工作流：

- `analysis/qa-analysis-v1`：选择问答并调用与批量命令相同的 `ProductQuestionBatchAnalyzer`；单条重跑只是批量分析器的一条记录，不再维护第二套生产分析入口；
- `work/qa-draft-agent-v1`：当前发布版本 `1.1.0`；候选版本 `1.2.0` 处于 `evaluating`。它由 Laravel 后台异步触发，执行 clause 拆分与分类、事实解析、Policy 检索、英文生成、目标语言翻译、Review、单次 Repair、Final Review，并把草稿回写 Laravel。
- `work/ticket-draft-v1`：Ticket 在线运行中心候选版本 `1.0.0`。当前只处理订单/生产进度和物流进度，使用阶段截止上下文、console-new 只读事实和 verified Policy，完成 Draft、Review/Repair 后只保存本地草稿。

Ticket Workflow 的发布验证使用 `resources/data/ticket-draft-replay-v1.json` 和 `tickets:run-n8n-draft-replay`。回放仍走真实 n8n Webhook，但 Fact Gateway 只读取 checksum 绑定的冻结 Fixture，不访问实时订单状态；回放 Case/WorkItem 使用独立类型和队列，默认运行后清理。

`1.2.0` 增加确定性回放 fixture、缺失意图纠正、多意图覆盖校验、风险矩阵、商品标题/SN 清理和无 Action Proposal 时的后续动作承诺拦截。Policy 未经人工确认时只生成保守的 blocked Draft，不会被当成 verified Policy 使用。

n8n 负责 AI 节点与流程编排。业务数据、MiniMax 凭据、Policy、事实白名单、最终安全校验和 Draft 审计仍由 Laravel 管理；工作流不会调用 Preview 或 Confirm。

运行中心与分析中心当前采用逻辑隔离、同仓部署：`work/*` 只读取已发布的 verified Policy；`analysis/*` 只生成分析结果、证据和候选，不发布 Policy，也不执行业务写操作。生产环境应把两类 Workflow 放入不同 n8n Project，并分别限制环境变量和执行权限。物理拆库、拆仓和 Temporal 迁移要等 Fact、Policy 与 Workflow 契约稳定后再进行。

Ticket 的研究资产与在线运行保持硬边界：`DecisionCase`、`DecisionNode`、Research Finding 和历史客服回复不能被在线 Workflow 当作 Policy；只有人工发布的 `verified KnowledgeArticle` 可以进入运行中心。运行说明见 `docs/runbooks/ticket-n8n-shadow-workflow.md`。

工厂能力聚合命令现在只更新证据卡片，不再隐式创建或更新 Policy 候选。`product-questions:import-factory-capability-policies` 和 `product-questions:import-draft-policy-reviews` 仅用于当前存量数据的一次性/人工迁移，不进入 Scheduler；正式 Policy 的唯一发布入口是后台 Draft → Review → Publish 及其不可变版本记录。

生产分析统一使用 `product-questions:classify-batch` / `ProductQuestionBatchAnalyzer`。`product-questions:analyze` 必须显式提供 `--question`，仅保留给后台单条诊断和历史详细回复质量分析，不能再选择或覆盖一批数据。

## 启动

```bash
cp .env.example .env
openssl rand -hex 32
```

把随机值写入 `.env` 的 `N8N_ENCRYPTION_KEY`，并为 Work、Analysis 分别生成内部 API Token。Laravel 项目的 `.env` 配置对应值：

```dotenv
N8N_WORK_INTERNAL_API_TOKEN=<same work token>
N8N_ANALYSIS_INTERNAL_API_TOKEN=<same analysis token>
```

n8n 目录的 `.env` 分别使用 `LARAVEL_WORK_INTERNAL_API_TOKEN` 和 `LARAVEL_ANALYSIS_INTERNAL_API_TOKEN`。旧的 `N8N_INTERNAL_API_TOKEN` / `LARAVEL_INTERNAL_API_TOKEN` 仅作为升级期间的兼容回退，新部署不应继续共用。

从仓库根目录启动 Laravel（默认端口 8000）并迁移数据库：

```bash
php artisan migrate
php artisan serve
```

启动 n8n：

```bash
cd n8n
docker compose up -d
docker compose logs -f n8n
```

已有实例的加密密钥已经保存在 `n8n_data` 卷的配置文件中，普通升级使用上面的命令，不要用另一个值覆盖。全新生产实例必须在第一次启动前设置 `N8N_ENCRYPTION_KEY`，并显式加载生产覆盖文件：

```bash
docker compose -f compose.yaml -f compose.production.yaml up -d
```

首次打开 `http://localhost:5678` 完成本地 Owner 设置，然后导入 `workflows/*.json`。工作流从环境变量读取站点、Laravel 地址和 Token，无需建立含业务密钥的 Credential。

## 运行与验收

1. 在 Laravel 后台问答详情点击“通过 n8n 生成 Draft”。
2. 页面立即返回并显示 queued/running，每 5 秒刷新；浏览器关闭不影响 n8n 后台执行。
3. 在 n8n Execution 中检查各节点输入输出。
4. 完成后在 Laravel 后台检查 Draft；Draft 必须仍处于人工审核边界，线上问题不得变成已回复。

AI 和内部 API 节点会有限重试。失败会回写 `product_question_draft_requests.status=failed` 与错误信息；页面允许重新发起。Laravel Scheduler 每分钟执行 `product-questions:reap-draft-requests`，重试滞留 queued 请求并回收超时 running；页面打开时仍保留兜底检查。

普通“生成 Draft”按完整输入指纹复用已完成结果；“强制重新生成”才创建新的 n8n Execution。问题、Analysis、商品快照、站点配置、verified Policy、Workflow/Prompt/模型任一变化都会产生新输入，运行中的变化会使请求转为 `stale`。

工作流向 Laravel 发送 n8n `execution.id`。Laravel 使用 `workflow_key + workflow_version + execution_id + question_id` 幂等并记录到 `n8n_workflow_executions`。

导入工作流后注册发布版本：

```bash
php artisan n8n:register-workflow work/qa-draft-agent-v1 1.2.0 n8n/workflows/work__qa-draft-agent-v1.json \
  --status=evaluating --n8n-id=<n8n-workflow-id> --prompt-version=n8n-qa-draft-agent-v4
```

`active` 发布现在必须经过真实 n8n 回放门禁。先选择一个已同步、事实较完整的商品作为固定上下文，运行 50 条回放：

```bash
php artisan product-questions:run-n8n-draft-replay \
  --site=dressafford --product-sn=<stable-product-sn> \
  --workflow-version=1.2.0 \
  --output=storage/app/product-question-replays/1.2.0-predictions.json
```

排查失败案例时可重复传入 `--case=pq-003 --case=pq-047` 做定向回放；正式门禁仍必须使用完整 50 条结果。

Runner 通过真实 Webhook 创建隔离的回放问答和 Draft Request，并输出 Execution ID、Draft、事实、Policy、耗时和错误。同时生成 `*-review.json`；人工逐条填写 `fact_error`、`policy_violation`、`accepted_as_is` 和可选备注。回放记录默认保留审计，但会设为离线，不出现在正常问答列表；仅临时测试时使用 `--cleanup`。

人工复核后生成发布报告：

```bash
php artisan product-questions:gate-draft-release \
  storage/app/product-question-replays/1.2.0-predictions.json \
  --annotations=storage/app/product-question-replays/1.2.0-predictions-review.json \
  --workflow-version=1.2.0 \
  --report=storage/app/product-question-replays/1.2.0-release-report.json
```

门禁要求 50 条预测和人工事实/Policy 复核全部覆盖、Workflow 失败为零、事实错误为零、Policy 违规为零、必须阻止的案例全部阻止，并且漏意图率不超过 2%。通过后才允许激活完全相同 checksum 的 Workflow：

```bash
php artisan n8n:register-workflow work/qa-draft-agent-v1 1.2.0 n8n/workflows/work__qa-draft-agent-v1.json \
  --status=active --n8n-id=<n8n-workflow-id> --prompt-version=n8n-qa-draft-agent-v4 \
  --release-report=storage/app/product-question-replays/1.2.0-release-report.json
```

运行健康和人工反馈可以分别检查：

```bash
php artisan product-questions:draft-workflow-health --hours=24 --fail-on-unhealthy
php artisan product-questions:draft-feedback-report --days=30
```

后台人工审核不再需要直接编辑 JSON。把 Runner 生成的预测导入审核台：

```bash
php artisan product-questions:import-draft-replay-review \
  storage/app/product-question-replays/1.2.0-predictions.json \
  --workflow-version=1.2.0
```

在 `admin/workflow-replay-reviews` 逐条核对事实、Policy 和最终 Draft，完成后可从页面运行发布门禁。生产部署、备份、监控、Token 轮换和回滚见 `docs/runbooks/product-question-n8n-production.md`。

### 定向测试一条问答

后台 URL 中的编号是远端问答编号。例如 `product-questions/886?site_id=1` 使用：

```dotenv
PRODUCT_QUESTION_SITE_KEY=dressafford
PRODUCT_QUESTION_EXTERNAL_ID=886
```

正常人工操作应直接在 Laravel 后台打开该问答并点击 n8n Draft 按钮。旧的 `work/qa-draft-v1`、租取接口和 PHP Draft Generator 已移除，不再提供双轨生成入口。

## 停止与升级

```bash
docker compose down
docker compose pull
docker compose up -d
```

`docker compose down` 不删除命名卷。不要运行 `down -v`，除非明确需要删除本地 n8n 数据。
