# Domain Model & Data Schema

Context for the Eloquent models, their relationships, and the database schema
that backs the Momento card-game / avatar store.

## Model inventory (27 models, `app/Models/`)

### Users & auth
- `User` — implements `JWTSubject`; fillable `name, email, password, role,
  phone, address, avatar`. Hidden: `otp, otp_varified, password,
  remember_token`. Relations: `orders()`, `preOrderMappers()`.
  - `role` is a plain string, values `"Customer"` (default) or `"Admin"`.
  - Custom JWT claims added in `getJWTCustomClaims()` (name, email, role).

### Catalog
- `Category` — product categories.
- `Product` — the central catalog entity. `type` is an enum-ish string
  (`customizable`, `simple`, `trading`, plus `photo` added in 2026_07_14).
  Fillable: `name, slug, image, type, short_description, description, price,
  status, offer_price, category_id`.
  - Relations: `category()`, `images()` (ProductHasImage), and one
    `hasMany` per customization part: `skin_tones, hairs, noses, eyes, mouths,
    dresses, crowns, base_cards, beards, trading_fronts, trading_backs`.
  - `getCustomRelations()` lists all customization relations;
    `getCustomizationsAttribute()` accumulates them for API responses.
  - `getImageUrlAttribute()` returns `asset('storage/...')` (appended).

### Customization parts (each `hasMany` from `Product`)
- `SkinTone`, `Hair`, `Nose`, `Eye`, `Mouth`, `Dress`, `Crown`, `BaseCard`
  (has `card_type`), `Beard`.
- `TradingFront`, `TradingBack` — trading card faces/backs.
- `ProductHasImage` — gallery images for a product.

### Orders & fulfillment
- `Order` — customer order. Has `user_id`, `tgc_receipt_id`, trading-box
  fields, city/zipcode columns, and customization refactor columns. Relations:
  `items()` (OrderItem), `shipments()` (OrderShipment), `payments()`
  (OrderHasPaid), `shippingInformation()` (ShippingInformation).
- `OrderItem` — line item; supports card customizations
  (`card_customizations`, joker columns) after the 2026 refactor.
- `OrderItemCard` — per-card customization on an order item
  (`slot_name`, `character_blob`).
- `OrderHasPaid` — payment record per order; status updated by admin.
- `OrderShipment` — shipment tracking per order.
- `ShippingInformation` — address/ship-to details.
- `PreOrderMapper` — links a product to a user pre-order; `product_id`,
  `user_id`.

### Trading cards
- `TradingCardPackage` — package options for trading-card products
  (`slug`, `price_cents`, `is_active`). See `Enums/TradingCardPackage.php`.
- `TradingFront`, `TradingBack` (see Catalog).

### Misc / platform
- `Contact` — contact-us submissions (store public, index/destroy admin).
- `Subscriber` — newsletter subscribers (public store, admin index).
- `SecretKey` — DB-backed secrets (name, value, environment, is_active,
  soft-deletable). Managed via `SecretManager` and admin `SecretKeyController`.
- `TGCWebhookLog` — log of incoming TGC webhook events.

## Migration history highlights (45 migrations)

- Base tables: `users`, `cache`, `jobs`, `personal_access_tokens`, `categories`,
  `products`, customization part tables (beard, crown, base_card, dress, hair,
  mouth, eye, nose, skin_tone), `product_has_images`, `contacts`.
- Orders cluster: `orders`, `order_items`, `order_has_paids`, then a large
  **2026 refactor** (`refactor_order_items_for_card_customizations`,
  `card_customizations`, customization columns, `order_item_cards` slot/character
  blobs) — this is the current order schema.
- TGC/print: `orders.tgc_receipt_id`, `orders.user_id`, trading-box fields,
  `trading_card_packages`, `order_shipments`, `tgc_webhook_logs`.
- `secret_keys` table (2025_12_11), `subscribers`, `pre_order_mappers`.
- `product.type` enum gradually expanded (`trading`, `photo`).

> When adding columns or tables, use `doctrine/dbal`-compatible migrations and
> keep `enum` changes consistent with existing string-based `type` columns
> unless a real enum is introduced.

## Key relationships to remember

- `Product` is the hub: it owns every customization part via `hasMany`.
- An `Order` has many `OrderItem`s; each `OrderItem` may have many
  `OrderItemCard`s (the physical card customizations sent to TGC).
- `OrderHasPaid` and `OrderShipment` are separate 1:N from `Order`.
- `TradingCardPackage` is referenced by slug (not FK) at pricing time via
  `CartPriceResolver`.
