# API & Routing

Context for the route surface of the Momento backend. All product APIs live
under `routes/api.php` (prefix `api/`); TGC-specific APIs live in
`routes/tgc.php`. `routes/web.php` is the untouched Laravel default.

## Route groups (`routes/api.php`)

### Public (no auth)
- `POST /api/register` — `AuthController@register`
- `POST /api/login` — `AuthController@login`
- `POST /api/forgotpass` — `OtpController@otpSender`
- `POST /api/verify` — `OtpController@verifyOtp`
- `POST /api/resetpass` — `OtpController@resetPassword`
- `GET /api/profile/{id}`, `PUT /api/profile/{id}` — `ProfileController`
- `POST /api/contact` — `ContactController@store`
- `GET /api/shop`, `GET /api/shop/{slug}` — `ProductController@index/@show`
- `POST /api/subscribers` — `SubscriberController@store`
- `GET /api/trading-card/packages` — `TradingCardPackageController@index`
- `POST /api/cart/price` — `CartPricingController@calculate`
- `POST /api/checkout` — `StripeController@createCheckoutSession`
- `POST /api/webhook/stripe` — `WebhookController@handle` (Stripe webhook)
- `POST /api/webhooks/tgc/receipt-shipped` — `TGCWebhookController@handle`
- `apiResource /api/payments` — `PaymentController`

### Protected (`auth:api`)
- `GET /api/auth/me`, `POST /api/auth/logout`, `POST /api/auth/refresh`
- `apiResource /api/preorders` — `PreOrderController`
- `apiResource /api/customer-orders` — `OrderController`
- `GET /api/myorders/{id}` — `OrderController@myorders`
- `GET/DELETE /api/contacts*` — `ContactController` index/destroy
- `GET /api/subscribers` — `SubscriberController@index`
- `GET /api/order/{id}` — `OrderController@show`
- `POST /api/order/{order}/retry` — `OrderController@retryPayment`
- `GET /api/admin/orders`, `GET /api/admin/orders/{id}` — `OrderController`

### Admin only (`auth:api` + `roles:Admin`)
- `apiResource /api/categories` — `CategoryController`
- `apiResource /api/products` — `ProductController`
- `POST /api/cardproduct`, `GET /api/cardproduct/{slug}` — `ProductController`
- `POST /api/updateproduct` — `ProductController@updateProductStatus`
- `apiResource /api/orders` (index/show/update only) — `AdminOrderController`
- `GET /api/orders/{order}/cancel`, `POST /api/orderupdate/{id}` —
  `AdminOrderController`
- `GET /api/orders/{order}/payments`, `PUT /api/payment/{orderHasPaid}/status`
  — `AdminOrderPaymentController`
- Full CRUD on `/api/secrets` (`index/show/store/update/destroy/restore`) —
  `SecretKeyController`

## TGC routes (`routes/tgc.php`) — all `auth:api`, prefix `tgc`

Controllers live in `app/Http/Controllers/Api/TGC/`:

- `POST /tgc/games`, `POST /tgc/folders`, `POST /tgc/files`
- `POST /tgc/decks`, `POST /tgc/games/{gameId}/decks`
- `POST /tgc/decks/{deckId}/cards`, `POST /tgc/cards`,
  `PUT /tgc/cards/{cardId}/proof`
- `POST /tgc/carts`, `POST /tgc/carts/{cartId}/items`,
  `GET /tgc/carts/{cartId}`, `GET /tgc/carts/{cartId}/items`,
  `PUT /tgc/carts/{cartId}`
- `POST /tgc/addresses`, `GET /tgc/addresses/{addressId}`
- `POST /tgc/publish` (`tgc.publish`), `GET /tgc/publish/{jobId}/status`
- `GET /tgc/receipts/{receiptId}`
- `GET /tgc/status/queue`, `GET /tgc/carts/{cartId}/estimate`

These controllers are thin wrappers around `App\Services\TGC\TGCService`.
Never add direct `Http::` calls to TGC in a controller.

## How to add an endpoint

1. Create/extend a controller in the correct `app/Http/Controllers/` subfolder.
2. Add the route in `routes/api.php` (or `routes/tgc.php` for TGC).
3. Choose the right middleware group: public / `auth:api` / `auth:api` + `roles:Admin`.
4. Return the standard envelope `{success, status, message, data}`.
5. For TGC calls, add a method to `TGCService` + a DTO in `app/DTOs/TGC/`,
   then call it from the controller — do not call `Http::` directly.
6. If the route is public, consider CSRF/CORS (CORS configured in
   `config/cors.php`; API uses `auth:api`, stateless).

## Controllers (`app/Http/Controllers/`)

| Folder / file            | Responsibility                                  |
|--------------------------|-------------------------------------------------|
| `AuthController`         | Register, login, me, refresh, logout            |
| `OtpController`          | OTP send/verify/reset password                  |
| `ProfileController`      | Public profile get/update                       |
| `Product/`               | `CategoryController`, `ProductController` (shop + admin) |
| `OrderController`        | Customer + admin order views, retry payment     |
| `Admin/`                 | `AdminOrderController`, `AdminOrderPaymentController` |
| `Api/Admin/SecretKeyController` | DB secret management                     |
| `Api/CartPricingController`     | Calls `CartPriceResolver`                  |
| `Api/TradingCardPackageController` | Lists trading packages                  |
| `PaymentController`      | Payments resource                               |
| `PaymentGateway/`        | `StripeController`, `WebhookController`         |
| `TGC/`                   | `TGCWebhookController` (incoming receipt webhook)|
| `PreOrderController`, `SubscriberController`, `ContactController` | Misc resources |
