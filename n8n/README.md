# Customer Service n8n

本目录与 Laravel 代码位于同一 Git 仓库，运行产品问答工作流：

- `analysis/qa-analysis-v1`：选择问答并调用 Laravel 的既有分析服务；
- `work/qa-draft-agent-v1`：当前发布版本 `1.1.0`，由 Laravel 后台异步触发，执行分类、事实解析、Policy 检索、英文生成、目标语言翻译、Review、单次 Repair、Final Review，并把草稿回写 Laravel。

n8n 负责 AI 节点与流程编排。业务数据、MiniMax 凭据、Policy、事实白名单、最终安全校验和 Draft 审计仍由 Laravel 管理；工作流不会调用 Preview 或 Confirm。

## 启动

```bash
cp .env.example .env
openssl rand -hex 32
```

把随机值写入 `.env` 的 `N8N_ENCRYPTION_KEY`，并为 `LARAVEL_INTERNAL_API_TOKEN` 配置另一条随机值。Laravel 项目的 `.env` 使用同一个值：

```dotenv
N8N_INTERNAL_API_TOKEN=<same token>
```

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

### 定向测试一条问答

后台 URL 中的编号是远端问答编号。例如 `product-questions/886?site_id=1` 使用：

```dotenv
PRODUCT_QUESTION_SITE_KEY=dressafford
PRODUCT_QUESTION_EXTERNAL_ID=886
```

旧的批量工作流仍可用该变量定向测试。正常人工操作应直接在 Laravel 后台打开该问答并点击 n8n Draft 按钮。

## 停止与升级

```bash
docker compose down
docker compose pull
docker compose up -d
```

`docker compose down` 不删除命名卷。不要运行 `down -v`，除非明确需要删除本地 n8n 数据。
