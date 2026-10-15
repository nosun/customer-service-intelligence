# WeKnora 电商知识平台规划

## 1. 决策

WeKnora 作为电商知识的检索、浏览和维护平台接入；`customer-service-intelligence` 继续作为知识治理、审核、版本和证据的事实来源。

第一阶段不让 WeKnora 直接成为业务规则执行器，也不让 Agent 直接调用退款、取消、改单等写接口。WeKnora 返回候选知识和引用，本系统负责检查知识状态、适用范围、实时业务事实和操作权限。

## 2. 建设目标

- 统一沉淀商品、订单、生产、物流、售后、客服、系统操作和接口契约知识。
- 让业务人员能够分类、审核、更新、废弃和追溯知识。
- 让客服助手、研发 Agent 和其他内部 AI 使用同一批已验证知识。
- 每个答案可以追溯到知识条目、版本和来源。
- 把相对稳定的规范知识与客户、订单等实时事实分开。

暂不包含：

- 把原始 Ticket、邮件、地址、电话等个人信息直接导入 WeKnora。
- 根据历史客服回复自动发布正式政策。
- 由 RAG 自行决定退款金额、取消条件或业务写操作。
- 在第一阶段启用 WeKnora Agent 的任意代码执行、浏览器操作或业务系统写权限。

## 3. 系统职责

```text
知识来源
  ├─ 业务政策、SOP、接口文档、系统操作手册
  ├─ Ticket / Chat / Gmail 中提取的候选知识
  └─ 业务系统事实和案例证据
                │
                ▼
customer-service-intelligence
  分类 → 关联 → 证据校验 → 审核 → 版本 → 发布资格判断
                │
                │ 只发布 verified 且有效的知识
                ▼
WeKnora
  文档管理 → 分块 → 索引 → 混合检索 → 引用 → 人工维护
                │
        ┌───────┴────────┐
        ▼                ▼
客服回复草稿          内部 AI / 研发 Agent
        │                │
        └───────┬────────┘
                ▼
本系统再次执行适用范围、事实完整性、风险和权限校验
```

| 系统 | 负责 | 不负责 |
| --- | --- | --- |
| `customer-service-intelligence` | 知识身份、状态、审核人、版本、证据、关系、发布和撤回 | 向量索引、通用文档解析、全文知识门户 |
| WeKnora | 文档解析、Chunk、Embedding、混合检索、引用、知识浏览和维护界面 | 判定知识是否经过公司审核、验证实时订单事实、批准业务写操作 |
| 订单/生产/物流等业务系统 | 返回实时业务事实、执行授权操作并返回最终结果 | 保存政策知识、根据历史案例推导规则 |
| AI | 理解问题、检索知识、生成解释或草稿 | 提升知识状态、伪造业务事实、绕过审批执行高风险操作 |

## 4. 分类体系

分类采用多个正交维度，避免把站点、知识类型和业务域混成一棵不断膨胀的目录树。

### 4.1 业务域 `domain`

| 一级域 | 二级示例 |
| --- | --- |
| `catalog` 商品与目录 | 商品属性、尺码、颜色、定制、上下架、图片和价格 |
| `sales` 售前与销售 | 优惠、交期承诺、支付、活动、转化规则 |
| `order` 订单 | 下单、支付、拆单、合单、改单、取消 |
| `production` 生产 | 工单、排期、状态、加急、异常、返工 |
| `fulfillment` 履约与物流 | 仓库、包裹、承运商、轨迹、清关、签收和异常 |
| `after_sales` 售后 | 退货、退款、换货、补偿、投诉、拒付 |
| `customer_service` 客服 | Case 分类、沟通规范、升级、回复策略和模板 |
| `system_operation` 系统操作 | 管理后台操作、数据修复、任务执行、发布和回滚 |
| `api_integration` 接口与集成 | API 契约、字段、鉴权、幂等、错误码和调用示例 |
| `data` 数据 | 指标口径、数据字典、同步、报表和数据质量 |
| `security_compliance` 安全与合规 | 权限、个人信息、审计、密钥和保留策略 |

### 4.2 知识形态 `knowledge_type`

| 类型 | 含义 | AI 使用方式 |
| --- | --- | --- |
| `policy` | 公司政策与业务边界 | 可作为正式判断依据 |
| `sop` | 有前置条件、步骤和完成标准的业务流程 | 生成操作建议，写操作仍需权限校验 |
| `runbook` | 系统运维、故障处理和恢复步骤 | 面向研发/运维 AI，默认只读建议 |
| `api_contract` | API 路径、参数、返回、权限、错误和幂等规则 | 生成或校验接口调用 |
| `data_dictionary` | 字段、状态、枚举、指标定义 | 解释数据和生成查询 |
| `troubleshooting` | 症状、诊断证据、原因和处理方法 | 辅助定位，不把历史原因当作当前结论 |
| `template` | 邮件、消息和工单模板 | 在满足适用条件后生成草稿 |
| `faq` | 稳定的一问一答 | 直接检索回答低风险问题 |
| `reference` | 概念、产品说明和外部参考 | 提供背景信息 |

现有 `KnowledgeArticle::TYPES` 的 `process` 后续迁移为 `sop`；迁移完成前保持兼容映射，避免一次性修改现有数据。

### 4.3 适用范围

每条知识至少明确以下范围：

- `site_key`：`global`、`dressafford.com`、`ellenbridals.com` 等；
- `system_key`：适用的系统或服务；
- `environment`：`all`、`production`、`staging`、`local`；
- `language`：知识原文和可输出语言；
- `region`：国家、地区或渠道限制；
- `audience`：`business`、`customer_service`、`engineering`、`ai`；
- `valid_from` / `valid_until`：生效和失效时间。

`site_key` 不同且政策可能不同的知识不能只靠提示词隔离，必须通过知识库范围或服务端过滤隔离。

### 4.4 风险级别

| 风险 | 示例 | 默认行为 |
| --- | --- | --- |
| `informational` | 商品说明、字段解释、只读查询方法 | 可用于回答或草稿 |
| `decision_support` | 取消条件、退款比例、异常处理流程 | 必须结合实时事实并保留引用 |
| `write_operation` | 退款、取消、改地址、数据修复命令 | 只提供步骤；执行需要独立权限、审批、幂等和结果回查 |
| `restricted` | 密钥操作、个人信息、生产破坏性命令 | 限定人员和 AI，不发布到通用知识库 |

## 5. 生命周期

沿用当前状态语义：

```text
observed ──人工审核──> verified ──发现冲突──> conflicted
    │                       │                    │
    └────────放弃──────────> deprecated <───────┘
```

- `observed`：历史案例、AI 归纳或新建草稿，不能进入生产 AI 检索库。
- `verified`：负责人确认且证据完整，可以发布。
- `conflicted`：规则之间存在冲突，必须立即从生产检索范围撤回。
- `deprecated`：被替代或失效，从生产检索范围撤回但保留审计记录。

发布条件：

1. 状态为 `verified`；
2. 有负责人、审核人和审核时间；
3. 有稳定的 `article_key` 和递增版本；
4. 适用范围明确；
5. 不包含凭据或未经批准的个人信息；
6. 冲突关系已经处理；
7. `decision_support` 和 `write_operation` 明确所需事实、禁止事项和升级规则。

## 6. 知识关系

关系由本系统保存和校验；第一阶段不依赖 WeKnora GraphRAG 表达业务约束。

| 关系 | 含义 | 示例 |
| --- | --- | --- |
| `applies_to` | 知识适用于对象 | 取消 SOP → DressAfford 整单 |
| `requires` | 使用前必须读取或满足 | 取消 SOP → 订单请求时生产状态 |
| `depends_on` | 内容依赖另一条知识 | 客服回复模板 → 取消政策 |
| `invokes` | 流程调用接口或系统操作 | 查询订单 SOP → ConsoleNew `GET /orders/{order_no}` |
| `supersedes` | 新知识替代旧知识 | v3 政策 → v2 政策 |
| `conflicts_with` | 两条知识存在未解决冲突 | 历史客服做法 ↔ 正式退款政策 |
| `evidenced_by` | 结论的证据来源 | 政策 → 业务确认记录 / Decision Node |
| `implements` | 操作或接口实现某项政策 | 退款接口 → 退款 SOP |
| `related_to` | 普通参考关系 | 物流异常 ↔ 承运商状态字典 |

关系记录至少包含 `from_article_id`、`relation_type`、`to_article_id`、`notes`、`created_by` 和时间。`requires`、`supersedes`、`conflicts_with` 属于强关系，发布前必须校验；`related_to` 只是导航关系。

## 7. WeKnora 知识库布局

本地 PoC 使用一个 Workspace，先建立以下知识库：

| 知识库 | 内容 | 权限 |
| --- | --- | --- |
| `commerce-common` | 跨站点通用且已验证的电商知识 | 业务、客服和 AI 只读 |
| `brand-dressafford` | DressAfford 政策、商品与流程 | DressAfford 相关应用可检索 |
| `brand-ellenbridals` | EllenBridals 政策、商品与流程 | EllenBridals 相关应用可检索 |
| `engineering-operations` | 系统操作、Runbook、故障处理 | 研发/运维及内部 AI |
| `api-contracts` | 内部接口契约、字段和安全调用方式 | 研发 AI；敏感接口进一步限制 |

不建立可被生产 AI 检索的 `observed` 库。候选知识继续保存在本系统审核队列中，避免误把“待确认”当成正式知识。

知识库数量保持克制：站点政策需要强隔离时拆库；普通分类使用 `domain`、`knowledge_type` 和标签，不为每个二级分类建立知识库。

## 8. 发布格式与映射

`knowledge_articles` 是规范内容，发布器把它渲染成稳定 Markdown：

```markdown
# {title}

- 知识编号：{article_key}
- 版本：{version}
- 业务域：{domain}
- 类型：{knowledge_type}
- 适用站点：{site_key}
- 风险：{risk_level}
- 审核时间：{reviewed_at}

## 适用范围
{applicability}

## 所需事实
...

## 规则 / 决策树
...

## 操作步骤
...

## 禁止事项
...

## 升级规则
...
```

WeKnora `custom_metadata` 保存可过滤和追溯的短字段：

- `source_system=customer-service-intelligence`
- `article_key`
- `article_id`
- `version`
- `content_hash`
- `domain`
- `knowledge_type`
- `site_key`
- `system_key`
- `risk_level`
- `reviewed_at`

本系统新增发布映射时，建议使用：

### `knowledge_article_relations`

- `from_knowledge_article_id`
- `relation_type`
- `to_knowledge_article_id`
- `notes`
- `created_by`
- timestamps

### `knowledge_publications`

- `knowledge_article_id`
- `target`，例如 `weknora-local`
- `knowledge_base_key`
- `external_knowledge_id`
- `published_version`
- `content_hash`
- `status`：`pending/published/failed/withdrawn`
- `published_at`、`last_error`
- timestamps

发布必须幂等：相同 `article_key + version + content_hash` 不重复写入；更新保留本地版本记录；`conflicted/deprecated` 触发撤回。WeKnora 写入成功不等于索引已经完成，必须继续轮询解析状态后再把发布记为可检索。

## 9. 检索与 AI 使用流程

```text
用户问题
  -> 识别用途、站点、系统、环境和风险
  -> 选择允许访问的知识库
  -> WeKnora Hybrid Search
  -> 返回 knowledge_id / chunk_id / score / content
  -> 本系统按 article_key 回查当前状态和版本
  -> 丢弃非 verified、旧版本、范围不匹配的结果
  -> 按 requires 关系补充实时事实或依赖知识
  -> 生成带引用的草稿
  -> 风险门禁：回答 / 人工审核 / 禁止执行
```

AI 接入顺序：

1. 客服系统通过本地服务端网关检索，不把 WeKnora API Key 下发浏览器。
2. 内部研发 AI 使用只读、限定知识库的 API Key 或 MCP Endpoint。
3. 业务人员通过 WeKnora Web UI 浏览已发布知识。
4. 验证权限和审计后，再考虑面向更多系统开放检索。

每次 AI 使用至少记录：查询、选择的知识库、返回的知识/Chunk、分数、文章版本、生成结果和人工修订。知识被更新后，历史 AI Run 仍引用当时使用的版本，不反向改写历史。

## 10. 安全边界

- 原始 Ticket、邮件和 Chat 不进入通用 WeKnora 知识库。
- 发布内容不得包含姓名、邮箱、电话、地址、订单号和未脱敏附件。
- 实时订单、生产、物流和退款状态通过只读业务 API 获取，不作为长期知识向量化。
- API 示例使用占位符，不保存真实 Token、Cookie、签名或生产凭据。
- `engineering-operations` 和 `api-contracts` 与客服知识分库、分 Key。
- 应用只取得所需的 `retrieve` 或 `ingest` 能力，不使用 full-access Key。
- WeKnora 部署在本机或内网，关闭公开注册，不直接暴露公网。
- 第一阶段禁用 Sandbox、BrowserSkill 和高风险 MCP 工具。

## 11. 本地部署基线

- 安装目录：`/Users/guohuawei/dev/WeKnora`
- 固定 Git 版本：`v0.8.2`；Docker 镜像标签同样使用带 `v` 的 `v0.8.2`，不使用 `latest` 漂移升级
- 部署方式：标准 Docker Compose
- 本地入口：`http://127.0.0.1:18100`
- 本地后端：`http://127.0.0.1:18180`
- 本地数据：Docker volumes，不提交 Git
- 可选组件：Neo4j、MinIO、Langfuse、Sandbox 暂不启用
- 模型：完成平台启动后再配置，不把模型密钥写入仓库

日常启动、健康检查、账户初始化、日志和数据保留操作见 [WeKnora 本地运行手册](../runbooks/weknora-local.md)。

升级流程必须先阅读目标版本 Breaking Changes，在备份后升级，并执行发布、检索、权限和撤回契约测试。

## 12. 分阶段落地与验收

### 阶段 0：分类和边界冻结

- 评审本文件中的业务域、知识类型、风险和关系。
- 选定知识负责人、审核人和各知识库读者。

验收：任取 20 条现有知识，能够无歧义地标注分类、范围、状态、风险和关系。

### 阶段 1：本地 WeKnora PoC

- 启动固定版本的标准部署。
- 完成管理员初始化和基础安全设置。
- 创建 5 个知识库，但首批只启用 `commerce-common` 和 `brand-dressafford`。
- 手工录入 5 条不含个人信息的已验证知识。

验收：UI、API 健康；知识可以完成解析；中英文查询能返回正确引用；重启后数据仍在。

### 阶段 2：单向发布器

- 实现 WeKnora HTTP Client、配置和连接健康检查。
- 实现 Markdown 渲染、发布映射、幂等更新、解析状态轮询和撤回。
- 只发布 `verified`。

验收：重复发布不重复；版本更新替换正确；`conflicted/deprecated` 不再可检索；失败可以重试且有审计。

### 阶段 3：客服检索和引用

- 在客服工作台加入知识检索。
- 生成回复草稿时记录知识和业务事实引用。
- 使用 Benchmark Case Step 做离线评估。

验收：正确知识 Top 3 命中率、跨站误用率、引用完整率和人工修订率达到评审门槛；仍不自动发送。

### 阶段 4：系统和接口知识

- 接入 `engineering-operations` 和 `api-contracts`。
- 为研发 AI 建立只读、限库的 MCP/API 访问。
- 对写操作知识增加命令风险标签和人工确认要求。

验收：AI 能找到正确接口版本、前置条件、错误码和回滚方法；不会获得凭据，也不会自动执行高风险命令。

### 阶段 5：受控自动化

只对 `verified + informational + 事实完整 + 无写操作` 场景评估自动回复。退款、取消、改单、补偿和数据修复仍使用独立的审批、幂等、审计和结果回查链路。

## 13. PoC 评估指标

- 正确知识 Top 1 / Top 3 命中率；
- 无答案时的正确拒答率；
- 跨站点或跨环境知识误用率；
- `observed/conflicted/deprecated` 泄漏数，目标为 0；
- 引用中知识编号和版本完整率；
- 更新到可检索、撤回到不可检索的延迟；
- 检索 P95 延迟；
- 人工对 AI 草稿的接受率和修改率；
- WeKnora 或模型不可用时的安全降级结果。

第一轮 PoC 不预设自动回复准确率目标，先验证知识治理、隔离、更新、撤回和引用链路是否可靠。
