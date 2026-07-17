# Payments & Orders

Context for the Stripe payment flow, the order lifecycle, and the single source
of truth for cart pricing.

## Cart pricing — single source of truth

**Never compute cart totals inline.** Always use `App\Services\CartPriceResolver`.

- `TAX_RATE = 0.08` (8%).
- `JOKER_ADDON_PRICE = 7.00` (added per unit when `has_joker` is true).
- `resolveUnitPrice(array $item)`:
  - For `type === 'trading'` products with a `package_slug`: looks up an active
    `TradingCardPackage` by slug, price = `price_cents / 100`. Invalid/inactive
    slug → `abort(422, 'Selected package is no longer available.')` (fails loud,
    no silent fallback to base price).
  - Otherwise base price = `final_price ?? price ?? 0`; adds joker add-on.
  - Returns `{base_price, joker_addon, unit_price}`.
- `priceCart(array $items)`: returns `{lines, subtotal, tax, total}` where
  `tax = subtotal * TAX_RATE`, `total = subtotal + tax`. Each line includes
  `qty`, `base_unit_price`, `joker_addon`, `unit_price`, `line_total`.
- Exposed publicly via `POST /api/cart/price` → `CartPricingController@calculate`.

## Stripe flow

SDK: `stripe/stripe-php` `^19`. Controllers in `app/Http/Controllers/PaymentGateway/`.

- `POST /api/checkout` → `StripeController@createCheckoutSession`
  (creates a Stripe Checkout Session; likely calls `CartPriceResolver` for the
  authoritative amount).
- `POST /api/webhook/stripe` → `WebhookController@handle`
  verifies the Stripe signature and updates order/payment state.
- `POST /api/order/{order}/retry` → `OrderController@retryPayment`
  re-attempts payment for a failed order.
- `apiResource /api/payments` → `PaymentController`.

## Order lifecycle

Models (see `context/domain-model.md`):
- `Order` (has `user_id`, `tgc_receipt_id`, trading-box fields, shipping
  city/zipcode, customization columns).
- `OrderItem` (line items; supports `card_customizations`, joker columns).
- `OrderItemCard` (per-card customization: `slot_name`, `character_blob`).
- `OrderHasPaid` (payment records; status updated by admin).
- `OrderShipment` (shipment tracking).
- `ShippingInformation` (ship-to address).

Customer endpoints:
- `apiResource /api/customer-orders` → `OrderController` (create/show/list).
- `GET /api/myorders/{id}` → customer's orders.
- `GET /api/order/{id}`, `POST /api/order/{order}/retry`.

Admin endpoints (`roles:Admin`):
- `apiResource /api/orders` (index/show/update) → `AdminOrderController`.
- `GET /api/orders/{order}/cancel`, `POST /api/orderupdate/{id}` (delivery status).
- `GET /api/orders/{order}/payments`, `PUT /api/payment/{orderHasPaid}/status`
  → `AdminOrderPaymentController`.
- `GET /api/admin/orders`, `GET /api/admin/orders/{id}` → `OrderController`.

## Pre-orders & subscriptions

- `apiResource /api/preorders` → `PreOrderController` (uses `PreOrderMapper`).
- `POST /api/subscribers`, `GET /api/subscribers` → `SubscriberController`.

## Integration touchpoints

- After a successful print order, `Order.tgc_receipt_id` ties the order to a
  TGC receipt (see `context/tgc-integration.md`). TGC shipping webhooks update
  `OrderShipment` via `TGCWebhookController`.
- Keep all money math in `CartPriceResolver`; controllers should persist the
  resolver's output (subtotal/tax/total) onto the `Order` for auditability.
