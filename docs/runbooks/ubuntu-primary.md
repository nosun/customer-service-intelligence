# Ubuntu 主实例运行约定

主机：`192.168.3.226`。2026-11-02 起，本地仅保留项目文件，业务数据和运行状态统一保存在服务器。

| 用途 | 地址或目录 |
| --- | --- |
| Laravel 主项目 | `/opt/customer-service-intelligence` |
| Laravel / n8n 部署配置 | `/opt/customer-service-intelligence-ops` |
| 后台 | `https://csi.test/admin` |
| n8n | `https://n8n.csi.test` |
| WeKnora 源码 | `/opt/weknora` |
| WeKnora 实际 Compose 配置 | `/opt/weknora-ops/compose.json` |
| WeKnora 网页 | `https://csi.test:18443` |
| WeKnora API，服务器本机使用 | `http://127.0.0.1:18180` |
| WeKnora 迁移与维护说明 | `/opt/weknora-ops/DEPLOYMENT.md` |
| 本轮 Mac 数据归档 | `/var/backups/csi-mac-retirement-20261102` |

域名沿用 Mac hosts 中指向服务器的 `csi.test` 和 `n8n.csi.test`，WeKnora 使用同一受信任证书。WeKnora 数据库、Redis、解析服务和 Ollama 仅在 Docker 网络中可访问，网页和 API 的容器映射仅绑定服务器 loopback。

## 操作入口

```bash
ssh root@192.168.3.226
cd /opt/customer-service-intelligence
runuser -u csi -- /usr/bin/php artisan schedule:list
systemctl status csi-worker csi-scheduler.timer csi-backup.timer
docker compose -f /opt/customer-service-intelligence-ops/csi-n8n.yaml ps
docker compose -f /opt/weknora-ops/compose.json ps
systemctl status csi-weknora-backup.timer
```

修改业务代码以服务器主目录为准。服务器已有未提交成果，不要以旧 Mac 副本覆盖；版本整理和发布须另行审查现有 diff。应用命令使用 `csi` 用户，避免产生 root 所有的运行文件。WeKnora 源码可由 `nosun` 维护，部署凭据位于 root 私有的 ops 目录。

## 调度和能力边界

- Laravel 同步、队列及备份由服务器 systemd / Docker 托管。
- 问答巡检保留每天 Asia/Shanghai 10:00、16:00 的 Mac Codex 调度。它通过 SSH 操作服务器，不依赖本地项目数据库或 state 文件；Mac Codex 停止运行会影响该巡检。
- 问答继续只处理固定起点 `2026-10-27T11:16:56Z` 之后新增的问题，保留独立复核和人工最终审核，不自动发布或通知客户。
- Gmail OAuth 已迁至服务器，邮箱 `support@dressafford-service.com`。`csi-gmail-sync.service` 读取最近 30 天的完整会话；timer 按 Asia/Shanghai 每天 10:00、16:00 触发，前一轮未结束时不会重叠启动。每轮最多 100 页（10,000 个会话），超过上限时需要扩大范围并复核分页。
- WeKnora 服务迁移不自动启用 Laravel 的 WeKnora retrieval / publish；上线该业务集成须另行核实站点知识库映射、凭据和流程验收。

## 本地数据清理边界

本地保留源码、Git、迁移、测试、文档、版本化知识规则及依赖。运行期 SQLite、storage 中的业务资料/备份/报告/state、output、浏览器产物、日志、会话、缓存和真实 `.env` 在归档到服务器并验证后清理。

Mac 旧 n8n、WeKnora 容器及其专属数据卷在服务器恢复与验收后移除；相关原始数据和配置归档只保存在服务器，禁止将归档或真实凭据提交 Git。既有 Studio 迁移私有材料也归档到服务器后从 Mac 清理。Gmail 授权及同步已于 2026-11-03 迁入服务器。

服务器 CSI 每日备份目录为 `/var/backups/customer-service-intelligence`；WeKnora 每日备份目录为 `/var/backups/csi-weknora`。本轮迁移原始归档单独保留，不随七日轮换清理。恢复或回退应以服务器数据为准，不能直接重新启用空的 Mac 副本。

## Gmail 同步维护

凭据目录为服务器 `/opt/customer-service-intelligence/storage/app/private/gws/primary`，目录权限 0700，凭据文件 0600，所有者 `csi:www-data`。使用 OAuth refresh token 换取短期 access token；不能只迁移 access token，也不能直接复制 Mac keyring 加密文件。禁止将凭据写入 Git 或维护记录。

Gmail 服务以 `csi` 运行，工作目录必须是 Laravel 项目目录。HTTP 代理仅作用于此服务（`127.0.0.1:10809`）。systemd 单次服务防止重叠；运行时不使用 `--resume`，确保已存会话里的新邮件也会更新。同步只读取邮件及附件元数据，不发送邮件，也不自动生成或发布客户回复。

```bash
systemctl status csi-gmail-sync.service csi-gmail-sync.timer
journalctl -u csi-gmail-sync.service -n 30 --no-pager
systemctl start csi-gmail-sync.service
```

部署源文件位于 `/opt/customer-service-intelligence-ops/csi-gmail-sync.{service,timer}`，生效副本位于 `/etc/systemd/system/`。现有 CSI 备份包含凭据、同步数据库和新服务配置；恢复后需重新验证 Gmail 授权、代理、邮箱身份和 timer。超过 30 天的历史邮件本次没有全量导入。


### Ellenbridals Gmail 接入（2026-11-03）

站点 `ellenbridals.com`，邮箱 `ellenbridals.com@gmail.com`。独立服务为
`csi-gmail-sync-ellenbridals.service` / `.timer`，凭据目录为
`storage/app/private/gws/ellenbridals`（0700，csi:www-data），凭据文件为 0600。
已通过 Gmail `users.getProfile` 验证实际邮箱身份及读取权限；3 个会话、3 封邮件的
试同步成功。2026-11-03 北京时间 10:52:58 启动首轮近一年同步，首轮完成状态需查
`sync_runs` 和 journal；不得将试同步成功等同于一年历史已全部导入。

首轮历史导入使用 `in:anywhere newer_than:1y`，最多 1000 页，超时 6 小时；
其运行进程继续完成。2026-11-03 按用户要求，将后续服务配置改为
`in:anywhere newer_than:30d`，每轮最多 100 页、超时 2 小时，与 Dressafford 一致。
timer 已启用，每天 Asia/Shanghai 10:00、16:00 运行；此次只 reload 配置，
未重启正在执行的首轮历史导入。已导入历史保留。首次历史导入已由 systemd
托管，不依赖终端保持连接；同一服务运行时不重叠启动。不使用 `--resume`，
以更新历史会话中的新回复。备份脚本已纳入新服务配置及凭据目录。

Google Cloud 项目 `customer-service-intelligence` 已由用户切换 External 受众并
加入该测试用户。授权须勾选 Gmail 只读权限，单纯身份权限无法同步邮件。
该个人 Gmail 无需授予 Cloud 项目 IAM 角色：独立 `client_secret.json` 去除可选的
`project_id` 配额提示，避免 gws 附加组织项目的 quota header 导致
`serviceusage.services.use` 403；保留 OAuth 客户端身份及密钥。

当前仍为 External Testing。Gmail refresh token 通常 7 天过期，长期运行需由
项目管理员处理生产发布状态及 Google 要求的验证；过期后需要重新授权。
凭据和授权回调 code 不得写入维护记录或 Git。

```bash
systemctl status csi-gmail-sync-ellenbridals.service csi-gmail-sync-ellenbridals.timer
journalctl -u csi-gmail-sync-ellenbridals.service -n 30 --no-pager
```

Dressafford 仍同步最近 30 天，其 timer 同样已生效为每天 10:00、16:00。

Ellenbridals 首轮历史同步于 2026-11-03 北京时间 11:10:45 完成，397 个会话、
598 封邮件，`sync_runs completed`，systemd Result=success。此后按最近 30 天同步。

Gmail Spam MiniMax 定时巡检和飞书通知见 [Gmail Spam 客户邮件巡检](gmail-spam-review.md)。
