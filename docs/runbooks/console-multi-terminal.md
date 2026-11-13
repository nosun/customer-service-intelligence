# 多套 Console 接入

CSI 按站点选择独立的 Console Admin API。连接名称、站点路由及已核实的订单号前缀位于 [config/console.php](../../config/console.php)，由 `ConsoleNewConnections` 为每次查询创建对应客户端，不在共享客户端上切换凭据。

| 连接名称 | Admin API | 站点 |
| --- | --- | --- |
| `seawave` | `https://console-new.seawavetech.com/api/admin` | `dressafford.com`、`ellenbridals.com` |
| `mydorris` | `https://console-new.mydorris.com/api/admin` | `dorriswedding.com`、`dorrisbridal.com` |

**2026-11-12 用户明确的操作边界：本项目业务自动化仅在 `seawave` 实施；`mydorris` 始终只读，供学习参考，其 AI 处理由另一位同事负责。** 不为 MyDorris 启用邮件、沟通单写入、发布、解除或自动处理任务。新写适配器必须延续该限制，不能借用多终端路由开放通用写入。联系客户一期以 [Seawave 实施计划](../architecture/seawave-customer-contact-phase-one.md)为准。

## 配置

原终端继续使用现有配置；新终端使用自己的 Token，不能将原终端 Token 直接复制过去。凭据只放在服务器 `.env`，不写入代码、文档或研究记录。

```dotenv
CONSOLE_NEW_API_URL=https://console-new.seawavetech.com/api/admin
CONSOLE_NEW_API_TOKEN=
CONSOLE_NEW_API_TIMEOUT=15

CONSOLE_MYDORRIS_API_URL=https://console-new.mydorris.com/api/admin
CONSOLE_MYDORRIS_API_TOKEN=
CONSOLE_MYDORRIS_API_TIMEOUT=15
```

各功能需要独立授权：

| 读取内容 | API 权限及其他条件 |
| --- | --- |
| 订单、退款事实、高优先级订单 | `order.read` |
| 生产工单 | `ticket.read` |
| 客服沟通单与历史 | `customer-contacts.view`；绑定内部账号也需授权，`contact_customer.enabled` 开启 |
| 订单修改事项与历史 | `order-modifications.view`；绑定内部账号也需授权，`order_modification.enabled` 开启 |

如使用 IP 白名单，还需要允许服务器出口 IP。公开 `openapi.json` 可读并不能证明 Token 或业务权限可用。403 表示授权失败，不能作为“没有记录”处理。

项目使用配置缓存，修改后以应用账号刷新配置，并通知队列进程在当前任务结束后重载：

```bash
runuser -u csi -- php artisan config:cache
runuser -u csi -- php artisan queue:restart
runuser -u csi -- php artisan console:read connections
```

`connections` 不发网络请求，只报告地址、是否配置凭据和站点映射；不能将 `configured=true` 当作认证成功。不要执行可能输出整份凭据配置的命令。

## 查询与隔离规则

- Ticket 站点优先用于选择终端；支持站点简称、域名及带 `www` 的完整 URL。
- 站点和已登记的订单前缀指向不同终端时，查询前报错。当前默认前缀 `dad`、`ebd` 已在本地真实记录核实；新终端的前缀需读取真实数据后再登记，不根据测试样例推断。
- 两套终端都有凭据时，无可识别站点、无已登记前缀的查询需要先明确站点。仅凭客户邮箱不会向两个终端广播查找。只有一个终端配置完整且没有其他明确路由时，保留原有单终端查询行为。
- 选定终端缺 Token、403 或订单不存在时，都不会转到另一个终端查询。
- 同一终端内的两个品牌仍需核对订单站点和客户身份，不能因为共用 Console 就互相替代。关联 Ticket 列表也按站点隔离。
- 核查快照保存连接名称、API 地址及不含明文凭据的配置指纹。更换地址、Token 或路由后，旧订单事实及包含订单上下文的草稿输入失效，需要重新核查。

Ticket 订单工作台、工作流订单事实、取消案例核验均使用上述路由。后台核查结果会显示来源终端。新终端提供只读查询，现有原终端的取消/改码执行开关不会对它生效；原终端执行仍受原有政策、权限和原子状态校验约束。

Console 与 `CommerceSite` 商品接口是两类连接，不共用 Token。按已知站点查询订单不要求新增商品接口凭据；Ticket 站点为空时，订单域名还须唯一匹配启用的 `CommerceSite` 才能自动补站点。当前已有 DorrisWedding 的 `CommerceSite`，DorrisBridal 尚未登记；不得据此伪造商品接口配置。

## 只读研究命令

```bash
# 列出本地连接，不访问业务系统
runuser -u csi -- php artisan console:read connections

# 新终端订单第一页
runuser -u csi -- php artisan console:read orders --connection=mydorris --page=1 --page-size=20

# 按站点选择终端，ORDER_NUMBER 替换为真实订单号
runuser -u csi -- php artisan console:read order --site=dorriswedding.com --order-no=ORDER_NUMBER

# 保存包含软删除记录的沟通单第一页，用于历史研究
runuser -u csi -- php artisan console:read customer-contacts --connection=mydorris --archive --page-size=100 --output=mydorris-contacts-page1.json

# 沟通单历史，RECORD_ID 替换为真实记录 ID
runuser -u csi -- php artisan console:read customer-contact-histories --connection=mydorris --id=RECORD_ID --page-size=100 --output=mydorris-contact-history-page1.json

# 修改事项与高优先级订单
runuser -u csi -- php artisan console:read order-modifications --connection=mydorris --page-size=100 --output=mydorris-modifications-page1.json
runuser -u csi -- php artisan console:read priority-orders --connection=mydorris --page-size=100 --output=mydorris-priority-page1.json
```

命令支持 `orders`、`order`、`work-orders`、`priority-orders`、`customer-contacts`、`customer-contact`、`customer-contact-histories`、`order-modifications`、`order-modification`、`order-modification-histories`，仅发送 GET。详情使用 `--order-no` 或 `--id`，列表使用 `--page` / `--page-size`。沟通单和修改事项列表的订单号筛选按完整号查询；同号涉及多个站点时，另传 `--site-id`，其值是对应 Console 内的真实站点 ID。

每次命令只读取一页，标准输出仅展示来源、请求 ID、响应摘要和分页信息。全量研究必须按 `last_page` 读完所有页，核对条数和重复记录；一页样本不足以得出整体比例。`priority-orders` 有固定业务范围，不能代替完整订单列表。

`--output` 只接受不含路径的简单 `.json` 文件名，写入 `storage/app/private/console-research/`，目录权限 0700、文件 0600，不覆盖已有文件。原始数据包含客户信息，应只在本机分析，不提交 Git；可提交的研究结论须去除客户身份和原始沟通正文，并保留终端、采集时间与证据范围。

## 新增第三套终端

在 `config/console.php` 的 `connections` 中登记独立地址、Token 环境变量及超时，在 `sites` 中明确映射站点。订单前缀在真实核实后才加入 `order_prefixes`。刷新配置后检查连接列表，再通过只读命令分别验证订单、工单和所需研究模块的权限。业务消费方无需修改；新连接仍只读。

## 2026-11-12 验证记录

- 已下载新终端公开文档并验证 447 个本地引用：[快照与阅读记录](../api/console-new/mydorris/README.md)。
- 原终端真实订单列表只读查询成功，`request_id=req_daca989c-d0cd-457e-9889-d4c49ce13804`，采集时间 `2026-11-12T00:45:50Z`；仅取一条验证连通性，不用于总体研究。
- 相关 119 项测试通过，635 次断言；覆盖两个终端的 Host/Token 隔离、同终端不同品牌校验、分页、拒绝回退、配置变更失效和新终端只读约束。PHP 格式及差异空白检查通过。
- 已刷新生产配置缓存并发出队列平滑重载信号，worker 状态正常。刷新前核对配置差异仅新增 `console` 配置；刷新后原终端查询再次成功，`request_id=req_390e7c8b-77c6-449c-aead-1cfee7318591`，采集时间 `2026-11-12T00:52:06Z`。
- 后续用户补齐新终端 Token，已刷新配置并完成沟通单列表、75 条沟通单完整历史、18 笔订单详情和 3 笔订单工单的只读认证查询。站点 ID 34 为 DorrisWedding、36 为 DorrisBridal，实际前缀分别为 drw、drb；前缀信息已记录，路由配置当前仍以显式站点为准。修改事项权限本轮未验证。
- 新终端问题分类与流程见 [2026-11-12 MyDorris 联系客户研究](../research/2026-11-12-mydorris-customer-contact-workflows.md)，原终端研究结论不能直接代替新终端真实行为。
