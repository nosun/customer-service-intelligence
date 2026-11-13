# dad20261113-38381 单笔影子试跑

日期：2026-11-13；时间按 Asia/Shanghai 展示。结论：这笔订单已有同事发信、客户也已确认两件都保留，应停止首轮邮件，交人工接续。Console 的等待状态不能证明尚未发生沟通。

## 核实的来源与订单

先调用 trade list_sites 核对 sw=console/production，再读取当前部署文档与精确订单。订单 API 请求 ID：req_f68efd74-17f8-49c4-972a-2d1067289344。沟通单、历史与工单通过项目既有 Seawave 只读适配器读取，完整分页。

- 品牌 DressAfford，Seawave 内 sid=35，oid=188369。
- 订单已付款、待处理、未发货。
- 两件有效礼服：363378、370676，各1件，尺寸均为8，颜色选项均为 As picture。不能由此推断客户试穿意图。
- 多件沟通单27，source=multi_dress，status=customer，version=1；内部备注和客户要求为空，未解除，blocks_assignment=true。没有其它沟通单阻断。
- 沟通历史只有首次付款自动规则记录。现有历史可证明初始商品ID范围，但没有独立的原始 quantity/count 与规则版本，不能因此将48小时推进资格标成已证明。
- 工单显示待生产；当前 API 没有 production_started 布尔字段，仍保持未开工证明未知。

## Console 之外已存在的沟通

Gmail 在相关查询范围内返回0封。HelpDesk 本地同步记录则已包含该订单的同事邮件与客户回复：

| 时间 | 已知事实 |
| --- | --- |
| 11月13日07:57 | 同事已针对两款8码礼服发出确认邮件，询问保留两件生产还是取消一件 |
| 11月13日15:10 | 客户回复：Keep order of both dresses. Thank you! |

本地 Ticket：[13643](https://csi.test/admin/conversations/13643)，外部标识4c496d3f-f830-4ae6-9824-7671c2213189。报告不包含客户姓名、邮箱、地址或完整原始邮件。

这是足以阻止重复首信的正向证据，不是“HelpDesk最新无回复”的证明。适配器仍 complete=false、fresh=false，不能据缓存空白自动判定客户未回复或同事未接管。

## 实际试跑方法与结果

读取真实业务事实后，在独立内存 SQLite 中运行真正的 MultiDressRoundManager、MultiDressPolicy、MultiDressMailExecutor 和等待检查。试跑阶段禁止外部 HTTP/Process，不连接原应用数据库写入；没有把来源完整性或政策开关改成 true。

结果：

```json
{
  "decision": "manual",
  "reason": "human_contact_or_reply",
  "prior_contact": true,
  "reply_seen": true,
  "draft_work_rejected": true,
  "send_result": "disabled",
  "wait_until": null,
  "isolated_work_items": 0,
  "isolated_effects": 0
}
```

未发送真实邮件，未写 Console 或 HelpDesk，未写主实例台账，未启动新的48小时计时。正确的下一步是由负责同事在 HelpDesk 接续，核对后按既有流程处理 Console 事项；本次没有执行放行。

原始快照、通信证据及隔离试跑JSON保存在应用 private research 目录，权限受限，不提交原始客户数据到 Git。

## 本次修正与验证

1. 修正 DressAfford sid 从1到35，增加真实终端身份形状的HTTP fake回归，确保订单与沟通单查询在同一正确站点。
2. 对同一品牌、同一客户且唯一明确关联当前订单的 HelpDesk 缓存 Ticket，明确识别既有客服消息与客户回复；其它订单或多订单歧义不伪装为当前回复。排除非消息事件，证据ID加入Ticket命名空间，避免0/1事件号跨Ticket碰撞。
3. 优先报告已有沟通/回复，避免被其它缺失证明掩盖；来源不完整、生产证明与政策门禁均保留。

独立功能分支完整回归：441项测试、2631个断言通过；多件模块44项测试、198个断言通过。本次没有刷新主实例配置缓存、安装台账、登记/激活n8n或打开任何执行开关。

这单适合验证防重和已有回复交人工的分支。首封新邮件的真实流程验收还需要一笔无既有沟通的订单，并完成运行手册中的集成门禁。
