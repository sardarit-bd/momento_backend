# Auth & Roles

Context for authentication, authorization, and the user/role model.

## Authentication — JWT (`tymon/jwt-auth`)

- Guard: `auth:api` (configured in `config/auth.php`, driver `jwt`).
- Token secret: `JWT_SECRET` in `.env` (`config/jwt.php`).
- `User` model implements `Tymon\JWTAuth\Contracts\JWTSubject`:
  - `getJWTIdentifier()` → primary key.
  - `getJWTCustomClaims()` → returns `name`, `email`, `role` as JWT claims.
- Token lifecycle helpers via `Tymon\JWTAuth\Facades\JWTAuth`:
  - Issue: `JWTAuth::fromUser($user)` (register).
  - Authenticate: `JWTAuth::parseToken()->authenticate()` (me).
  - Refresh: `auth('api')->refresh()`.
  - Logout: `auth('api')->logout()`.
- Attempt credentials: `auth('api')->attempt($credentials)`.

## Endpoints (`routes/api.php`)

Public:
- `POST /api/register` — validates name/email/password(+phone/address/role),
  hashes password (`BCRYPT_ROUNDS=12`), default role `Customer`, returns token.
- `POST /api/login` — returns JWT token + user.
- `POST /api/auth/refresh`, `POST /api/auth/logout` (protected).
- `GET /api/auth/me` (protected) — returns the user from the parsed token.

## Password reset — OTP flow (`OtpController`)

1. `POST /api/forgotpass` → `otpSender` sends an OTP to the user.
2. `POST /api/verify` → `verifyOtp` verifies the OTP.
3. `POST /api/resetpass` → `resetPassword` sets a new password.

`User` stores `otp` and `otp_varified` (hidden in serialization). OTP values
are not exposed in API responses.

## Authorization — `RoleCheck` middleware

- Class: `app/Http/Middleware/RoleCheck.php`, alias `roles`.
- Usage: `->middleware(['auth:api', 'roles:Admin'])`.
- Logic:
  - If not authenticated → `abort(401)`.
  - Splits the `roles` argument on `,` (e.g. `Admin,Customer`).
  - If `Auth::user()->role` is not in the list → `abort(403, 'Unauthorized access.')`.
- The `role` column on `users` is a plain string; valid values: `Customer`
  (default on register) and `Admin`.

## Middleware registration

- `Authenticate` (`app/Http/Middleware/Authenticate.php`) extends Laravel's
  base; for `api/*` or JSON requests it returns `null` (stateless 401) instead
  of redirecting.
- Both `Authenticate` and `RoleCheck` are registered in
  `app/Http/Kernel.php` (Laravel 12: `$middlewareAliases` / route middleware).

## Guard notes

- `Sanctum` is installed but **not** the primary auth mechanism; JWT is used
  for the API. Do not introduce Sanctum tokens unless extending a specific flow.
- API is stateless — no session cookies. `SESSION_DRIVER=database` is used for
  non-auth session needs (e.g. flash), not for API login.
