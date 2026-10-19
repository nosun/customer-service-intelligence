# Product Question n8n 生产运行手册

当前 Draft 的服务策略、内容审核和排版职责见[流程说明](../architecture/product-question-draft-workflow.md)。本地已启用的候选流程不等于已通过生产发布门禁。

## 发布前

1. 备份 Laravel 数据库与 n8n 数据卷；确认可以从备份恢复。
2. 使用不同随机值配置 `N8N_WORK_INTERNAL_API_TOKEN`、`N8N_ANALYSIS_INTERNAL_API_TOKEN`、`N8N_WEBHOOK_TOKEN`。全新 n8n 实例在第一次启动前配置 `N8N_ENCRYPTION_KEY` 并使用 `compose.production.yaml`；已有数据卷必须继续使用卷内原密钥，不能直接替换。
3. 运行 Laravel migration、清理并重建配置缓存，确认 Scheduler 和 Queue Worker 常驻。
4. 将 `work/*` 与 `analysis/*` 放入不同 n8n Project，仅授予所需环境变量；不向 n8n 提供业务数据库、tradeManager 或 console-new 凭据。
5. 导入仓库内 Workflow JSON，校验 definition checksum。候选版本先注册为 `evaluating`。
6. 将当前固定回放集的实际生成结果导入后台，完成事实、Policy 与回复质量人工审核；不要把样本数量或程序测试通过当作质量验收。只有门禁报告 `passed=true`，且 checksum 与待发布定义一致，才允许注册为 `active`。

## 部署

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan product-questions:draft-workflow-health --hours=24 --fail-on-unhealthy
```

n8n 保留最近 168 小时 Execution 数据，生产环境必须定期备份数据卷。升级前停止新触发、等待 running 请求结束，备份后再拉取固定版本镜像。禁止使用 `docker compose down -v`。

全新生产部署使用：

```bash
docker compose -f compose.yaml -f compose.production.yaml up -d
```

已有实例普通升级只使用 `compose.yaml`。如确需轮换 n8n 加密密钥，必须先按 n8n 官方密钥轮换流程迁移凭据，不能只修改环境变量。

## 上线冒烟

1. 使用一个低风险、未回复的公开商品问题发起 Draft。
2. 确认页面经历 queued → running → completed，并记录 n8n Execution ID。
3. 检查 Draft 事实来源、命中 Policy、可见性和安全提示。
4. 人工修改后执行 Preview；仅在远端 Preview 差异正确时 Confirm。
5. 确认 Draft feedback、Work Item 和远端回答均能回查。

## 监控

Scheduler 每五分钟执行：

```bash
php artisan product-questions:draft-workflow-health --hours=1 --fail-on-unhealthy
```

非零退出码表示存在超过两分钟的 queued 或超过十分钟的 running 请求。生产进程管理器应把非零退出、失败率和连续超时接入告警。人工反馈每周检查：

```bash
php artisan product-questions:draft-feedback-report --days=7
```

## Token 轮换

1. 生成新 Token，先更新 Laravel 与对应 n8n Project。
2. 重启服务并执行一条低风险冒烟。
3. 确认新 Token 正常后删除旧 Token。Work 与 Analysis 分别轮换，禁止重新合并为共享 Token。

## 回滚

1. 停止候选 Workflow 的新触发。
2. 将上一个已通过门禁且 checksum 匹配的版本重新注册为 `active`。
3. 对仍处于 queued/running 的请求执行 reaper；不得把旧输入结果写回已变化的问题。
4. 回查远端 Preview/Confirm。Draft 回滚不等于撤回已经 Confirm 的业务回复。
