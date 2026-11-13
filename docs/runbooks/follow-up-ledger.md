# 跟进台账（第一版）

后台入口：`/admin/follow-ups`，菜单“跟进台账”。Ticket 详情页可以直接“加入跟进 / 查看台账”。

新建只必填标题；备注、关联信息、负责人均选填。网页默认由当前登录人负责，默认明天北京时间 10:00 跟进。可记录订单逾期、运单异常、回复后仍未解决的 Ticket，也可记录其他事项。订单操作仍在 console-new，客户消息仍在 HelpDesk 发送。

## 人工使用

1. 写一句要跟进的事情，按需补充当前情况，点击“加入跟进”。
2. 每次处理后，记录新进展，可同时修改状态和下次时间。
3. 状态为 `open` 待处理、`active` 处理中、`waiting` 等待中、`closed` 已结束。结束后可重新打开。
4. “已到期”包含等待中的事项；已结束事项不进入待办。清空下次时间后，可从“未安排时间”找到。
5. 从 Ticket 进入时先检查已有事项；同一问题继续增加记录，独立问题可以另建。Ticket 关闭或回复不会自动结束事项。

## AI / 本地命令

在项目目录执行 `php artisan follow-ups`。所有命令输出 JSON，失败返回非零退出码。AI 可在用户授权的任务范围内读取、建立事项、更新状态和追加记录。无需直接操作数据库，也不需要网络 API 或新增密钥。

```sh
# 默认读取未结束事项；支持分页和搜索
php artisan follow-ups list --view=due
php artisan follow-ups list --view=all --search=DA123 --page=1 --limit=30
php artisan follow-ups list --conversation-id=123

# 新建，只需标题。--actor 记录来源，默认 ai:codex
php artisan follow-ups create --data='{"title":"DA123 逾期，确认工厂发货时间","reference":"DressAfford / DA123","note":"已向工厂查询，等待回复。"}'

# 读取事项和日志；日志默认每页 30 条，最新在前
php artisan follow-ups show 1
php artisan follow-ups show 1 --page=2

# 使用刚读取到的 data.version，避免覆盖用户或其他 AI 的最新修改
php artisan follow-ups update 1 --expected-version=1 --data='{"status":"waiting","note":"工厂反馈明天完工，明天下午确认发货。","next_follow_up_at":"2026-10-28 15:00"}'

# 只追加记录也支持；每次成功更新 version 都会增加
php artisan follow-ups update 1 --expected-version=2 --data='{"note":"再次催办，尚未收到回复。"}'
php artisan follow-ups update 1 --expected-version=3 --data='{"status":"closed","note":"已确认发货并通知客户，此次生产跟进结束。"}'

# 较长或含特殊字符的内容先写 JSON 文件，避免 shell 转义问题
php artisan follow-ups create --input=/tmp/follow-up.json
```

支持字段：`title`、`note`、`status`、`urgent`（布尔）、`reference`、`owner`、`conversation_id`（本地 Ticket ID，不是 HelpDesk 编号）、`next_follow_up_at`。更新仅改变传入字段；`note` 追加为不可编辑的日志，并更新最近进展。清空时间使用 `"next_follow_up_at":null`。新建未传时间则默认明天 10:00；新建显式传 null 则不安排时间。

未指定时区的输入时间按北京时间解释；带偏移量的 ISO 时间按其指定时区解释。数据库和 JSON 时间为 UTC，网页显示北京时间。日期筛选按北京时间计算。

每次写入自动保留操作者、时间和字段前后变化。网页从登录身份生成操作者；命令中的 actor 是本地操作归属标记，不是独立的 AI 身份认证。`version` 冲突时需重新读取，再决定更新内容；不要盲目重试旧数据。

第一版不自动调查、抓取邮件或创建业务异常，不自动发送消息、取消订单或退款，也不自动结束跟进。台账中的人工和 AI 备注是处理记录，不能替代来源系统的实际执行结果。

部署时运行常规迁移：`php artisan migrate`；表为 `follow_ups` 和 `follow_up_entries`。
