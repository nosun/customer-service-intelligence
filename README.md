# Customer Service Intelligence

基于 Laravel 13 的本地客服邮件采集与分析 MVP。当前只读取 Gmail 邮件，不发送邮件，也不执行退款、取消、改地址或订单修改。

## 环境

- PHP 8.4+
- Composer
- SQLite
- Google Workspace CLI (`gws`，仅真实 Gmail 同步时需要)

## 初始化

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
chmod 600 database/database.sqlite
php artisan migrate
```

数据库默认保存在 `database/database.sqlite`，不会提交到 Git。

## 使用测试样本验证

```bash
php artisan gmail:sync \
  --mailbox=support@dressafford-service.com \
  --fixture=tests/fixtures/gmail-threads.json

php artisan gmail:analyze

php artisan knowledge:export \
  --output=storage/app/private/demo-candidate-reply-pairs.csv

php artisan test
```

`gmail:sync` 可以安全重复执行；同一 Gmail Thread 和 Message 会更新，不会重复创建。

## Gmail 授权完成后的只读同步

首次授权由邮箱账号持有人执行：

```bash
gws auth login --readonly --services gmail
gws auth status
```

然后执行：

```bash
php artisan gmail:sync \
  --mailbox=support@dressafford-service.com \
  --query='in:anywhere' \
  --page-limit=10
```

如果本机需要隔离多个 Gmail 账号，请分别指定配置目录：

```bash
php artisan gmail:sync \
  --mailbox=support@dressafford-service.com \
  --gws-config-dir="$PWD/storage/app/private/gws/dressafford" \
  --page-limit=2
```

第一次真实同步建议先限制日期或 Gmail 标签，并把 `--page-limit` 设为较小值。

## 当前分析能力

`gmail:analyze` 使用可审计的关键词规则完成初步分类，并提取“客户来信 → 客服回复”的候选回复对。结果只是待审核的知识素材，不会自动成为 SOP。

当前分类：

- `presales`
- `production`
- `logistics`
- `after_sales`
- `unknown`

`knowledge:export` 将候选回复对导出为 CSV，供人工审核和后续规则归纳。邮件模板 CSV 暂不预设固定格式；拿到真实文件后，再按实际表头编写导入映射，避免丢失信息。

## 代码结构

- `app/Services/Gmail`：`gws` 对接、Gmail 内容解析和幂等同步
- `app/Services/Analysis`：规则分类和候选回复对提取
- `app/Console/Commands`：同步、分析和导出命令
- `app/Models`：邮件及知识候选数据模型
- `database/migrations`：SQLite/MySQL 可迁移的数据结构
- `tests/Feature/GmailWorkflowTest.php`：完整样本工作流回归测试
