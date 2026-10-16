# 多站点账户、下单与订单查询使用说明（候选）

- 状态：待业务审核，不能直接作为自动发送依据
- 适用站点：使用当前统一商城前台的站点
- 站点变量：`{{ site.brand_name }}`、`{{ site.public_base_url }}`、`{{ site.customer_service_email }}`
- 浏览器核验日期：2026-10-16
- 已核验站点：Dorris Wedding、Dress Afford

## 使用原则

1. 品牌、域名和客服邮箱只能读取当前站点配置，不在策略中写死。
2. 先生成并校验完整英文答复；客户使用非英语时，再翻译为客户语言。
3. 页面按钮文字可能随前台版本变化。优先给出稳定入口和目标，不依赖“右上角”“第几个按钮”等容易过期的位置描述。
4. 不用历史问答中的按钮名称、促销、制作周期或支付方式作为当前事实。
5. 页面与本文不一致时，以当前页面为准，并将差异提交人工复核。

## 注册与登录

- 稳定入口：`{{ site.public_base_url }}/register`
- 当前页面同时提供 Sign In 与 Register。
- 注册需要邮箱、密码和确认密码；页面可能同时提供第三方登录方式。
- 不要承诺注册优惠金额，因为促销可能变化。
- 不要要求客户在问答中发送密码、验证码或完整支付信息。

英文规范回复：

> You can sign in or create an account at {{ site.public_base_url }}/register. To register, enter your email address and create a password. Please do not send us your password or verification code. If the page shows an error, send us a screenshot of the error message, but hide any sensitive information.

## 下单

- 官方说明入口：`{{ site.public_base_url }}/article/order-procedure.html`
- 稳定流程：打开商品页 → 选择页面要求的商品选项 → 加入购物袋/购物车 → 进入结账 → 登录或注册（如页面要求）→ 填写配送信息 → 选择可用配送与支付方式 → 核对并提交订单。
- 商品选项、配送方式、费用和支付方式必须以当前页面或实时接口为准。
- 不承诺一定存在 Coupon、Order Notes、某个支付方式或固定按钮名称。
- 不要引用 How to Order 页面中的固定制作/到货天数；交期必须走商品制作周期和配送查询流程。

英文规范回复：

> To place an order, open the product page and select all required options, then add the item to your bag or cart and proceed to checkout. Follow the current checkout page to sign in or register if required, enter your shipping details, select an available shipping and payment method, review the order, and submit it. You can also view the current guide at {{ site.public_base_url }}/article/order-procedure.html.

## 查询订单

- 稳定入口：`{{ site.public_base_url }}/order/status`
- 注册用户：登录账户后进入 My Orders 查看订单状态。
- 访客用户：在 Order Status 页面输入下单邮箱和订单号查询。
- 客户找不到订单时，先核对站点、下单邮箱和订单号；不要让客户公开发送完整个人信息。
- 页面查询失败时，收集经过脱敏的截图和错误提示，然后转人工。

英文规范回复：

> You can check your order at {{ site.public_base_url }}/order/status. If you placed the order while signed in, log in and open My Orders. If you checked out as a guest, enter the email address used for the order and your order number on the Order Status page. If the order still cannot be found, contact us at {{ site.customer_service_email }} with the order number and a screenshot that does not show sensitive payment information.

## 找不到商品

- 先使用问答对应的当前商品快照读取商品 URL，不拼接或猜测 URL。
- 商品已下架、URL 缺失或跳转异常时，不承诺库存或重新上架时间，转人工确认。

英文规范回复：

> You can view the item here: {{ product_url }}. If the page is unavailable or redirects unexpectedly, please send us a screenshot and the product number so we can check it for you.

## 页面或结账报错

1. 请客户刷新页面后重试。
2. 仍失败时，可尝试无痕窗口、其他受支持浏览器或网络。
3. 收集页面 URL、发生时间、浏览器/设备、错误文本和脱敏截图。
4. 不要求客户提供密码、验证码、完整卡号或安全码。
5. 仍无法完成时转人工或技术团队，不承诺修复时间。

## 已发现的文档差异

- 两个站点当前注册页显示 Register，但 How to Order 页面仍提及 Join Free。
- 当前历史问答曾使用 Add to Bag、Checkout、Place Order 等具体文案，而公开 How to Order 页面还出现 Add to Cart、Proceed to Pay、Pay now 等不同文案。
- Dorris Wedding 的 How to Order 页面包含固定到货天数、制作启动时间和追踪号时间；这些时间性陈述未在本次核验中验证，不应进入自动回复。

因此，面向客户的回复应使用上面的稳定流程描述，并提供当前站点页面链接；不要逐字复用可能过期的页面说明。

## 发布前需要业务确认

- 是否提供商品 hold/暂留及其期限。
- 是否提供线下或寄送试穿，以及是否存在例外。
- 是否提供客服电话或允许主动回拨。
- 各站点是否都支持 Google 登录、Order Notes 及相同支付方式。
- 公开 How to Order 页面中的时间性说明是否仍有效，是否需要同步更新商城页面。
