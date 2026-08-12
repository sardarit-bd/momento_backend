# AGENTS.md — Momento Backend (Laravel 12 API)

This file is the entry point for any AI assistant working in this repository. It
explains what the project is, how it is structured, and where to find the deeper
context files. Read the linked context files when working on the relevant area.

## What this project is

**Momento** (`.env` `APP_NAME="Momento card game"`) is a Laravel 12 JSON API
backend for a customizable card-game / avatar store. It powers:

- User accounts (JWT auth, OTP password reset, profiles).
- A product catalog of customizable cards/avatars (skin tones, hair, eyes,
  mouths, noses, beards, crowns, dresses, base cards, trading fronts/backs).
- Customer orders, pre-orders, subscriptions, contacts.
- A **TheGameCrafter (TGC)** integration that publishes decks/boxes and orders
  physical prints through TGC's API.
- **Stripe** checkout + webhooks for payments.
- Admin endpoints (CRUD for products/categories/orders, secret-key management).

The API is consumed by a separate frontend; this repo is backend-only (no web
UI blade views are used for the product, `routes/web.php` is the Laravel default).

## Tech stack (quick reference)

| Concern        | Choice                                                             |
| -------------- | ------------------------------------------------------------------ |
| Framework      | Laravel 12, PHP `^8.2` (deploys on PHP 8.3)                        |
| Auth           | `tymon/jwt-auth` `^2.2` (`auth:api` guard), Sanctum installed      |
| Database       | MySQL (migrations are framework default + custom)                  |
| Payments       | Stripe (`stripe/stripe-php` `^19`) + webhooks                      |
| 3rd-party API  | TheGameCrafter (`TGC_BASE_URL=https://www.thegamecrafter.com/api`) |
| Queue / cache  | DB-driven (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`)    |
| Tests          | Pest 3 (`pestphp/pest`), in-memory SQLite                          |
| Code style     | `laravel/pint` (run `./vendor/bin/pint`)                           |
| Build / assets | Vite 7 + Tailwind 4 (frontend assets only)                         |
| Deploy         | `deploy.sh` (manual) + GitHub Actions `deploy.yml` on `main`       |
| Secrets        | DB-backed `SecretKey` model via `App\Services\SecretManager`       |

## Repository layout (mental map)

```
app/
  Http/Controllers/      # Route handlers, grouped: root, Admin/, Api/, TGC/, PaymentGateway/, Product/
  Http/Middleware/       # Authenticate, RoleCheck (roles:Admin)
  Http/Requests/         # Form request validation objects
  Http/Resources/        # API resource transformers (e.g. LoginUserResource)
  Models/                # Eloquent models (27 of them)
  Services/              # CartPriceResolver, SecretManager, TGC/, PaymentGateway/
  DTOs/TGC/              # Immutable request DTOs for the TGC service
  Enums/                 # TradingCardPackage enum
  Jobs/TGC/              # Queue jobs for async deck/box publishing
  Exceptions/TGC/        # TGCApiException, TGCAuthException
  Observers/ Notifications/ Mail/ Actions/ Console/ Providers/ public/
routes/                  # api.php (main), tgc.php (TGC), web.php, console.php
database/migrations/     # 45 migrations, default + heavily customized (card/order refactor)
config/                  # jwt.php, services.php (tgc keys), postman.php, etc.
tests/                   # Pest Feature/Unit, TestCase.php, Pest.php
deploy.sh                # Manual tar+scp+ssh deploy to VPS
.github/workflows/       # deploy.yml (push to main -> VPS)
```

## Ground rules when editing this repo

- Use JWT auth (`auth:api` guard) for protected routes; admin routes add
  `roles:Admin`. See `context/auth-roles.md`.
- All business logic that talks to TGC goes through `App\Services\TGC\TGCService`
  and the DTOs — never call TGC HTTP endpoints from controllers directly. See
  `context/tgc-integration.md`.
- Pricing is the single source of truth in `App\Services\CartPriceResolver`
  (`TAX_RATE=0.08`, joker add-on `$9`). Never compute cart totals inline. See
  `context/payments-orders.md`.
- API responses follow a `{success, status, message, data}` envelope (see
  `AuthController`). Keep that shape consistent.
- Run `php artisan test` and `./vendor/bin/pint` before considering work done.
- Do NOT commit `.env` or secrets. Production secrets live in the `secret_keys`
  table, not in code.

## Context files (load when relevant)

Read the matching file for deeper, area-specific guidance:

- `context/architecture.md` — stack, conventions, request/response patterns, coding standards.
- `context/domain-model.md` — Eloquent models, relationships, key migrations, the customization & order schema.
- `context/api-routing.md` — every route group, controllers, and how to add endpoints.
- `context/auth-roles.md` — JWT setup, `RoleCheck` middleware, user model, OTP flow.
- `context/tgc-integration.md` — TheGameCrafter service, DTOs, session manager, jobs, webhooks.
- `context/payments-orders.md` — Stripe checkout/webhooks, order lifecycle, cart pricing.
- `context/testing.md` — Pest setup, running tests, writing Feature/Unit tests.
- `context/deployment.md` — `deploy.sh`, GitHub Actions, VPS layout, env handling.

> Note: when editing code, the priority is to keep the existing patterns shown in
> these files. Prefer adding context files over editing this root when a new
> subsystem is introduced.
