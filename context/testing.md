# Testing

Context for running and writing tests in the Momento backend.

## Stack

- **Pest 3** (`pestphp/pest` `^3`, `pestphp/pest-plugin-laravel` `^3`).
- PHPUnit under the hood; configured by `phpunit.xml` at repo root.
- In-memory **SQLite** for tests (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`).

## Configuration (`phpunit.xml`)

Test env overrides:
- `APP_ENV=testing`, `APP_MAINTENANCE_DRIVER=file`.
- `BCRYPT_ROUNDS=4` (faster hashing in tests).
- `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`
  (jobs run synchronously — no queue worker needed for tests).
- `MAIL_MAILER=array` (captures mail instead of sending).
- `PULSE_ENABLED` / `TELESCOPE_ENABLED` / `NIGHTWATCH_ENABLED` = false.

Test suites: `Unit` (`tests/Unit`), `Feature` (`tests/Feature`).

## Running tests

```bash
php artisan test            # runs Pest via Artisan (recommended)
composer test               # config:clear + artisan test
./vendor/bin/pest           # direct Pest binary
./vendor/bin/pest --filter=name   # run a single test
```

## Test scaffolding

- `tests/TestCase.php` — base `Tests\TestCase` extending
  `Illuminate\Foundation\Testing\TestCase` (Laravel/Pest bootstrap).
- `tests/Pest.php` — Pest global setup (uses `Tests\TestCase`, applies
  `RefreshDatabase` by default, loads helpers).
- `tests/Support/` — shared helpers/factories utilities.
- `tests/Feature/` — HTTP/endpoint tests (use `actingAs` with JWT or hit
  public routes; `RefreshDatabase` for isolation).
- `tests/Unit/` — pure unit tests for services (e.g. `CartPriceResolver`).

## Conventions

- Use Pest's expectation-style API and `it('...', fn () => ...)` or
  `test('...', fn () => ...)`.
- Wrap HTTP tests with `actingAs($user, 'api')` when testing protected routes
  (JWT guard). For admin routes, set `$user->role = 'Admin'`.
- For database tests, rely on `RefreshDatabase` (already applied in `Pest.php`).
- Mock external services: TGC (`TGCService`) and Stripe should be mocked/faked
  rather than hit live endpoints in tests.
- Factories live in `database/factories/` (PSR-4 `Database\Factories\`);
  seeders in `database/seeders/`.
- Keep tests fast: in-memory SQLite + `sync` queue + `array` cache/mail.

## Before considering work done

- Add/extend tests for new behavior (Feature for endpoints, Unit for services).
- Run `php artisan test` — all green.
- Run `./vendor/bin/pint` for style.
