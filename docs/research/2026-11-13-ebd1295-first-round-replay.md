# ebd20261105-1295 多件首轮流程：只读核实及离线模拟

日期：2026-11-13。范围：Seawave／EllenBridals，多件确认首轮。**没有真实发信、创建远程草稿、写Console、解除阻断、修改应用数据库或启用调度。**

本次按brainstorming整理[工程设计](../superpowers/specs/2026-11-13-seawave-multi-dress-first-round-design.md)，按writing-plans形成[开发计划](../superpowers/plans/2026-11-13-seawave-multi-dress-first-round.md)。模拟使用可重跑的本地研究探针，既不是生产功能实现，也不是已接通真实n8n的全链路验收。

## 1. 先核对现在应不应该处理

通过trade的list_sites核对 `sw=console/production`，再调用 `console_get_order(site=sw, order_number=ebd20261105-1295)`。订单快照捕获于 **2026-11-13 16:57:27 +08:00**。

| 对象 | 当前核实状态 | 对首信的影响 |
| --- | --- | --- |
| 订单 | sid36、oid188301；已付款、处理中、未发货 | 已不处于初始待确认阶段 |
| 商品337567 | 颜色As picture，Size 2，数量1；已取消 | 不能继续按两件原清单发信 |
| 商品337573 | Black，Size 2，数量1；有效 | 当前只剩一件有效礼服 |
| 沟通单16 | 当天15:12:44 +08:00已有快照：version5、status=factory、released_at非空 | 已有处理及放行证据，不再首信 |
| 内部沟通摘要 | 已联系；客户只留337573，另一件取消 | 是人工内部记录，不冒充已读取客户原始回复 |

**当前真实处理结果：`skip:already_handled`。** 不重发，不重新暂停，不写“AI已联系”，不执行任何补放行。

当前trade工具清单没有customer-contacts读取／操作工具，只公布docs.read、order.read范围。因此本次订单状态是实时核实，沟通单状态来自当天既有私有快照；没有声称实时重读了全部沟通历史。接口接入和权限注册列入开发前置任务，不把它误报为原Console Token授权已丢失。

## 2. 用哪个历史状态模拟首信

使用2026-11-10保存的订单和沟通单初始历史：

| 商品 | 颜色 | 尺码 | 数量 | 当时状态 |
| --- | --- | --- | --- | --- |
| 337567 | As picture | 2 | 1 | 正常 |
| 337573 | Black | 2 | 1 | 正常 |

当时订单已付待处理；沟通单source为multi_dress，未解除。产品名称为空，因此首信使用可识别的商品编号，不编造款名。只知道不同商品编号、同码，不能据此认定买多留一。

历史快照本身没有证明完整沟通记录，也没有原始两件规则版本、排除类别的命中证明。实际按这份证据执行时应为 **`manual:communication_history_incomplete`**，不是可直接发送。合成分支另外注入“已完整排除既有联系”等条件，用于验证未来流程；这些条件不反写成真实历史。

模拟的发送时钟设为2026-11-13 17:00 +08:00，使用已保存的11月11日站点政策版本来演示当前设计，不声称还原11月5日当天实际发送的政策或邮件。当前人工已完成的“只留一件”信息没有用于历史首信生成。

只读检查了EllenBridals Gmail账户身份，并按该订单号检索。只命中两封DSN失败通知，没有取得多件首信或客户回复；失败收件人也未匹配该订单客户邮箱。因此不能把这些通知当作本首信的失败证据，也不能因为没找到首信就认为没有人工沟通。HelpDesk原始处理过程本次未取得。

## 3. 历史假设分支中的具体首信

这份预览不包含真实客户邮箱，称呼使用Hi there；本地收件人是不可投递的 `customer@example.invalid`。没有调用Gmail draft或send。

Subject: **Please confirm the dresses in your order ebd20261105-1295**

```text
Hi there,

Thank you for your order with EllenBridals. Your order is currently on hold
while we confirm your selection. Please reply to this email within 48 hours
to let us know which dresses you would like to keep.

Your order includes the following dresses:

Item 337567 — Color: As picture, Size: 2, Quantity: 1
Item 337573 — Color: Black, Size: 2, Quantity: 1

Our return policy does not support ordering multiple dresses with the
intention of keeping only one and returning the rest. Any return request
remains subject to the conditions and exclusions in our return policy:
https://www.ellenbridals.com/article/return-policy.html

Are these dresses for different people or occasions, or are you planning
to compare styles or sizes after they arrive?

Please reply to let us know your plans:

- If you plan to keep both dresses, please confirm this.
- If you would like to keep only one, please identify the item.
- If you are still deciding on a style or size, let us know what you are
  unsure about so our team can help you review the options before production.

Best regards,
EllenBridals Customer Service
```

程序检查商品／颜色／尺码／数量、暂停／48小时、站点政策链接、未强制减件及未披露内部超时后果。模拟审核使用固定审核夹具，**未调用独立AI审核或真实n8n工作流**；正式实现仍需相应回放验收。

## 4. 模拟时间线

下表除历史商品输入及当前已处理结果外，均为假事件：

| 时间／事件 | 预期状态和动作 |
| --- | --- |
| T0：历史事实且已完整排除人工联系 | 准备固定模板及必要用途问题，生成／审核正文 |
| T0：先保存发送意图 | 再调用一次假Gmail；重复任务不产生第二次发送 |
| 2026-11-13 17:00 +08:00：模拟SENT并回查 | 保存模拟收据；wait_until=2026-11-15 17:00 +08:00 |
| 模拟备注写入失败 | 邮件保持sent；只补写备注，不重发、不改截止时间 |
| 备注修复 | 保留同事原文，追加nosun-agent首轮marker |
| 47:59:59 | 尚未到期，继续等待 |
| 任意时刻收到相关回复，哪怕只答Yes | 停止无回复分支，交HelpDesk人工，无自动后续邮件 |
| 48小时到期，两件且全部事实／授权满足 | 生成受限动作；假Confirm仅验证目标阻断解除，不谎称已生产 |
| 48小时到期，两件但缺授权 | 停在preview_ready，等待真实用户授权，不Confirm |
| 48小时到期，三件及以上 | 继续暂停，产生人工待办 |
| 发送超时／结果未知 | 不启动48小时；只对账，不盲重发 |

针对本单最终“只留337573”的实际内部处理摘要，系统未来遇到相同客户回复时只负责停止timeout并交人工；取消337567、退款和实际跟单动作仍由现有同事处理。本次没有自动重做这些已完成业务。

## 5. 执行结果

研究探针实际运行通过 **47项断言**，包括：

- 当前已处理单跳过；历史证据不全转人工；历史补全假设后才准备首信；已有人工联系不重发；MyDorris拒绝执行。
- 固定模板事实检查；一次模拟发送；重复执行不重发；备注失败仅补备注；计时起点不重置；保留人工原备注。
- 48小时前不推进；两件到期等待授权；合成授权后才模拟解除；release结果不等同生产。
- 三件超时、三件减两件、商品变化、其它ETA/comment阻断、人工接管、消息过期／不完整／关联不明均hold。
- 部分回复、截止后执行前收到回复均退出超时路径；关联投递失败阻止推进。
- 发送unknown无计时、不重发；回查无匹配仍unknown；回查匹配使用实际发送时间。
- 审核拒绝、账号错误、审核后事实变化时没有任何模拟发送；跨站点身份键独立。

真实邮件发送次数 **0**；真实Console写入次数 **0**；真实远程草稿 **0**；真实n8n执行次数 **0**。

这些是设计状态机断言，不是已经实现业务执行器的测试通过，也没有验证跨系统事务／并发或客户实际收件。后续必须移植到PHP执行器测试，补真实n8n、两个品牌受控邮箱和HelpDesk关联验收。

## 6. 发现的关键接口缺口

已读取当前部署API文档，`release`明确规定：**解除分配阻断，不改订单状态，不自动分配。** 因此要完成用户要求的“正常进入生产”，必须补齐并核实后半段：

```text
多件等待条件满足
→ 只处理目标多件事项，保留其它阻断
→ 恢复正常订单处理／待分配阶段
→ 由既有正常分配流程承接
→ 有真实分配／工单开工证据后，分别记录结果
```

不能调用一次整单审核把其它事项一并放掉；不能把release成功显示为已开工。两件动作必须有服务端前置条件校验及真实授权；原始两件规则证明和完整HelpDesk只读数据未具备时只能给建议。

## 7. 复现和文件

可版本化的脱敏输入：[订单夹具](../../resources/data/seawave-multi-dress-replay-ebd1295-v1.json)。没有客户姓名、邮箱、电话和地址。

可重跑的研究探针：[multi-dress-first-round-replay.py](../../ops/research/multi-dress-first-round-replay.py)。只导入标准库，不读取.env，不启动Laravel，不导入GwsClient，不创建任何真实传输客户端。

```bash
runuser -u csi -- python3 ops/research/multi-dress-first-round-replay.py \
  --fixture resources/data/seawave-multi-dress-replay-ebd1295-v1.json \
  --output-dir storage/app/private/research/multi-dress-replay-20261113/replay
```

输出：

- `storage/app/private/research/multi-dress-replay-20261113/replay/result.json`：逐项结果、假事件、内容摘要和限制。
- `storage/app/private/research/multi-dress-replay-20261113/replay/email-preview.txt`：完整本地邮件预览。
- 同一私有目录上级保存本次只读order-current、API文档及Gmail证据；客户原始资料不复制进版本化报告。

本次fixture SHA-256：`52af6e3a23089fa89a75fb674ee00133000b0d49c070f4e2546b3461dc1f75b4`。探针结果绑定该hash；原始证据路径及hash保留在fixture的provenance中。
