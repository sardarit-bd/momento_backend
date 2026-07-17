# TGC (TheGameCrafter) Integration

Context for how Momento publishes decks/boxes and orders physical prints through
TheGameCrafter's API. **All TGC HTTP traffic must go through
`App\Services\TGC\TGCService` — never call TGC endpoints from a controller.**

## Configuration (`config/services.php` + `.env`)

- `TGC_BASE_URL` (`services.tgc.base_url`) — `https://www.thegamecrafter.com/api`.
- `TGC_API_KEY` (`services.tgc.api_key`) and `services.tgc.api_key_id`.
- `TGC_USERNAME`, `TGC_PASSWORD`, `TGC_DESIGNER_ID` — credentials. These are
  sourced at runtime via `App\Services\SecretManager` (DB `secret_keys` table),
  not hard-coded.
- `TGC_SESSION_CACHE_TTL_HOURS` (default 12) — session cache lifetime.

## Session management

- `App\Services\TGC\TGCSessionManager` authenticates with TGC and caches a
  `session_id`. Exposes:
  - `getSessionId()` — valid session or auto-authenticates.
  - `getDesignerId()`, `getUserId()`.
  - `authenticate()` — performs login, returns a new session id.
  - `flushSession()` — clears a stale/invalid session (called on HTTP 401).
- `TGCService::send()` performs a request, and on `401` flushes + re-authenticates
  once before failing with `TGCAuthException`.

## Service layer (`app/Services/TGC/TGCService.php`)

- Uses Laravel `Http` facade with `acceptJson()`, `timeout(30)`, `retry(2, 200)`.
- Form requests via `asForm()`; multipart uploads via `requestMultipart()`.
- Every call appends `session_id` to the payload.
- `handleResponse()` throws `App\Exceptions\TGC\TGCApiException` on non-2xx;
  logs status + body.
- Methods include: `createGame`, `createFolder`, `createDeck`,
  `uploadFile`/`uploadFolderFile`, `createCard`, `createCardFromFace`,
  `proofCard`, `createTuckBox`, `updateTuckBox`, `createCart`, `addSkuToCart`,
  `createAddress`, `getAddress`, `getCart`, `getCartItems`, `updateCart`,
  `uploadCardFaceImage`, `attachUserToCart`, `payWithShopCredit`,
  `fetchReceipt`, `getQueueStatus`, `listWebhooks`, `subscribeWebhook`.

## DTOs (`app/DTOs/TGC/`)

Immutable request objects passed into `TGCService` methods (one per call):
`CreateGameDTO`, `CreateFolderDTO`, `CreateDeckDTO`, `UploadFileDTO`,
`UploadFolderFileDTO`, `CreateCardDTO`, `CreateCardFromFaceDTO`, `ProofCardDTO`,
`CreateTuckBoxDTO`, `UpdateTuckBoxDTO`, `AddToCartDTO`, `CreateAddressDTO`.
Add a new DTO here when extending TGC capabilities; do not pass raw arrays
through controller→service boundaries.

## Supporting services

- `CardMergeService` — merges/composites card face + back images before upload.
- `TradingBoxCompositeService`, `TuckBoxCompositeService` — build composite
  box images for trading/tuck boxes.

## Routes (`routes/tgc.php`) — all `auth:api`, prefix `tgc`

Controllers in `app/Http/Controllers/Api/TGC/` are thin wrappers around
`TGCService`:
- Game/Folder/File/Deck/Card creation, card proofing.
- Cart operations (create, add item, get, update, estimate).
- Address create/get.
- `POST /tgc/publish` → `DeckPublishController@publish` (enqueues a job),
  `GET /tgc/publish/{jobId}/status` → poll job status.
- `GET /tgc/receipts/{receiptId}`, `GET /tgc/status/queue`.

## Async publishing (`app/Jobs/TGC/`)

- `PublishDeckJob` — publishes a deck to TGC (dispatched by `DeckPublishController`).
- `PublishTradingJob` — publishes a trading-card order/box.
- Queue connection is `database` (`QUEUE_CONNECTION=database`); run
  `php artisan queue:listen` (the `composer dev` script runs
  `queue:listen --tries=1`).

## Inbound webhooks

- `POST /api/webhooks/tgc/receipt-shipped` → `TGCWebhookController@handle`.
  Logs to `App\Models\TGCWebhookLog` and updates order shipment status.
- `TGCService::listWebhooks()/subscribeWebhook()` manage TGC event callbacks.

## Exceptions (`app/Exceptions/TGC/`)

- `TGCApiException` — non-2xx / business error from TGC.
- `TGCAuthException` — authentication/session failure (after retry).
