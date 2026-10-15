# WeKnora 本地运行手册

## 1. 当前基线

- 安装目录：`/Users/guohuawei/dev/WeKnora`
- Git 版本：`v0.8.2`
- 固定提交：`3e8b0bfc80b845b2d4b2ed683994748741450a97`
- Web UI：`http://127.0.0.1:18100`
- 后端 API：`http://127.0.0.1:18180`
- 部署方式：Docker Compose
- 数据位置：Docker volumes
- 当前未启用：Neo4j、Sandbox

Docker 镜像标签必须使用带 `v` 的 `v0.8.2`。`0.8.2` 不是本次部署使用的有效标签，不要改用 `latest` 绕过版本固定。

## 2. 启动

```bash
cd /Users/guohuawei/dev/WeKnora
docker compose up -d
docker compose ps
```

预期结果：

- `app`、`docreader`、`postgres` 显示 `healthy`；
- `frontend`、`redis` 显示 `Up`；
- 所有容器的重启次数保持为 0；
- Web 和 API 只绑定到 `127.0.0.1`，不直接暴露到局域网或公网。

## 3. 健康检查

```bash
curl -fsS http://127.0.0.1:18180/health
curl -fsS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:18100/
```

预期分别返回：

```text
{"status":"ok"}
200
```

浏览器打开 `http://127.0.0.1:18100`，应进入 WeKnora 登录页面。

## 4. 首次账户初始化

当前仅为创建首个本地账户临时开启注册：

```dotenv
DISABLE_REGISTRATION=false
```

在 Web 页面创建并验证首个账户后，立即编辑 `/Users/guohuawei/dev/WeKnora/.env`：

```dotenv
DISABLE_REGISTRATION=true
```

然后重新应用配置并验证登录：

```bash
cd /Users/guohuawei/dev/WeKnora
docker compose up -d
docker compose ps
```

关闭注册后应确认：已有账户仍可登录，新账户注册入口不再可用。

## 5. 查看日志

```bash
cd /Users/guohuawei/dev/WeKnora
docker compose logs --tail=120 app frontend postgres docreader
```

持续观察单个服务时使用：

```bash
docker compose logs --follow app
```

日志中若包含用户输入、模型请求或业务内容，不要直接复制到工单、聊天或公开文档；先脱敏。

## 6. 停止与恢复

停止服务但保留数据库和知识文件：

```bash
cd /Users/guohuawei/dev/WeKnora
docker compose down
```

恢复服务：

```bash
docker compose up -d
```

不要随意执行以下命令：

```bash
docker compose down -v
```

`-v` 会删除本地数据库和知识文件所在的 Docker volumes。只有在明确决定销毁全部本地 PoC 数据并完成必要备份后才能使用。

## 7. 模型配置边界

平台启动成功不代表知识问答链路已经可用。Embedding、重排和生成模型仍需单独配置并进行端到端验证。

- 模型 Token、API Key 和真实凭据只保存在本机 `.env` 或凭据管理工具中；
- 不把真实密钥写入本仓库、规划文档、命令历史或聊天；
- 优先使用专用于本地 PoC、权限和额度受限的凭据；
- 配置完成后再创建知识库、上传文档并验证解析、检索、引用、更新和撤回；
- 本地 `.env` 中的固定 PoC 密钥不得复制到共享或生产部署，正式使用前必须轮换。

## 8. 资源边界

当前 Docker Desktop 分配约 8 GB 内存，达到 WeKnora 标准部署建议的下限。批量解析大文件、并发导入或同时运行本地模型时可能出现内存压力。

PoC 阶段一次只导入少量脱敏文档。若出现容器重启、解析超时或系统明显交换内存，先检查：

```bash
docker compose ps
docker stats --no-stream
docker compose logs --tail=120 app docreader postgres
```

不要在未定位原因前反复重启或删除 volumes。

## 9. 首轮 PoC 验收

模型配置完成后：

1. 创建 `commerce-common` 和 `brand-dressafford` 两个知识库；
2. 录入 5 条脱敏且状态为 `verified` 的知识；
3. 验证文档解析完成，而不只是上传成功；
4. 用中英文问题验证 Top 3 结果和引用；
5. 更新一条知识并确认新版本可检索；
6. 撤回一条知识并确认旧内容不再可检索；
7. 重启 Compose 后确认账户、知识库和文档仍然存在。

原始 Ticket、邮件、聊天记录、地址、电话、订单号和真实凭据不得作为 PoC 文档上传。
