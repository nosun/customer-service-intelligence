# 多件确认首轮：开发交付与验证记录

日期：2026-11-13。范围：Seawave / DressAfford / EllenBridals，现有 multi_dress 沟通单的第一轮。用户已批准开发；生产启用不在此次执行中。

## 已实现

| 模块 | 源码交付 | 验证范围 |
| --- | --- | --- |
| 台账/策略 | CustomerContactRound/Effect/Event 三表、Case/WorkItem、订单级唯一首轮、原始命中冻结、分终端/品牌校验 | SQLite 迁移、重复导入、跨品牌 ID、已处理跳过、缺证据停止 |
| 事实与沟通 | Console 白名单及完整分页、Gmail 全文件夹/完整分页、HelpDesk 正向线索、规范化输入哈希 | HTTP/Process fake，读取不完整和歧义停止；HelpDesk 实时完整性待补 |
| 邮件与审核 | 固定品牌模板、四个受限问题模块、独立审核哈希、最多一次修订、私有冻结邮件 | 真正的 PHP draft 服务及工作租约；审核输出为测试替身 |
| n8n 候选 | 18个节点的 inactive 文件，work/customer_contacts 登记、受限内部接口，无发送或 Console 工具 | 连接检查、6个 Code 节点 JavaScript 语法；未做真实 n8n 执行 |
| Gmail 执行 | 账号验证、单次发送意图、SENT 原文/身份/marker 核对、未知结果只读对账 | Process fake 的真实 MIME/CLI 适配器及服务测试；真实收件待验收 |
| Console 备注 | 原文追加、nosun-agent、真实 Preview、人员权限与精确预览确认、版本冲突和回查基线 | HTTP fake 的 Preview/Confirm，记录真实测试用户；无生产写入 |
| 48小时/人工交接 | SENT 回查后计时、回复优先退出、原始两件及同款范围核验、其它阻断和人工修改停止、单个 FollowUp 及进展追加 | 截止边界、部分回复、原三件减两件、备注失败、来源不完整、政策/客户身份变化 |
| 后台/运维 | 列表/详情、事实/草稿/状态、接管、预览确认、调度及健康命令、隔离回放 | 页面/权限/转义、停止发送、独立 DB/Storage 的清理和恢复 |

首封信明确“当前暂停”和“48小时内回复”，不告知内部无回复后续，不强制只留一件。后续客户回复仍由同事在 HelpDesk 处理。当前固定英文模板；未接 Manager 多语言模板生成。

## 本次验证

- 本模块：37项测试、159个断言通过。
- 完整 PHP 回归：539项测试、3901个断言通过，约47秒。
- PHP 隔离订单回放：38项断言通过；真实发信0、真实 Console 写入0、真实 n8n 执行0。
- n8n 静态检查：18节点，连接目标有效，6个 Code 节点通过 node --check，active=false。
- 新增 PHP 文件通过 Pint；变更 PHP 文件做语法检查。不对已有未提交文件做全库格式化。

完整回归在独立 worktree 进行，未复制生产 .env。两项已有 HelpDesk 测试需要配置才能使用 HTTP fake，使用测试专用虚拟配置后全部通过，没有修改业务凭据或 HelpDesk 原实现：

```bash
HELPDESK_ACCOUNT_ID=12345 HELPDESK_PERSONAL_ACCESS_TOKEN=test-only runuser -u csi -- php artisan test --do-not-cache-result
```

本次验证覆盖重复任务与交错人工接管，不宣称已完成真实多进程压测、AWS/Gmail投递保障或跨系统原子事务。

## ebd20261105-1295 的回放结论

当前快照已有人工处理结果：一个商品取消、另一个保留，沟通单已解除。处理器跳过，不能补发第一封信。

历史双件快照只用于隔离分支模拟。完整沟通、原始双件规则证明、政策核实、发送回执、回复事件及备注写回基线均明确为合成条件；模拟审核不等于独立 AI 实审。没有用最终人工保留结果倒推首封信时已知意图。

可查看 [完整回放报告及邮件正文](2026-11-13-multi-dress-offline-replay.json)。夹具 SHA256：52af6e3a23089fa89a75fb674ee00133000b0d49c070f4e2546b3461dc1f75b4。

## 尚未完成的集成门禁

1. HelpDesk：实时完整 Ticket/事件搜索与人工占用/草稿状态。当前适配器固定 complete=false；现阶段真实候选不能自动发信。
2. Console：明确尚未生产的来源证明；原始命中数量/商品范围与规则版本；满足全部前置条件的原子超时推进。现在只有裸 release，不足以认定进入正常分配或生产。
3. n8n：真实 fixture 回放入口与专用发布门禁，绑定真实 execution ID、工作流/夹具 checksum 与人工标注。当前该 key 只可登记 evaluating，active 注册会拒绝。
4. 两品牌：批准的内部测试收件、回复进入 HelpDesk 及订单关联验收；真实备注 Preview/Confirm 契约验收。

因此，此次交付是可审阅、可模拟的源码实现，不能表述为生产自动闭环已上线。`timeout_execute_enabled=false` 在源码中固定，当前 timeout 仅返回本地资格建议；不会执行生产推进。

主实例未迁移、未修改 .env、未导入/激活 n8n、未进行生产读写验收。仓库已有其它未提交工作；本次仅同步相对开发前快照的任务增量，不打包提交其它改动，保留隔离 worktree 便于继续联调。

## 后续顺序

先完成 HelpDesk 实时读取和未生产状态证明，再跑两品牌只读 shadow 候选；接着完成真实 n8n 回放及审核验收；取得受控收件授权后验收首信与备注。最后单独开发、验收两件超时推进。无需为首轮联调提前启用 ETA、comment 或 HelpDesk 真实发送。

操作入口见 [运行手册](../runbooks/seawave-multi-dress-first-round.md)，上游需要配合的字段见 [接口契约](../api/seawave-multi-dress-execution-contract.md)。

## Git 提交版本验证

功能分支为 `feat/seawave-multi-dress-first-round-v1`。提交版本从干净基线整理，只包含多件首轮及必要的 Console 读取、Gmail 传输和 FollowUp 台账依赖，没有混入商品问答、物流、垃圾邮件自动处理等未提交工作。

该独立版本的全部 PHP 回归为 **438项测试、2608个断言通过**。前文539项/3901断言是在原开发快照（含其它未提交模块）上的整体回归，两者范围不同。新增独立终端路由/只读约束测试4项。隔离首轮回放仍为38项，真实发信、Console写入和n8n执行均为0。
