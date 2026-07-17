# Architecture & Conventions

Context for understanding how the Momento backend is built and the conventions
to follow when writing or modifying code.

## Stack

- **Laravel 12**, PHP `^8.2` (production runs on **PHP 8.3**).
- **MySQL** in production; tests run on in-memory **SQLite**.
- Autoloading: PSR-4 `App\` → `app/`, `Tests\` → `tests/`.
- Frontend build only (Vite 7 + Tailwind 4) — no Blade UI for the product.

## Dependency map (`composer.json`)

| Package                  | Use                                              |
|--------------------------|--------------------------------------------------|
| `tymon/jwt-auth` `^2.2`  | JWT auth (`auth:api` guard)                      |
| `laravel/sanctum` `^4`   | Installed, not the primary auth mechanism        |
| `stripe/stripe-php` `^19`| Stripe SDK for checkout/webhooks                 |
| `doctrine/dbal` `^4.4`   | Allows column modifications in migrations        |
| `pestphp/pest` `^3`      | Test runner                                       |
| `laravel/pint` `^1`      | Code style (auto-format)                         |
| `yasin_tgh/laravel-postman` | Generates Postman collection (`postman.php`)  |

## Directory conventions

- **Controllers** live under `app/Http/Controllers/` with subfolders:
  `Admin/`, `Api/` (incl. `Api/Admin`, `Api/TGC`), `Product/`, `TGC/`,
  `PaymentGateway/`. Root controllers handle auth, profile, orders, contacts, etc.
- **Services** hold business logic: `TGC/` (TGC integration), `PaymentGateway/`,
  `CartPriceResolver`, `SecretManager`.
- **DTOs** are immutable request objects under `app/DTOs/TGC/` (one per TGC call).
- **Enums** under `app/Enums/` (e.g. `TradingCardPackage`).
- **Jobs** under `app/Jobs/TGC/` for async publishing (`PublishDeckJob`,
  `PublishTradingJob`).
- **Resources** (`app/Http/Resources/`) transform models for the response
  envelope (e.g. `LoginUserResource`).
- **Requests** (`app/Http/Requests/`) are form-request validation objects.

## Request / response patterns

- **Response envelope** (standard shape, see `AuthController`):
  ```json
  { "success": true, "status": 201, "message": "...", "data": { } }
  ```
- Controllers wrap logic in `try/catch`, returning the envelope. Catch order:
  `ValidationException` → `QueryException` → `JWTException` → `\Throwable`.
  Hide DB error details outside `local` env.
- `apiResource` is used for CRUD (categories, products, orders, preorders,
  payments, subscribers).
- Validation uses `$request->validate([...])` inline or Form Request classes.

## Coding standards

- Follow PSR-12 + Laravel conventions. Run `./vendor/bin/pint` before committing.
- Use typed properties and return types where reasonable.
- Prefer Eloquent relationships over raw queries.
- Never call third-party HTTP APIs (TGC/Stripe) from controllers directly —
  route through a Service class.
- Single source of truth for pricing: `App\Services\CartPriceResolver` — do
  **not** compute cart totals inline in controllers.

## Configuration files of note

- `config/jwt.php` — JWT settings (secret in `.env` as `JWT_SECRET`).
- `config/services.php` — TGC keys: `services.tgc.base_url`, `api_key`,
  `api_key_id`, plus designer/user credentials sourced from `SecretManager`.
- `config/postman.php` — Postman export settings.
- `config/auth.php` — `api` guard uses `jwt` driver.

## Common commands

```bash
composer install            # install PHP deps
php artisan migrate          # run migrations
php artisan serve            # local dev server
php artisan test             # run Pest suite
./vendor/bin/pint            # auto-format
composer test                # config:clear + artisan test
```
