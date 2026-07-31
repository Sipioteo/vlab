# Visionary Lab — Equipment Loan Platform
## Complete Technical Specification (v1.1, binding)

**Status:** FROZEN CONTRACT. Backend and frontend teams implement in parallel against this document.
**Scope:** full rewrite/modernization of `https://prestitivlab.polito.it` (Politecnico di Torino — Visionary Lab / Ufficio Multimedialità equipment loans).
**Language:** all code, identifiers, JSON keys, DB columns, commit messages in **English**. All end-user visible copy in **Italian**.
**Date format everywhere in the API:** ISO-8601. Dates `YYYY-MM-DD`, times `HH:MM` (24h, no seconds), timestamps `YYYY-MM-DDTHH:MM:SSZ` (UTC, `Z` suffix, seconds precision).

**v1.1 (2026-07-31):** documented two features implemented after the v1.0 freeze — the order form PDF export (§7.5 #89, §7.9 #89) and substitute products (§6.22, §7.4 `Product.substitutes`, §7.5 #87/#88, §7.7 #87, §7.8 #32 `suggested_substitutes`, §7.9 #88). No previously frozen field or endpoint was renamed or removed.

> **Rule for implementers:** if this document and your intuition disagree, this document wins. If something is genuinely absent, pick the option that requires the *fewest* new JSON fields and add a `TODO(spec)` comment. Never rename a JSON field.

---

## Table of Contents

1. [System overview & architecture](#1-system-overview--architecture)
2. [Repository layout](#2-repository-layout)
3. [Configuration & environment](#3-configuration--environment)
4. [Authentication & authorization design](#4-authentication--authorization-design)
5. [Domain model & key algorithms](#5-domain-model--key-algorithms)
6. [Database schema](#6-database-schema)
7. [REST API contract](#7-rest-api-contract)
8. [Order state machine](#8-order-state-machine)
9. [Permission matrix](#9-permission-matrix)
10. [Settings registry](#10-settings-registry)
11. [Frontend specification](#11-frontend-specification)
12. [Design system](#12-design-system)
13. [Testing requirements](#13-testing-requirements)
14. [run.sh contract](#14-runsh-contract)
15. [Seed data & catalog import](#15-seed-data--catalog-import)
16. [Appendix A — enumerations](#appendix-a--enumerations)
17. [Appendix B — Italian UI glossary](#appendix-b--italian-ui-glossary)

---

# 1. System overview & architecture

## 1.1 What the system does

The Visionary Lab lends audio/video/lighting/VR equipment to students of Politecnico di Torino (primarily the *Ingegneria del Cinema e dei Mezzi di Comunicazione* degree course and DAUIN). The platform must:

- Publish a browsable **catalog** of equipment organized in categories (real categories observed on the legacy site: *Audio*, *Audio - Accessori e Cavi*, *Hardware e Software*, *Luci - Accessori - Fondali*, *Materiale Elettrico*, *Supporti*, *Tecnologie Interattive*, *Video*, *Video - Accessori e Cavi*).
- Let authenticated students build a **booking cart** and submit a loan request (*richiesta di prestito*) for a date range.
- Let lab staff **approve/reject**, hand out (*consegna*), and take back (*riconsegna*) equipment, tracking individual physical units.
- Track **per-unit condition history** (damage, maintenance, inspection, notes).
- Enforce **runtime-configurable rules** (opening hours, closures, loan duration caps, monthly/yearly quotas, advance-booking windows) with *no* hardcoded business constants.
- Enforce **regulation acceptance** (global regulations at first login / on version bump; product- or category-scoped regulations at checkout — e.g. the epilepsy warning for VR headsets).
- Provide **statistics dashboards** for staff.

## 1.2 Architecture

```
┌──────────────────────────────┐          ┌───────────────────────────────────┐
│  Browser (SPA)               │          │  PHP 8.1 / Slim 4                 │
│  React 18 + TS + Vite        │  HTTPS   │  public/index.php front controller│
│  dev: http://localhost:8080  │ ───────► │  dev: http://localhost:8081       │
│  proxies /api → :8081        │  JSON    │  /api/v1/*                        │
└──────────────────────────────┘          │                                   │
                                          │  ┌─────────────────────────────┐  │
                                          │  │ Middleware pipeline         │  │
                                          │  │ CORS → JSON body → Auth     │  │
                                          │  │ → Role → Validation → Route │  │
                                          │  └─────────────────────────────┘  │
                                          │  ┌──────────┐  ┌──────────────┐   │
                                          │  │ Services │  │ Eloquent ORM │   │
                                          │  │ (domain) │  │ illuminate/db│   │
                                          │  └──────────┘  └──────┬───────┘   │
                                          │  ┌───────────────────┐│           │
                                          │  │ LdapAuthenticator ││           │
                                          │  │  Interface        ││           │
                                          │  │  ├ RealLdap...    ││           │
                                          │  │  └ FakeLdap...    ││           │
                                          │  └───────────────────┘│           │
                                          └───────────────────────┼───────────┘
                                                                  ▼
                                                 SQLite (default) | MySQL | PostgreSQL
```

**Stateless backend.** No PHP sessions. Authentication state lives entirely in a JWT held by the SPA plus a `refresh_tokens` DB row. This is required so the backend can be run behind `php -S` in dev and any SAPI in production.

## 1.3 Cross-database portability rules (MANDATORY)

The same migrations and the same queries must run on SQLite, MySQL 8 and PostgreSQL 14+. Therefore:

- **No native `ENUM` columns.** Use `string(32)` (or `string(64)`) plus application-level validation against the enum lists in [Appendix A](#appendix-a--enumerations). Reason: SQLite has no ENUM and PostgreSQL ENUM migration is painful.
- **No DB-specific date functions** (`DATE_ADD`, `DATEDIFF`, `strftime`, `date_trunc`) in application queries. All date arithmetic is done in PHP with `DateTimeImmutable`. Aggregation "by day/week/month" is done by selecting raw rows into PHP and bucketing there, **or** by selecting a pre-computed `*_bucket` string column (see `orders.created_month`). Statistics endpoints MUST use the PHP-side bucketing approach.
- **No `JSON_*` SQL functions.** JSON columns are read/written wholesale; filtering on JSON contents happens in PHP.
- **No `ON DELETE CASCADE` reliance for business logic** — declare it in migrations where noted, but services must also delete/guard explicitly (SQLite requires `PRAGMA foreign_keys=ON`, which the bootstrap MUST set).
- `boolean` columns are `tinyint(1)`/`boolean`; always cast to real PHP `bool` in Eloquent `$casts` and always emit real JSON `true`/`false`.
- Money is not used anywhere. Decimals are not used anywhere.
- All timestamps stored in **UTC**. The display timezone comes from the `hours.timezone` setting (default `Europe/Rome`). Business-day computations (opening hours, closures, loan duration) are performed in the **lab timezone**, not UTC.
- Text search uses `LIKE '%term%'` with `LOWER()` applied to both sides (portable). No full-text indexes.

## 1.4 Backend framework conventions

- **Slim 4** with PSR-7 (`slim/psr7`), PSR-11 container (`php-di/php-di`), PSR-15 middleware.
- **illuminate/database** ^9.x (PHP 8.1 compatible) booted through `Illuminate\Database\Capsule\Manager`, with `$capsule->setAsGlobal(); $capsule->bootEloquent();` in `src/bootstrap.php`.
- Migrations: use `illuminate/database`'s schema builder driven by a **hand-rolled migration runner** (`bin/console migrate`) that reads ordered classes from `database/migrations/` and records applied names in a `migrations` table (`id`, `migration`, `batch`, `ran_at`). Do **not** pull in the full Laravel framework.
- Controllers are single-responsibility invokable classes in `src/Http/Controllers`. Business rules live in `src/Domain/*Service` classes; controllers do validation + serialization only.
- Serialization: every model has an explicit `App\Http\Resources\XResource::toArray()` — **never** return `$model->toArray()` directly. The JSON field names in [§7](#7-rest-api-contract) are the contract.
- All responses are `Content-Type: application/json; charset=utf-8`.

---

# 2. Repository layout

```
/home/user/vlab
├── run.sh                        # single entrypoint (see §14)
├── SPEC.md                       # this file
├── README.md
├── .gitignore                    # vendor/, node_modules/, *.sqlite, .env, storage/
├── data/
│   └── catalog.json              # scraped catalog, input to the seeder (§15)
├── backend/
│   ├── composer.json
│   ├── .env.example
│   ├── public/
│   │   └── index.php             # front controller (ONLY web-reachable PHP file)
│   ├── bin/
│   │   └── console               # CLI: migrate, migrate:fresh, seed, settings:list, user:role
│   ├── config/
│   │   ├── settings.php          # returns array; reads env with defaults
│   │   └── database.php
│   ├── src/
│   │   ├── bootstrap.php         # container, Eloquent capsule, middleware, routes
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   ├── Middleware/       # Cors, JsonBodyParser, Authenticate, RequireRole,
│   │   │   │                     # RequireRegulationAcceptance, ErrorHandler, RateLimit
│   │   │   ├── Resources/        # JSON serializers
│   │   │   └── Validation/       # Validator + rule sets per endpoint
│   │   ├── Domain/
│   │   │   ├── Auth/
│   │   │   │   ├── LdapAuthenticatorInterface.php
│   │   │   │   ├── RealLdapAuthenticator.php
│   │   │   │   ├── FakeLdapAuthenticator.php
│   │   │   │   ├── LdapUser.php               # value object
│   │   │   │   ├── RoleResolver.php
│   │   │   │   └── JwtService.php
│   │   │   ├── Availability/AvailabilityService.php
│   │   │   ├── Calendar/CalendarService.php   # opening hours, closures, slots
│   │   │   ├── Orders/OrderService.php
│   │   │   ├── Orders/OrderStateMachine.php
│   │   │   ├── Orders/LimitsEvaluator.php
│   │   │   ├── Regulations/RegulationService.php
│   │   │   ├── Settings/SettingsRepository.php
│   │   │   └── Stats/StatsService.php
│   │   ├── Models/               # Eloquent models
│   │   └── Support/              # helpers: Dates, Str, Paginator, AuditLogger
│   ├── database/
│   │   ├── migrations/           # 0001_create_users_table.php, ...
│   │   ├── seeders/              # SettingsSeeder, CatalogSeeder, FakeUsersSeeder,
│   │   │                         # RegulationsSeeder, DemoOrdersSeeder
│   │   └── vlab.sqlite           # gitignored, created by run.sh
│   ├── storage/                  # gitignored; uploads/regulations/*.pdf, logs/
│   ├── tests/
│   │   ├── Unit/
│   │   ├── Feature/
│   │   ├── TestCase.php          # boots app with in-memory SQLite + migrations
│   │   └── bootstrap.php
│   └── phpunit.xml
└── frontend/
    ├── package.json
    ├── vite.config.ts            # port 8080, proxy /api → http://localhost:8081
    ├── tsconfig.json
    ├── vitest.config.ts
    ├── index.html
    └── src/
        ├── main.tsx
        ├── App.tsx               # router
        ├── api/                  # typed client: client.ts + one file per resource
        ├── types/api.ts          # hand-written TS mirrors of §7 payloads
        ├── auth/                 # AuthProvider, useAuth, RequireRole, RequireRegs
        ├── components/           # reusable UI
        ├── features/             # catalog/, cart/, orders/, admin/, stats/, regulations/
        ├── pages/                # one file per route (§11)
        ├── hooks/
        ├── i18n/it.ts            # ALL user-facing strings live here
        ├── styles/               # tokens.css, base.css
        └── test/setup.ts
```

**Hard rule:** the frontend never hardcodes an Italian string inside a component. Every label goes through `i18n/it.ts` (`t('catalog.title')`). This keeps future EN localization cheap and makes copy review possible without touching components.

---

# 3. Configuration & environment

## 3.1 Two-layer configuration

| Layer | Source | Contains | Editable at runtime |
|---|---|---|---|
| **Boot config** | env vars / `.env` | DB connection, JWT secret, LDAP_MODE, app URL, upload dir, debug | No (restart required) |
| **Settings** | `settings` DB table | everything business-related (§10) | Yes, by admin via API/UI |

Rule: **anything a lab manager could plausibly want to change is a Setting, not env.** Env holds only what is needed to reach the DB and decide which authenticator to construct.

## 3.2 Environment variables (`backend/.env`)

```ini
APP_ENV=local                 # local | test | production
APP_DEBUG=true                # verbose error payloads when true
APP_URL=http://localhost:8081
APP_FRONTEND_URL=http://localhost:8080   # used for CORS allow-list

DB_DRIVER=sqlite              # sqlite | mysql | pgsql
DB_DATABASE=database/vlab.sqlite   # path for sqlite, db name otherwise
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_PREFIX=

JWT_SECRET=change-me-in-production-min-32-chars
JWT_ALGO=HS256
JWT_ISSUER=vlab

LDAP_MODE=fake                # fake | real  — OVERRIDES the ldap.mode setting

# Only used when LDAP_MODE=real AND the corresponding setting is empty.
LDAP_HOST=
LDAP_PORT=389
LDAP_ENCRYPTION=none          # none | ssl | tls
LDAP_BASE_DN=
LDAP_BIND_DN=
LDAP_BIND_PASSWORD=

STORAGE_PATH=storage
UPLOAD_MAX_BYTES=10485760     # 10 MiB
LOG_LEVEL=debug
```

`config/settings.php` returns a nested array built from these with the defaults shown. **Missing `JWT_SECRET` in `APP_ENV=production` must abort boot with a clear fatal error.** In `local`/`test` a deterministic fallback secret is allowed.

## 3.3 Precedence for LDAP mode

```
LDAP_MODE env var (if set and non-empty)  >  settings key `ldap.mode`  >  'fake'
```

The container binds `LdapAuthenticatorInterface` to the concrete class at build time based on the resolved mode. `GET /api/v1/health` reports the active mode so the SPA can show a dev banner.

## 3.4 CORS

Allowed origin: value of `APP_FRONTEND_URL` (plus `http://localhost:8080` and `http://127.0.0.1:8080` always, in `local`/`test`). Allowed methods `GET,POST,PUT,PATCH,DELETE,OPTIONS`. Allowed headers `Authorization,Content-Type,Accept,X-Requested-With`. Exposed headers: none. `OPTIONS` preflight answered with `204`. Credentials: **not** used (bearer token only).

---

# 4. Authentication & authorization design

## 4.1 Flow

```
SPA POST /api/v1/auth/login {username, password}
   │
   ├─► LdapAuthenticatorInterface::authenticate(username, password) : ?LdapUser
   │        LdapUser { uid, email, first_name, last_name, display_name, groups[] }
   │        returns null on bad credentials; throws LdapUnavailableException on
   │        connection failure (→ HTTP 503, code "ldap_unavailable")
   │
   ├─► RoleResolver::resolve(LdapUser, ?User $existing) : string role
   │        1. if existing user has role_locked = true  → keep existing.role  (LOCAL OVERRIDE WINS)
   │        2. else map LdapUser.groups through setting `ldap.role_map`
   │           (first match in map order wins; map order = insertion order of the JSON object)
   │        3. else setting `ldap.default_role` (default "student")
   │
   ├─► User row upserted by `ldap_uid` (create on first login, refresh
   │        email/first_name/last_name/display_name/last_login_at every login)
   │        If user.is_active = false → 403 "account_disabled"
   │
   └─► JwtService::issue(user) → access token + refresh token row
```

## 4.2 `LdapAuthenticatorInterface`

```php
<?php
namespace App\Domain\Auth;

interface LdapAuthenticatorInterface
{
    /**
     * @throws LdapUnavailableException when the directory cannot be reached / bound.
     * @return LdapUser|null null == credentials rejected
     */
    public function authenticate(string $username, string $password): ?LdapUser;

    /** Connectivity probe used by POST /api/v1/settings/ldap/test. */
    public function testConnection(): LdapTestResult;

    /** 'fake' | 'real' */
    public function mode(): string;
}
```

`LdapUser` is an immutable value object:

```php
final class LdapUser {
    public function __construct(
        public readonly string $uid,          // e.g. "s123456" / "student1"
        public readonly ?string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $displayName,
        /** @var string[] full DNs or CNs of groups */
        public readonly array $groups = [],
        /** @var array<string,mixed> raw attributes, for debugging */
        public readonly array $raw = [],
    ) {}
}
```

`LdapTestResult { bool $ok; string $message; ?int $latencyMs; ?int $entriesFound; }`

### RealLdapAuthenticator

- Uses the `ldap` PHP extension. If `!extension_loaded('ldap')` the constructor throws `LdapUnavailableException('php-ldap extension missing')`.
- **All parameters read from settings** (`ldap.*`, §10), never hardcoded. Env values are used only as defaults when the setting is empty.
- Sequence: `ldap_connect(host, port)` → `ldap_set_option(LDAP_OPT_PROTOCOL_VERSION, 3)` → `ldap_set_option(LDAP_OPT_REFERRALS, 0)` → `ldap_set_option(LDAP_OPT_NETWORK_TIMEOUT, ldap.timeout_seconds)` → if `encryption=tls` then `ldap_start_tls()` → service-bind with `ldap.bind_dn`/`ldap.bind_password` (anonymous if empty) → `ldap_search(base_dn, sprintf(user_filter, ldap_escape($username, '', LDAP_ESCAPE_FILTER)))` → must return exactly 1 entry → re-bind as that entry's DN with the supplied password → on success, read attributes named by `ldap.attr_*` settings → resolve groups either from the user entry's `ldap.attr_groups` attribute **or**, if `ldap.group_base_dn` is non-empty, by a second search `(&(objectClass=groupOfNames)(member=<userDn>))`.
- Never logs the password. Logs at debug level: host, base_dn, resolved DN, group count.

### FakeLdapAuthenticator

- Reads from the `fake_ldap_users` table (§6.19). Password check is `password_verify($password, $row->password_hash)`.
- `testConnection()` always returns `ok: true, message: "Fake LDAP attivo"`.
- Seeded users (see §15.3) — **exact credentials, do not change**:

| username | password | role via groups | display name | email |
|---|---|---|---|---|
| `student1` | `password` | `student` | Marco Rossi | student1@studenti.polito.it |
| `student2` | `password` | `student` | Giulia Bianchi | student2@studenti.polito.it |
| `tecnico1` | `password` | `technician` | Luca Ferrero | tecnico1@polito.it |
| `borsista1` | `password` | `assistant` | Sara Conti | borsista1@polito.it |
| `admin1` | `password` | `admin` | Anna Ricci | admin1@polito.it |

Their `groups` arrays are respectively `["cn=studenti,ou=groups,dc=polito,dc=it"]`, `["cn=tecnici,ou=groups,dc=polito,dc=it"]`, `["cn=borsisti,ou=groups,dc=polito,dc=it"]`, `["cn=vlab-admin,ou=groups,dc=polito,dc=it"]`, and they resolve through the default `ldap.role_map` (§10).

## 4.3 JWT

Library: `firebase/php-jwt` ^6.4.

**Access token** — `HS256`, secret `JWT_SECRET`, TTL from setting `security.jwt_ttl_minutes` (default 480 = 8h).

```json
{
  "iss": "vlab",
  "sub": "17",
  "iat": 1753900000,
  "exp": 1753928800,
  "jti": "b1f0…",
  "uid": "student1",
  "role": "student",
  "name": "Marco Rossi",
  "ver": 3
}
```

- `sub` = `users.id` as a **string**.
- `ver` = `users.token_version`. Incrementing that column (role change, deactivation, forced logout) invalidates every outstanding access token for that user. The `Authenticate` middleware MUST compare `ver` against the DB value and reject with `401 token_stale` on mismatch.
- `role` in the token is a *hint for the UI only*. **Every authorization check on the backend re-reads the role from the DB.** Never trust the claim.

**Refresh token** — opaque 64-char random hex string, stored **hashed** (`hash('sha256', $token)`) in `refresh_tokens`. TTL from `security.jwt_refresh_ttl_days` (default 14). Rotating: `POST /api/v1/auth/refresh` consumes the presented token (sets `revoked_at`) and issues a new pair. Presenting an already-revoked token revokes the entire token family (`family_id`) and returns `401 refresh_reused`.

## 4.4 Middleware pipeline (outermost → innermost)

1. `ErrorHandlerMiddleware` — converts exceptions to the standard error envelope (§7.3).
2. `CorsMiddleware`
3. `JsonBodyParserMiddleware` — parses `application/json`; rejects malformed JSON with `400 invalid_json`.
4. `RateLimitMiddleware` — only on `POST /auth/login`: max 10 attempts per `username` **and** per IP per 15 minutes (in-DB counter table is not required; an in-memory/APCu or file-based counter under `storage/ratelimit/` is acceptable). Exceeded → `429 too_many_attempts`.
5. `AuthenticateMiddleware` — for routes flagged auth-required: extracts `Authorization: Bearer <jwt>`, validates, loads `User`, stores it in the request attribute `user`. Missing/invalid → `401`.
6. `RequireRoleMiddleware(...$roles)` — 403 on mismatch.
7. `RequireRegulationAcceptanceMiddleware` — applied **only** to `POST /api/v1/orders`. Verifies every required regulation for the cart contents has a current acceptance; 409 otherwise (§7.9).

Route groups declare their requirements declaratively in `src/bootstrap.php`.

---

# 5. Domain model & key algorithms

## 5.1 Inventory model — DECISION: individual `product_units` (with pooled reservation)

**Chosen approach: one DB row per physical unit (`product_units`), while bookings reserve an anonymous *quantity* from the pool; concrete units are assigned only at pickup.**

Justification (short):

1. The legacy catalog is already unit-numbered (`Microfono Rode NTG4 01`, `… 02`) and paints a *per-unit* status (`Prestabile`, `In prestito`, `In uso`, `Mancante`, `Dismesso`). A single `quantity` integer cannot express "unit 03 is in maintenance, unit 04 is missing".
2. The required fields **serial number, asset code (*codice inventario*), purchase date, inspection date (*data di collaudo*)** are intrinsically per-unit; putting them on the product would be a lie the moment a lab owns two of anything.
3. Damage logs must be attributable to a specific unit ("the spring on VR headset 02 is lost"), otherwise maintenance history is useless.
4. Reserving anonymous quantity (rather than a specific unit) at booking time keeps the availability math a simple counting problem, avoids reservation fragmentation, and lets staff hand out whichever unit is physically nearest. Assignment at pickup (`order_item_units`) preserves full traceability where it matters.

Consequences the teams must respect:

- `products.quantity` does **not** exist as a writable field. `products` expose a **derived, read-only** `units_total` and `units_available` (units in a loanable status *right now*, ignoring bookings) plus `available_quantity` (range-aware, only present on availability-aware responses).
- Creating a product with `initial_units: N` auto-generates N `product_units` rows with `label` = `sprintf('%02d', i)` and `status='available'`.
- The catalog seeder turns `products[].quantity` from `catalog.json` into N unit rows.
- Deleting a unit is only allowed if it is not assigned to a non-terminal order; otherwise `409 unit_in_use`.

## 5.2 Availability algorithm (normative)

**Definitions**

> **Terminology:** *due date* is prose for the DB column `orders.return_date` and the JSON field `return_date` (the date the equipment is expected back). `returned_at` is the *actual* return timestamp. There is no separate `due_date` column or JSON field anywhere.

- A loan request covers the inclusive date range `[pickup_date, due_date]`.
- Setting `booking.buffer_days_between_loans` (default `0`) extends every *existing* order's occupied range by `B` days at the end: occupied range = `[pickup_date, due_date + B]`.
- **Stock-locking statuses** (`LOCKING`): `pending` (only if `booking.pending_locks_stock` is true, default true), `approved`, `picked_up`, `overdue`. All other statuses (`draft`, `rejected`, `cancelled`, `no_show`, `returned`, `returned_late`) do **not** lock stock.

**Capacity**

```
capacity(product) = COUNT(product_units WHERE product_id = P
                                          AND status = 'available'
                                          AND deleted_at IS NULL)
```
If `products.status != 'available'` then `capacity = 0` for booking purposes (product-level master switch).

> Units whose status is `maintenance`, `missing`, `retired` or `internal_use` are excluded from capacity **for the entire horizon**. Time-boxed maintenance is out of scope for v1; staff flip the unit status manually.

**Reserved quantity on a date**

```
reserved(P, d) = SUM(order_items.quantity)
                 over orders O JOIN order_items I ON I.order_id = O.id
                 WHERE I.product_id = P
                   AND O.status IN LOCKING
                   AND O.pickup_date <= d
                   AND (O.return_date + B days) >= d      -- day arithmetic done in PHP, not SQL
```

**Available quantity over a range** (this is the number a student may still book):

```
available(P, [s,e]) = capacity(P) - MAX over d in [s..e] of reserved(P, d)
```
i.e. the *bottleneck day* governs. Clamped at `>= 0`.

**Implementation note (portability):** load all locking `order_items` joined to their orders that overlap `[s, e + B]` for the products of interest in ONE query, then compute the per-day maxima in PHP. Never do per-day SQL.

**Excluding an order from its own calculation:** when re-validating an existing order (edit/approve), pass `excludeOrderId` so the order does not block itself.

**Concurrency:** `POST /api/v1/orders` (and every approve transition) MUST re-run the availability check *inside a DB transaction* immediately before writing. On SQLite use `BEGIN IMMEDIATE`; on MySQL/PG take `SELECT ... FOR UPDATE` on the affected `products` rows (skip on SQLite). If capacity is insufficient at that moment → `409 insufficient_availability` with the offending product ids.

## 5.3 Calendar rules

`CalendarService` answers: *is date D a valid pickup date? a valid return date? which time slots?*

A date is **bookable-as-pickup** iff ALL hold:
1. `D >= today + booking.min_advance_days` (in lab timezone).
2. `D <= today + booking.max_advance_days`.
3. The weekday entry in `hours.weekly` for `D` has `closed = false`.
4. `D` is not inside any `closures` row where `blocks_pickup = true`.

A date is **bookable-as-return** iff conditions 3 and 4 hold (with `blocks_return = true`) and `D >= pickup_date`.

**Time slots** are generated from `hours.pickup_windows` / `hours.return_windows` for that weekday, sliced at `hours.slot_duration_minutes`. A slot is emitted as `{"start":"14:00","end":"14:30"}`. If a weekday has no window entry, it falls back to the weekday's `open`/`close` from `hours.weekly`.

**Auto-shift rule:** the system never silently shifts dates. If a user submits a non-bookable date, the API returns `422` with `code: "date_not_bookable"` and a `suggestions` array of the 3 nearest valid dates.

## 5.4 Loan limits (`LimitsEvaluator`)

Evaluated at checkout **and** re-evaluated at approve. Produces a list of violations:

| Violation code | Rule | Severity |
|---|---|---|
| `max_loan_days_exceeded` | `due_date - pickup_date + 1 > booking.max_loan_days` | **soft** |
| `max_loan_days_hard_cap_exceeded` | `> booking.max_loan_days_hard_cap` (nullable) | **hard** |
| `max_orders_per_month_exceeded` | count of user's orders with `pickup_date` in the same calendar month and status ∉ {`draft`,`rejected`,`cancelled`} `>= booking.max_orders_per_month` | **soft** |
| `max_orders_per_year_exceeded` | same but calendar year vs `booking.max_orders_per_year` | **soft** |
| `max_active_orders_exceeded` | count of user's orders in {`pending`,`approved`,`picked_up`,`overdue`} `>= booking.max_active_orders` | **soft** |
| `max_items_per_order_exceeded` | distinct products in cart `> booking.max_items_per_order` | **hard** |
| `max_quantity_per_product_exceeded` | any item quantity `> booking.max_quantity_per_product_per_order` | **hard** |
| `advance_window_violated` | pickup date outside `[min_advance_days, max_advance_days]` | **hard** |
| `date_not_bookable` | closure / closed weekday | **hard** |
| `slot_not_available` | requested time outside configured windows | **hard** |
| `insufficient_availability` | §5.2 | **hard** |
| `on_site_only_multi_day` | cart contains a `loan_mode='on_site_only'` product and `pickup_date != due_date` | **hard** |
| `regulation_acceptance_required` | §5.5 | **hard** |

- A setting value of `null` means **infinite** → the corresponding check is skipped entirely.
- **hard** violations ⇒ `422`, order not created.
- **soft** violations ⇒ order IS created, `orders.exceeds_limits = true`, and `orders.limit_violations` stores the JSON array of violation objects. The frontend must warn *before* submitting (via `POST /api/v1/availability/check`) with copy like *"La durata richiesta supera il limite di X giorni: la richiesta potrebbe essere respinta."*
- If `booking.allow_exceeding_limits` is `false`, **soft becomes hard**.

Violation object shape (identical everywhere it appears):

```json
{
  "code": "max_loan_days_exceeded",
  "severity": "soft",
  "message": "La durata richiesta (12 giorni) supera il limite di 7 giorni.",
  "limit": 7,
  "actual": 12,
  "product_ids": []
}
```

## 5.5 Regulations

Three scopes:

- `global` — every user must accept the current version. Enforced at login: `GET /api/v1/auth/me` and the login response both return `pending_regulations`. The SPA blocks all routes except `/regolamento/accetta` until empty. Backend enforcement: **only** `POST /api/v1/orders` is hard-blocked (409) — read-only browsing stays allowed so the user can read the document.
- `category` — required when the cart contains ≥1 product in that category.
- `product` — required when the cart contains that product.

`regulations.version` is an integer starting at 1. `POST /regulations/{id}/publish` increments it and sets `published_at`; all previous acceptances become stale (an acceptance is valid only if `regulation_acceptances.version == regulations.version`). Editing a draft (`published_at IS NULL`) does not bump the version.

`requires_acceptance = false` ⇒ informational document, shown but never blocking.

## 5.6 Order numbering

`orders.code` — human-facing, unique, format `VL-{YYYY}-{NNNN}` where `NNNN` is a zero-padded per-year sequence starting at `0001`. Generated inside the creation transaction as `MAX(sequence)+1` for the year (column `orders.year_sequence` int). Draft orders do **not** consume a code (`code` is `NULL` while `status='draft'`); the code is assigned at submit.

## 5.7 Overdue detection

There is no cron requirement in v1. Overdue is computed **lazily**:

- Any read of an order list/detail runs `OrderService::refreshOverdue()` for the returned orders: if `status = 'picked_up'` and `now_lab_tz > due_date 23:59 + booking.overdue_grace_hours` → transition to `overdue` (writes an `order_events` row with `actor_id = NULL`, `actor_type='system'`).
- Similarly `approved` orders whose `pickup_date` is older than `booking.no_show_grace_hours` → `no_show`.
- `bin/console orders:refresh` performs the same sweep over all non-terminal orders, so a cron may be added later without code changes.

---

# 6. Database schema

Conventions: every table has `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` unless stated. `created_at`/`updated_at` are `DATETIME NULL` (Eloquent `$timestamps = true`). Soft-deleted tables carry `deleted_at DATETIME NULL` and use Eloquent `SoftDeletes`. FK columns are `BIGINT UNSIGNED`. `json` columns are `TEXT` on SQLite. All string lengths given are `VARCHAR(n)`.

Index naming: `idx_{table}_{cols}`, unique: `uniq_{table}_{cols}`, FK: `fk_{table}_{col}`.

## 6.1 `users`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| ldap_uid | string(191) | no | | **unique** — login identifier |
| email | string(191) | yes | null | |
| first_name | string(100) | yes | null | |
| last_name | string(100) | yes | null | |
| display_name | string(191) | yes | null | fallback: `first_name last_name`, else `ldap_uid` |
| role | string(32) | no | `'student'` | `student\|technician\|assistant\|admin` |
| role_locked | boolean | no | `false` | true ⇒ LDAP group mapping is ignored (local override) |
| role_source | string(32) | no | `'ldap'` | `ldap\|manual\|seed` (informational) |
| matricola | string(32) | yes | null | student number if LDAP provides it |
| course | string(191) | yes | null | degree course, from LDAP or free text |
| phone | string(32) | yes | null | user-editable |
| is_active | boolean | no | `true` | false ⇒ login refused |
| token_version | int | no | `1` | bump to invalidate JWTs |
| ldap_groups | json | yes | null | last seen groups, for debugging |
| last_login_at | datetime | yes | null | |
| notes | text | yes | null | staff-only note about the user |
| created_at / updated_at | datetime | yes | | |
| deleted_at | datetime | yes | null | soft delete |

Indexes: `uniq_users_ldap_uid (ldap_uid)`, `idx_users_role (role)`, `idx_users_email (email)`.

## 6.2 `refresh_tokens`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| user_id | fk → users.id | no | on delete cascade |
| token_hash | string(64) | no | **unique**, sha256 hex |
| family_id | string(36) | no | uuid; rotation chain |
| expires_at | datetime | no | |
| revoked_at | datetime | yes | |
| user_agent | string(255) | yes | |
| ip | string(45) | yes | |
| created_at / updated_at | datetime | yes | |

Indexes: `uniq_refresh_tokens_hash (token_hash)`, `idx_refresh_tokens_user (user_id)`, `idx_refresh_tokens_family (family_id)`.

## 6.3 `categories`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| slug | string(120) | no | | **unique**, kebab-case, e.g. `tecnologie-interattive` |
| name | string(191) | no | | Italian display name, e.g. `Tecnologie Interattive` |
| description | text | yes | null | markdown |
| icon | string(64) | yes | null | icon key for the UI (`camera`,`vr`,`light`,`audio`,`cable`,`support`,`hardware`,`electric`,`video`) |
| image_url | string(1024) | yes | null | |
| parent_id | fk → categories.id | yes | null | 1 level of nesting max; v1 seeds flat |
| position | int | no | `0` | manual ordering, ascending |
| is_active | boolean | no | `true` | |
| created_at / updated_at / deleted_at | datetime | yes | | |

Indexes: `uniq_categories_slug (slug)`, `idx_categories_parent (parent_id)`, `idx_categories_position (position)`.

## 6.4 `products`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| category_id | fk → categories.id | no | | restrict on delete |
| slug | string(191) | no | | **unique**, generated from name; stable |
| name | string(255) | no | | e.g. `Microfono Mezzo Fucile Rode NTG4` |
| brand | string(120) | yes | null | `Rode` |
| model | string(120) | yes | null | `NTG4` |
| description | text | yes | null | markdown |
| specs | json | yes | null | array of `{"label":"Peso","value":"320 g"}` |
| image_url | string(1024) | yes | null | primary image (denormalized from `product_images`) |
| status | string(32) | no | `'available'` | `available\|maintenance\|retired` — master switch |
| loan_mode | string(32) | no | `'takeaway'` | `takeaway\|on_site_only` (legacy "Solo in sede") |
| requires_training | boolean | no | `false` | shows a warning badge |
| min_loan_days | int | yes | null | per-product floor, overrides nothing else |
| max_loan_days | int | yes | null | per-product cap; `null` ⇒ use `booking.max_loan_days` |
| replacement_value_note | string(255) | yes | null | free text, e.g. "€ 1.200 ca." |
| source_notes | text | yes | null | provenance text copied from `catalog.json` |
| position | int | no | `0` | ordering within category |
| is_featured | boolean | no | `false` | homepage highlight |
| created_by / updated_by | fk → users.id | yes | null | |
| created_at / updated_at / deleted_at | datetime | yes | | |

Indexes: `uniq_products_slug (slug)`, `idx_products_category (category_id)`, `idx_products_status (status)`, `idx_products_name (name)`, `idx_products_brand (brand)`, `idx_products_featured (is_featured)`.

## 6.5 `product_images`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| product_id | fk → products.id | no | | cascade |
| url | string(1024) | no | | absolute URL (no local upload in v1) |
| alt | string(255) | yes | null | |
| position | int | no | `0` | 0 = primary |
| created_at / updated_at | datetime | yes | | |

Indexes: `idx_product_images_product (product_id, position)`.

> When `product_images` changes, the service re-syncs `products.image_url` to the `position = 0` row (or `NULL`).

## 6.6 `product_units`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| product_id | fk → products.id | no | | cascade |
| label | string(32) | no | | `01`, `02`, … unique per product |
| serial_number | string(120) | yes | null | |
| asset_code | string(120) | yes | null | *codice inventario* |
| purchase_date | date | yes | null | *data di acquisto* |
| inspection_date | date | yes | null | *data di collaudo* |
| next_inspection_date | date | yes | null | |
| status | string(32) | no | `'available'` | `available\|maintenance\|missing\|retired\|internal_use` |
| condition_note | string(255) | yes | null | short current-condition summary |
| location | string(120) | yes | null | shelf / cabinet |
| created_by / updated_by | fk → users.id | yes | null | |
| created_at / updated_at / deleted_at | datetime | yes | | |

Indexes: `uniq_product_units_product_label (product_id, label)`, `idx_product_units_status (status)`, `idx_product_units_serial (serial_number)`, `idx_product_units_asset (asset_code)`.

## 6.7 `recommended_products` (many-to-many, self-referencing)

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| product_id | fk → products.id | no | cascade |
| recommended_product_id | fk → products.id | no | cascade |
| relation | string(32) | no | `accessory\|alternative\|required_with` (default `accessory`) |
| position | int | no | default 0 |
| created_at / updated_at | datetime | yes | |

Indexes: `uniq_recommended_pair (product_id, recommended_product_id)`, `idx_recommended_product (product_id)`.
Constraint enforced in the service: `product_id != recommended_product_id` → `422 self_recommendation`. Relations are **directional** (A recommends B does not imply B recommends A); the UI may offer a "crea anche il collegamento inverso" checkbox which simply posts twice.

## 6.8 `product_logs`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| product_id | fk → products.id | no | | cascade |
| product_unit_id | fk → product_units.id | yes | null | null ⇒ applies to the whole product |
| order_id | fk → orders.id | yes | null | set when the log originates from a return inspection |
| user_id | fk → users.id | no | | author (staff) |
| type | string(32) | no | | `damage\|maintenance\|inspection\|note\|loss\|repair` |
| severity | string(32) | no | `'info'` | `info\|warning\|critical` |
| title | string(191) | no | | e.g. `Batteria danneggiata` |
| body | text | yes | null | markdown |
| occurred_at | datetime | no | | defaults to now; editable by staff |
| resolved_at | datetime | yes | null | set when the issue is closed |
| is_public | boolean | no | `true` | false ⇒ visible to staff only |
| created_at / updated_at / deleted_at | datetime | yes | | |

Indexes: `idx_product_logs_product (product_id, occurred_at)`, `idx_product_logs_unit (product_unit_id)`, `idx_product_logs_type (type)`, `idx_product_logs_order (order_id)`.

## 6.9 `orders`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| code | string(20) | yes | null | `VL-2026-0042`, unique when not null; assigned at submit |
| year_sequence | int | yes | null | per-year counter backing `code` |
| user_id | fk → users.id | no | | the student |
| status | string(32) | no | `'draft'` | see §8 |
| pickup_date | date | yes | null | required from `pending` onward |
| pickup_time | string(5) | yes | null | `"09:30"` — slot START |
| return_date | date | yes | null | requested return date = **due date** |
| return_time | string(5) | yes | null | slot START |
| picked_up_at | datetime | yes | null | actual |
| returned_at | datetime | yes | null | actual |
| subject | string(191) | yes | null | *materia / corso* — required at submit |
| motivation | text | yes | null | required at submit if `booking.require_motivation` |
| professor | string(191) | yes | null | optional (or required per setting) |
| notes | text | yes | null | free notes **from the student** |
| staff_notes | text | yes | null | internal notes, never returned to students |
| rejection_reason | text | yes | null | shown to the student |
| exceeds_limits | boolean | no | `false` | §5.4 soft violations present |
| limit_violations | json | yes | null | array of violation objects (§5.4) |
| items_count | int | no | `0` | denormalized: SUM(order_items.quantity) |
| decided_by | fk → users.id | yes | null | who approved/rejected |
| decided_at | datetime | yes | null | |
| handed_over_by | fk → users.id | yes | null | who marked picked_up |
| received_by | fk → users.id | yes | null | who marked returned |
| cancelled_by | fk → users.id | yes | null | |
| cancelled_at | datetime | yes | null | |
| late_days | int | yes | null | computed on return: `max(0, returned_date - due_date)` |
| submitted_at | datetime | yes | null | when it left `draft` |
| created_at / updated_at / deleted_at | datetime | yes | | |

Indexes: `uniq_orders_code (code)`, `idx_orders_user_status (user_id, status)`, `idx_orders_status (status)`, `idx_orders_pickup (pickup_date)`, `idx_orders_return (return_date)`, `idx_orders_submitted (submitted_at)`.

**Cart = the single `draft` order per user.** Enforced by a partial-ish rule in the service (SQLite/MySQL cannot express a partial unique index portably): `OrderService::cart(User)` uses `firstOrCreate(['user_id'=>id,'status'=>'draft'])`. Drafts older than `booking.cart_ttl_hours` are purged by `bin/console carts:prune` (and opportunistically on cart read).

## 6.10 `order_items`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| order_id | fk → orders.id | no | | cascade |
| product_id | fk → products.id | no | | restrict |
| quantity | int | no | `1` | `>= 1` |
| product_name_snapshot | string(255) | yes | null | frozen at submit, for history integrity |
| product_brand_snapshot | string(120) | yes | null | |
| notes | string(255) | yes | null | per-item student note |
| returned_quantity | int | no | `0` | filled at return; `< quantity` ⇒ partial return |
| created_at / updated_at | datetime | yes | | |

Indexes: `uniq_order_items_order_product (order_id, product_id)`, `idx_order_items_product (product_id)`.
The unique constraint means adding an existing product to the cart **increments quantity** rather than creating a second row.

## 6.11 `order_item_units`

Assignment of concrete units, written at pickup.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| order_item_id | fk → order_items.id | no | cascade |
| product_unit_id | fk → product_units.id | no | restrict |
| assigned_at | datetime | no | |
| returned_at | datetime | yes | |
| condition_out | string(32) | yes | `ok\|damaged\|incomplete` |
| condition_in | string(32) | yes | `ok\|damaged\|incomplete\|missing` |
| note | string(255) | yes | |
| created_at / updated_at | datetime | yes | |

Indexes: `uniq_order_item_units (order_item_id, product_unit_id)`, `idx_order_item_units_unit (product_unit_id)`.

## 6.12 `order_events` (state-machine audit trail)

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| order_id | fk → orders.id | no | cascade |
| from_status | string(32) | yes | null on creation |
| to_status | string(32) | no | |
| action | string(64) | no | `submit\|approve\|reject\|cancel\|pickup\|return\|mark_no_show\|mark_overdue\|reopen\|note` |
| actor_id | fk → users.id | yes | null ⇒ system |
| actor_type | string(16) | no | `user\|system` |
| actor_role | string(32) | yes | role at the time |
| comment | text | yes | |
| meta | json | yes | e.g. `{"late_days":2}` |
| created_at / updated_at | datetime | yes | |

Indexes: `idx_order_events_order (order_id, created_at)`.

## 6.13 `settings`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| key | string(120) | no | | **unique**, dotted, e.g. `booking.max_loan_days` |
| value | text | yes | null | **always JSON-encoded** (`"7"`, `"null"`, `"\"Visionary Lab\""`, `"[…]"`) |
| type | string(24) | no | | `string\|int\|bool\|json\|time\|date\|enum\|secret` |
| group | string(48) | no | | `lab\|hours\|booking\|regulations\|ldap\|security\|notifications\|ui\|stats` |
| label_it | string(191) | no | | UI label |
| description_it | string(500) | yes | null | UI help text |
| is_public | boolean | no | `false` | true ⇒ exposed by `GET /settings/public` without auth |
| is_secret | boolean | no | `false` | true ⇒ value redacted as `"********"` in every GET |
| nullable | boolean | no | `false` | true ⇒ `null` is a legal value meaning "infinite/unset" |
| options | json | yes | null | for `enum`: allowed values |
| position | int | no | `0` | ordering inside the group |
| updated_by | fk → users.id | yes | null | |
| created_at / updated_at | datetime | yes | | |

Indexes: `uniq_settings_key (key)`, `idx_settings_group (group, position)`.

**Always JSON-encode `value`.** This removes every ambiguity about `"0"` vs `0` vs `false` vs `null`. `SettingsRepository` decodes and casts per `type`, caches the whole table in a static array per request, and exposes `get(string $key, mixed $default = null)`, `all()`, `set(string $key, mixed $value, ?int $userId)`.

Unknown keys cannot be created via the API — `PUT /settings` rejects keys absent from the table with `422 unknown_setting_key`. The canonical key list lives in `SettingsSeeder` and is idempotently re-applied on every seed run (existing values preserved, new keys added, obsolete keys left untouched).

## 6.14 `closures`

Holiday / *ferie* / maintenance windows.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| title | string(191) | no | | `Chiusura estiva` |
| description | text | yes | null | |
| start_date | date | no | | inclusive |
| end_date | date | no | | inclusive; `>= start_date` |
| blocks_pickup | boolean | no | `true` | |
| blocks_return | boolean | no | `true` | |
| is_recurring_yearly | boolean | no | `false` | if true, month/day are matched every year |
| created_by | fk → users.id | yes | null | |
| created_at / updated_at / deleted_at | datetime | yes | | |

Indexes: `idx_closures_range (start_date, end_date)`.

## 6.15 `regulations`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint pk | no | | |
| slug | string(120) | no | | **unique** |
| title | string(191) | no | | |
| summary | string(500) | yes | null | shown in the acceptance modal header |
| scope | string(32) | no | `'global'` | `global\|category\|product` |
| content_type | string(16) | no | `'markdown'` | `markdown\|pdf` |
| body | longtext | yes | null | markdown source (when `content_type='markdown'`) |
| file_path | string(255) | yes | null | relative to `storage/` (when `content_type='pdf'`) |
| file_name | string(255) | yes | null | original filename for `Content-Disposition` |
| file_size | int | yes | null | bytes |
| file_mime | string(100) | yes | null | must be `application/pdf` |
| version | int | no | `1` | bumped by publish |
| requires_acceptance | boolean | no | `true` | |
| is_active | boolean | no | `true` | inactive ⇒ never required, hidden from students |
| published_at | datetime | yes | null | null ⇒ draft, not enforced, staff-only visible |
| position | int | no | `0` | |
| created_by / updated_by | fk → users.id | yes | null | |
| created_at / updated_at / deleted_at | datetime | yes | | |

Indexes: `uniq_regulations_slug (slug)`, `idx_regulations_scope (scope, is_active)`.

## 6.16 `regulation_targets`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| regulation_id | fk → regulations.id | no | cascade |
| target_type | string(16) | no | `category\|product` |
| target_id | bigint unsigned | no | id in the corresponding table (no FK — polymorphic) |
| created_at / updated_at | datetime | yes | |

Indexes: `uniq_regulation_targets (regulation_id, target_type, target_id)`, `idx_regulation_targets_lookup (target_type, target_id)`.
Rows exist only when `regulations.scope != 'global'`. Integrity is enforced by the service on delete of a product/category.

## 6.17 `regulation_acceptances`

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| regulation_id | fk → regulations.id | no | cascade |
| user_id | fk → users.id | no | cascade |
| version | int | no | version accepted |
| order_id | fk → orders.id | yes | set when accepted during checkout |
| accepted_at | datetime | no | |
| ip | string(45) | yes | |
| user_agent | string(255) | yes | |
| created_at / updated_at | datetime | yes | |

Indexes: `uniq_regulation_acceptances (regulation_id, user_id, version)`, `idx_regulation_acceptances_user (user_id)`.

## 6.18 `audit_logs`

Generic write-audit for staff/admin actions (products, settings, users, regulations, closures).

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| user_id | fk → users.id | yes | null ⇒ system |
| action | string(64) | no | `product.create`, `settings.update`, `user.role_change`, … |
| entity_type | string(64) | yes | `Product`, `Setting`, … |
| entity_id | string(64) | yes | id or setting key |
| changes | json | yes | `{"before":{…},"after":{…}}`; secrets redacted |
| ip | string(45) | yes | |
| user_agent | string(255) | yes | |
| created_at / updated_at | datetime | yes | |

Indexes: `idx_audit_logs_entity (entity_type, entity_id)`, `idx_audit_logs_user (user_id, created_at)`, `idx_audit_logs_action (action)`.

## 6.19 `fake_ldap_users` (dev/test only)

Never populated in production; the table still exists so the schema is uniform.

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| username | string(191) | no | **unique** |
| password_hash | string(255) | no | `password_hash(..., PASSWORD_DEFAULT)` |
| email | string(191) | yes | |
| first_name | string(100) | yes | |
| last_name | string(100) | yes | |
| display_name | string(191) | yes | |
| groups | json | yes | array of group DNs |
| is_active | boolean | no | default true |
| created_at / updated_at | datetime | yes | |

## 6.20 `migrations`

`id`, `migration string(191) unique`, `batch int`, `ran_at datetime`.

## 6.21 Entity relationship summary

```
users 1─* orders 1─* order_items 1─* order_item_units *─1 product_units
users 1─* refresh_tokens                                     │
users 1─* regulation_acceptances *─1 regulations             │
users 1─* product_logs                                       │
categories 1─* products 1─* product_units ───────────────────┘
products 1─* product_images
products *─* products  (recommended_products)
products *─* products  (product_substitutes, directional)
products 1─* product_logs
regulations 1─* regulation_targets ─(poly)─ categories | products
orders 1─* order_events
```

## 6.22 `product_substitutes` (many-to-many, self-referencing, directional)

| Column | Type | Null | Notes |
|---|---|---|---|
| id | bigint pk | no | |
| product_id | fk → products.id | no | cascade — the product being replaced |
| substitute_product_id | fk → products.id | no | cascade — the product offered instead |
| priority | int | no | default 0; lower sorts first |
| created_at / updated_at | datetime | yes | |

Indexes: `uniq_substitute_pair (product_id, substitute_product_id)`, `idx_substitute_product (product_id)`.
Constraint enforced in the service: `product_id != substitute_product_id` → `422 self_substitution`. Relations are **directional** (X can be replaced by Y does not imply Y can be replaced by X) and are **never traversed recursively**: only a product's DIRECT substitutes are ever considered when building suggestions (§7.8 #32, §7.9 #88).

---

# 7. REST API contract

**Base URL:** `/api/v1`. Dev: `http://localhost:8081/api/v1`, reached from the SPA as `/api/v1` through the Vite proxy.

## 7.1 Global conventions

- **Auth header:** `Authorization: Bearer <access_token>`.
- **Content type:** requests `application/json` (except regulation PDF upload = `multipart/form-data`). Responses always JSON except `GET /regulations/{id}/file` (PDF stream) and `GET /stats/export` (CSV).
- **Unknown request fields are ignored**, never an error.
- **`null` vs omitted:** on `PUT`/`PATCH`, an omitted key means "leave unchanged"; an explicit `null` means "clear the value". On `POST` an omitted optional key means "use the default".
- **Trailing slashes:** not accepted. Use exactly the paths listed.
- **Method override:** not supported.
- **IDs are integers** in JSON (not strings) everywhere except the JWT `sub`.

## 7.2 Envelopes

**Single resource** — the object at the top level:

```json
{ "id": 12, "name": "…" }
```

**Collection** — always wrapped:

```json
{
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": {
    "page": 1,
    "per_page": 24,
    "total": 137,
    "total_pages": 6
  }
}
```

Non-paginated collections (e.g. categories, settings) still use `{"data": [...]}` but with `"meta": null`.

**Pagination query params:** `page` (int ≥1, default 1), `per_page` (int 1..100, default from setting `ui.items_per_page`, i.e. 24). Out-of-range `per_page` is clamped, not rejected.

**Sorting:** `sort=<field>` and `order=asc|desc`. Allowed sort fields are listed per endpoint; an unknown field → `422 invalid_sort`.

**Created resources** return `201` with the full resource body and a `Location` header.
**Deletes** return `204` with an empty body.

## 7.3 Error format (identical for every error)

```json
{
  "error": {
    "code": "validation_failed",
    "message": "I dati inviati non sono validi.",
    "details": {
      "pickup_date": ["Il campo pickup_date è obbligatorio."],
      "items": ["Il carrello è vuoto."]
    },
    "trace_id": "b3f9c2a1"
  }
}
```

- `code` — stable machine-readable snake_case string (frontend switches on this).
- `message` — Italian, safe to show to the user.
- `details` — object of `field -> string[]`, or `null`. For non-validation errors it may carry domain context (e.g. `{"product_ids":[4,9]}`).
- `trace_id` — 8 hex chars, also written to the log line.
- In `APP_DEBUG=true` an extra `debug` object with `exception`, `file`, `line`, `trace` is appended. Never in production.

**Status code → code mapping (binding):**

| HTTP | `code` values |
|---|---|
| 400 | `invalid_json`, `bad_request` |
| 401 | `unauthenticated`, `invalid_credentials`, `token_expired`, `token_invalid`, `token_stale`, `refresh_invalid`, `refresh_expired`, `refresh_reused` |
| 403 | `forbidden`, `account_disabled`, `role_required` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict`, `insufficient_availability`, `invalid_transition`, `regulation_acceptance_required`, `unit_in_use`, `duplicate_slug`, `category_not_empty` |
| 413 | `payload_too_large` |
| 415 | `unsupported_media_type` |
| 422 | `validation_failed`, `limit_violation`, `date_not_bookable`, `slot_not_available`, `invalid_sort`, `unknown_setting_key`, `self_recommendation` |
| 429 | `too_many_attempts` |
| 500 | `server_error` |
| 503 | `ldap_unavailable` |

## 7.4 Canonical resource representations

These objects are referenced by name throughout §7.5+. **Field names are frozen.**

### `User`

```json
{
  "id": 3,
  "ldap_uid": "student1",
  "email": "student1@studenti.polito.it",
  "first_name": "Marco",
  "last_name": "Rossi",
  "display_name": "Marco Rossi",
  "role": "student",
  "role_label": "Studente",
  "role_locked": false,
  "matricola": "s123456",
  "course": "Ingegneria del Cinema e dei Mezzi di Comunicazione",
  "phone": null,
  "is_active": true,
  "last_login_at": "2026-07-31T08:12:03Z",
  "created_at": "2026-02-01T09:00:00Z"
}
```
`notes` is added **only** for staff/admin readers. Students reading `/auth/me` get the object above minus `role_locked`.

### `Category`

```json
{
  "id": 7,
  "slug": "tecnologie-interattive",
  "name": "Tecnologie Interattive",
  "description": "Visori VR, sensori, schede Arduino.",
  "icon": "vr",
  "image_url": null,
  "parent_id": null,
  "position": 70,
  "is_active": true,
  "products_count": 34
}
```
`products_count` counts non-deleted products with `status != 'retired'`. Present on list and detail.

### `ProductSummary` (catalog grid / search results / cart)

```json
{
  "id": 128,
  "slug": "visore-vr-meta-quest-3",
  "name": "Visore VR Meta Quest 3 128GB",
  "brand": "Meta",
  "model": "Quest 3",
  "category": { "id": 7, "slug": "tecnologie-interattive", "name": "Tecnologie Interattive" },
  "image_url": "https://prestitimultimedia.polito.it/foto/Meta_Quest3.jpg",
  "status": "available",
  "loan_mode": "takeaway",
  "requires_training": true,
  "units_total": 6,
  "units_available": 5,
  "has_required_regulations": true,
  "is_featured": false
}
```

`units_available` = units currently in status `available` (NOT range-aware).
`has_required_regulations` = true when at least one active, published, `requires_acceptance` regulation targets this product or its category.

### `Product` (detail) — `ProductSummary` **plus**:

```json
{
  "description": "Visore standalone…",
  "specs": [ { "label": "Risoluzione", "value": "2064x2208 per occhio" } ],
  "images": [
    { "id": 41, "url": "https://…/a.jpg", "alt": "Vista frontale", "position": 0 }
  ],
  "min_loan_days": null,
  "max_loan_days": 3,
  "replacement_value_note": "€ 550 ca.",
  "source_notes": "Catalogo DAUIN 2024",
  "position": 10,
  "recommended_products": [
    { "relation": "accessory", "position": 0, "product": { "…ProductSummary…" } }
  ],
  "substitutes": [
    { "priority": 1, "product": { "…ProductSummary…" } }
  ],
  "regulations": [
    { "id": 4, "slug": "avvertenze-vr", "title": "Avvertenze uso visori VR",
      "scope": "category", "version": 2, "requires_acceptance": true }
  ],
  "units": [ "…ProductUnit…" ],
  "recent_logs": [ "…ProductLog…" ],
  "created_at": "2026-01-10T10:00:00Z",
  "updated_at": "2026-07-02T14:31:00Z"
}
```

Visibility rules for `Product` detail:
- **Students / anonymous:** `units` is **omitted entirely**, unless setting `ui.show_unit_codes_to_students` is true, in which case a reduced unit object `{id,label,status}` is returned. `recent_logs` contains only `is_public = true` entries and omits `user`.
- **Staff:** full `units` and all logs including `is_public = false`.
- `substitutes` is ordered by `priority ASC`; a substitute whose product `status = 'retired'` is dropped from the list for non-staff readers (staff still see it).

### `ProductUnit`

```json
{
  "id": 512,
  "product_id": 128,
  "label": "03",
  "serial_number": "QST3-9981223",
  "asset_code": "INV-2024-00417",
  "purchase_date": "2024-03-15",
  "inspection_date": "2026-01-20",
  "next_inspection_date": "2027-01-20",
  "status": "available",
  "status_label": "Prestabile",
  "condition_note": null,
  "location": "Armadio B / ripiano 2",
  "current_order": { "id": 88, "code": "VL-2026-0088", "return_date": "2026-08-04" },
  "created_at": "2024-03-16T08:00:00Z"
}
```
`current_order` is `null` unless the unit is assigned to an order in `picked_up`/`overdue`.

### `ProductLog`

```json
{
  "id": 900,
  "product_id": 128,
  "product_unit_id": 512,
  "unit_label": "03",
  "order_id": null,
  "order_code": null,
  "type": "damage",
  "type_label": "Danno",
  "severity": "warning",
  "title": "Molla del cinturino persa",
  "body": "Manca la molla di regolazione destra.",
  "occurred_at": "2026-06-12T15:40:00Z",
  "resolved_at": null,
  "is_public": true,
  "user": { "id": 5, "display_name": "Luca Ferrero", "role": "technician" },
  "created_at": "2026-06-12T15:41:11Z"
}
```

### `OrderItem`

```json
{
  "id": 771,
  "product_id": 128,
  "quantity": 1,
  "notes": null,
  "returned_quantity": 0,
  "product": { "…ProductSummary…" },
  "product_name_snapshot": "Visore VR Meta Quest 3 128GB",
  "product_brand_snapshot": "Meta",
  "assigned_units": [
    { "id": 21, "product_unit_id": 512, "unit_label": "03",
      "assigned_at": "2026-08-01T09:35:00Z", "returned_at": null,
      "condition_out": "ok", "condition_in": null, "note": null }
  ]
}
```
`assigned_units` is `[]` before pickup, and is **omitted for students** (staff-only field) — students see only `quantity`.

### `OrderSummary` (lists)

```json
{
  "id": 88,
  "code": "VL-2026-0088",
  "status": "approved",
  "status_label": "Approvato",
  "user": { "id": 3, "display_name": "Marco Rossi", "ldap_uid": "student1" },
  "pickup_date": "2026-08-01",
  "pickup_time": "09:30",
  "return_date": "2026-08-04",
  "return_time": "16:00",
  "items_count": 3,
  "distinct_products": 2,
  "exceeds_limits": false,
  "is_late": false,
  "late_days": null,
  "submitted_at": "2026-07-25T11:02:00Z",
  "created_at": "2026-07-25T10:58:00Z"
}
```

### `Order` (detail) — `OrderSummary` **plus**:

```json
{
  "subject": "Laboratorio di Ripresa e Montaggio",
  "motivation": "Riprese del cortometraggio d'esame.",
  "professor": "Prof.ssa Rossi",
  "notes": "Ritiro previsto in mattinata.",
  "staff_notes": "Studente affidabile.",
  "rejection_reason": null,
  "limit_violations": [],
  "picked_up_at": null,
  "returned_at": null,
  "decided_by": { "id": 5, "display_name": "Luca Ferrero" },
  "decided_at": "2026-07-26T09:14:00Z",
  "handed_over_by": null,
  "received_by": null,
  "cancelled_at": null,
  "items": [ "…OrderItem…" ],
  "events": [ "…OrderEvent…" ],
  "required_regulations": [
    { "id": 4, "slug": "avvertenze-vr", "title": "Avvertenze uso visori VR",
      "version": 2, "accepted": true, "accepted_at": "2026-07-25T11:01:55Z" }
  ],
  "allowed_actions": ["cancel"],
  "updated_at": "2026-07-26T09:14:00Z"
}
```

- `staff_notes` is **omitted** when the reader is a student.
- `allowed_actions` is computed **for the current reader** from the state machine (§8) — it is the single source of truth for which buttons the SPA renders. Values are the `action` names of §8: `submit`, `approve`, `reject`, `cancel`, `pickup`, `return`, `mark_no_show`, `reopen`.

### `OrderEvent`

```json
{
  "id": 310,
  "from_status": "pending",
  "to_status": "approved",
  "action": "approve",
  "action_label": "Approvato",
  "actor": { "id": 5, "display_name": "Luca Ferrero", "role": "technician" },
  "actor_type": "user",
  "comment": "Confermato, ritiro alle 9:30.",
  "meta": null,
  "created_at": "2026-07-26T09:14:00Z"
}
```
`actor` is `null` when `actor_type = "system"`.

### `Setting`

```json
{
  "key": "booking.max_loan_days",
  "value": 7,
  "type": "int",
  "group": "booking",
  "label_it": "Durata massima del prestito (giorni)",
  "description_it": "Numero massimo di giorni consecutivi per un prestito standard.",
  "is_public": true,
  "is_secret": false,
  "nullable": false,
  "options": null,
  "position": 10,
  "updated_at": "2026-07-01T12:00:00Z"
}
```
`value` is the **decoded, typed** value (real JSON number/bool/null/array/object/string). Secrets are returned as the string `"********"` and `is_secret: true`.

### `Closure`

```json
{
  "id": 2,
  "title": "Chiusura estiva",
  "description": "Il laboratorio resta chiuso.",
  "start_date": "2026-08-08",
  "end_date": "2026-08-23",
  "blocks_pickup": true,
  "blocks_return": true,
  "is_recurring_yearly": false,
  "created_at": "2026-05-02T10:00:00Z"
}
```

### `Regulation`

```json
{
  "id": 4,
  "slug": "avvertenze-vr",
  "title": "Avvertenze uso visori VR",
  "summary": "Rischi fotosensibilità ed epilessia.",
  "scope": "category",
  "content_type": "markdown",
  "body": "# Avvertenze\n…",
  "file_url": null,
  "file_name": null,
  "file_size": null,
  "version": 2,
  "requires_acceptance": true,
  "is_active": true,
  "published_at": "2026-03-01T10:00:00Z",
  "position": 20,
  "targets": [ { "target_type": "category", "target_id": 7, "target_name": "Tecnologie Interattive" } ],
  "acceptance": { "accepted": true, "version": 2, "accepted_at": "2026-07-25T11:01:55Z" },
  "acceptances_count": 214,
  "created_at": "2026-02-20T09:00:00Z",
  "updated_at": "2026-03-01T10:00:00Z"
}
```
- `body` is omitted from **list** responses (only present on detail) to keep payloads small.
- `file_url` is `"/api/v1/regulations/4/file"` when `content_type = "pdf"`, else `null`.
- `acceptance` describes the **current reader**; `null` when unauthenticated.
- `acceptances_count` is staff-only.

## 7.5 Endpoint index

Legend for the **Auth** column: `–` public, `A` any authenticated user, `S` student+, `T` technician, `B` assistant (borsista), `AD` admin. A list like `T/B/AD` means any of those roles.

| # | Method | Path | Auth |
|---|---|---|---|
| 1 | GET | `/api/v1/health` | – |
| 2 | GET | `/api/v1/meta/enums` | – |
| 3 | POST | `/api/v1/auth/login` | – |
| 4 | POST | `/api/v1/auth/refresh` | – |
| 5 | POST | `/api/v1/auth/logout` | A |
| 6 | GET | `/api/v1/auth/me` | A |
| 7 | PATCH | `/api/v1/auth/me` | A |
| 8 | GET | `/api/v1/settings/public` | – |
| 9 | GET | `/api/v1/categories` | – |
| 10 | GET | `/api/v1/categories/{idOrSlug}` | – |
| 11 | POST | `/api/v1/categories` | T/AD |
| 12 | PUT | `/api/v1/categories/{id}` | T/AD |
| 13 | DELETE | `/api/v1/categories/{id}` | T/AD |
| 14 | GET | `/api/v1/products` | – |
| 15 | GET | `/api/v1/products/{idOrSlug}` | – |
| 16 | POST | `/api/v1/products` | T/AD |
| 17 | PUT | `/api/v1/products/{id}` | T/AD |
| 18 | DELETE | `/api/v1/products/{id}` | T/AD |
| 19 | GET | `/api/v1/products/{id}/units` | T/B/AD |
| 20 | POST | `/api/v1/products/{id}/units` | T/AD |
| 21 | PUT | `/api/v1/units/{unitId}` | T/AD |
| 22 | DELETE | `/api/v1/units/{unitId}` | T/AD |
| 23 | GET | `/api/v1/products/{id}/logs` | – (filtered) |
| 24 | POST | `/api/v1/products/{id}/logs` | T/B/AD |
| 25 | PUT | `/api/v1/logs/{logId}` | T/B/AD (own or T/AD) |
| 26 | DELETE | `/api/v1/logs/{logId}` | T/AD |
| 27 | PUT | `/api/v1/products/{id}/recommended` | T/AD |
| 28 | GET | `/api/v1/products/{id}/availability` | – |
| 29 | GET | `/api/v1/brands` | – |
| 30 | GET | `/api/v1/availability/products` | – |
| 31 | POST | `/api/v1/availability/dates` | – |
| 32 | POST | `/api/v1/availability/check` | A |
| 33 | GET | `/api/v1/calendar/opening` | – |
| 34 | GET | `/api/v1/cart` | S |
| 35 | POST | `/api/v1/cart/items` | S |
| 36 | PATCH | `/api/v1/cart/items/{itemId}` | S |
| 37 | DELETE | `/api/v1/cart/items/{itemId}` | S |
| 38 | PUT | `/api/v1/cart/dates` | S |
| 39 | DELETE | `/api/v1/cart` | S |
| 40 | POST | `/api/v1/orders` | S |
| 41 | GET | `/api/v1/orders` | A |
| 42 | GET | `/api/v1/orders/{id}` | A (own) / T/B/AD (any) |
| 43 | PUT | `/api/v1/orders/{id}` | T/B/AD |
| 44 | POST | `/api/v1/orders/{id}/approve` | T/B/AD |
| 45 | POST | `/api/v1/orders/{id}/reject` | T/B/AD |
| 46 | POST | `/api/v1/orders/{id}/cancel` | owner S, or T/B/AD |
| 47 | POST | `/api/v1/orders/{id}/pickup` | T/B/AD |
| 48 | POST | `/api/v1/orders/{id}/return` | T/B/AD |
| 49 | POST | `/api/v1/orders/{id}/no-show` | T/B/AD |
| 50 | POST | `/api/v1/orders/{id}/reopen` | AD |
| 51 | POST | `/api/v1/orders/{id}/notes` | T/B/AD |
| 52 | GET | `/api/v1/orders/{id}/events` | A (own) / T/B/AD |
| 53 | GET | `/api/v1/orders/calendar` | T/B/AD |
| 54 | GET | `/api/v1/regulations` | – |
| 55 | GET | `/api/v1/regulations/{idOrSlug}` | – |
| 56 | GET | `/api/v1/regulations/{id}/file` | – |
| 57 | POST | `/api/v1/regulations` | T/AD |
| 58 | PUT | `/api/v1/regulations/{id}` | T/AD |
| 59 | POST | `/api/v1/regulations/{id}/file` | T/AD |
| 60 | POST | `/api/v1/regulations/{id}/publish` | T/AD |
| 61 | DELETE | `/api/v1/regulations/{id}` | AD |
| 62 | GET | `/api/v1/regulations/{id}/acceptances` | T/B/AD |
| 63 | GET | `/api/v1/me/regulations/pending` | A |
| 64 | POST | `/api/v1/me/regulations/{id}/accept` | A |
| 65 | GET | `/api/v1/settings` | T/B/AD (read) |
| 66 | PUT | `/api/v1/settings` | AD |
| 67 | PUT | `/api/v1/settings/{key}` | AD |
| 68 | POST | `/api/v1/settings/ldap/test` | AD |
| 69 | GET | `/api/v1/closures` | – |
| 70 | POST | `/api/v1/closures` | T/AD |
| 71 | PUT | `/api/v1/closures/{id}` | T/AD |
| 72 | DELETE | `/api/v1/closures/{id}` | T/AD |
| 73 | GET | `/api/v1/users` | T/B/AD |
| 74 | GET | `/api/v1/users/{id}` | T/B/AD |
| 75 | PUT | `/api/v1/users/{id}` | AD |
| 76 | GET | `/api/v1/users/{id}/orders` | T/B/AD |
| 77 | GET | `/api/v1/stats/overview` | T/B/AD |
| 78 | GET | `/api/v1/stats/loans-over-time` | T/AD |
| 79 | GET | `/api/v1/stats/top-products` | T/AD |
| 80 | GET | `/api/v1/stats/by-category` | T/AD |
| 81 | GET | `/api/v1/stats/late-returns` | T/B/AD |
| 82 | GET | `/api/v1/stats/utilization` | T/AD |
| 83 | GET | `/api/v1/stats/my-activity` | T/B/AD |
| 84 | GET | `/api/v1/stats/export` | T/AD |
| 85 | GET | `/api/v1/audit-logs` | AD |
| 86 | GET | `/api/v1/logs` | T/B/AD |
| 87 | PUT | `/api/v1/products/{id}/substitutes` | T/AD |
| 88 | POST | `/api/v1/cart/items/{itemId}/swap` | S |
| 89 | GET | `/api/v1/orders/{id}/pdf` | A (own) / T/B/AD (any) |

## 7.6 System & auth endpoints

### 1. `GET /api/v1/health`

Response `200`:
```json
{
  "status": "ok",
  "app": "vlab",
  "version": "1.0.0",
  "environment": "local",
  "database": { "driver": "sqlite", "connected": true, "migrations_applied": 20 },
  "ldap_mode": "fake",
  "server_time": "2026-07-31T09:00:00Z",
  "timezone": "Europe/Rome"
}
```
If the DB is unreachable → `503` with `code: "server_error"` and the same body shape under `error.details`.

### 2. `GET /api/v1/meta/enums`

Returns every enumeration with Italian labels, so the SPA never hardcodes them.

```json
{
  "order_status": [
    { "value": "draft", "label": "Bozza", "is_terminal": false, "locks_stock": false },
    { "value": "pending", "label": "In attesa", "is_terminal": false, "locks_stock": true },
    { "value": "approved", "label": "Approvato", "is_terminal": false, "locks_stock": true },
    { "value": "rejected", "label": "Respinto", "is_terminal": true, "locks_stock": false },
    { "value": "cancelled", "label": "Annullato", "is_terminal": true, "locks_stock": false },
    { "value": "picked_up", "label": "Ritirato", "is_terminal": false, "locks_stock": true },
    { "value": "overdue", "label": "In ritardo", "is_terminal": false, "locks_stock": true },
    { "value": "returned", "label": "Restituito", "is_terminal": true, "locks_stock": false },
    { "value": "returned_late", "label": "Restituito in ritardo", "is_terminal": true, "locks_stock": false },
    { "value": "no_show", "label": "Non ritirato", "is_terminal": true, "locks_stock": false }
  ],
  "product_status": [ { "value": "available", "label": "Disponibile" }, { "value": "maintenance", "label": "In manutenzione" }, { "value": "retired", "label": "Dismesso" } ],
  "unit_status": [ { "value": "available", "label": "Prestabile" }, { "value": "maintenance", "label": "In manutenzione" }, { "value": "missing", "label": "Mancante" }, { "value": "retired", "label": "Dismesso" }, { "value": "internal_use", "label": "In uso interno" } ],
  "loan_mode": [ { "value": "takeaway", "label": "Asportabile" }, { "value": "on_site_only", "label": "Solo in sede" } ],
  "log_type": [ { "value": "damage", "label": "Danno" }, { "value": "maintenance", "label": "Manutenzione" }, { "value": "inspection", "label": "Collaudo" }, { "value": "note", "label": "Nota" }, { "value": "loss", "label": "Smarrimento" }, { "value": "repair", "label": "Riparazione" } ],
  "log_severity": [ { "value": "info", "label": "Informazione" }, { "value": "warning", "label": "Attenzione" }, { "value": "critical", "label": "Critico" } ],
  "role": [ { "value": "student", "label": "Studente" }, { "value": "technician", "label": "Tecnico" }, { "value": "assistant", "label": "Borsista" }, { "value": "admin", "label": "Amministratore" } ],
  "regulation_scope": [ { "value": "global", "label": "Globale" }, { "value": "category", "label": "Categoria" }, { "value": "product", "label": "Prodotto" } ],
  "recommendation_relation": [ { "value": "accessory", "label": "Accessorio" }, { "value": "alternative", "label": "Alternativa" }, { "value": "required_with", "label": "Necessario insieme" } ],
  "condition": [ { "value": "ok", "label": "Integro" }, { "value": "damaged", "label": "Danneggiato" }, { "value": "incomplete", "label": "Incompleto" }, { "value": "missing", "label": "Mancante" } ]
}
```

### 3. `POST /api/v1/auth/login`

Request:
```json
{ "username": "student1", "password": "password" }
```
Both required, `username` 1..191 chars, `password` 1..255.

Response `200`:
```json
{
  "access_token": "eyJhbGciOi…",
  "token_type": "Bearer",
  "expires_in": 28800,
  "expires_at": "2026-07-31T17:00:00Z",
  "refresh_token": "9f2c…64hex",
  "refresh_expires_at": "2026-08-14T09:00:00Z",
  "user": { "…User…" },
  "pending_regulations": [
    { "id": 1, "slug": "regolamento-generale", "title": "Regolamento generale del laboratorio",
      "version": 3, "scope": "global", "content_type": "markdown" }
  ]
}
```
`pending_regulations` lists **global**, active, published, `requires_acceptance` regulations the user has not accepted at the current version. Empty array when nothing is pending.

Errors: `401 invalid_credentials` (wrong user or password — never distinguish the two), `403 account_disabled`, `429 too_many_attempts`, `503 ldap_unavailable`.

### 4. `POST /api/v1/auth/refresh`

Request `{ "refresh_token": "9f2c…" }`. Response identical to login (new access **and** new refresh token; the old refresh token is revoked). Errors: `401 refresh_invalid|refresh_expired|refresh_reused`.

### 5. `POST /api/v1/auth/logout`

Request `{ "refresh_token": "9f2c…" }` (optional; if omitted, all refresh tokens of the user are revoked). Response `204`.

### 6. `GET /api/v1/auth/me`

Response `200`:
```json
{
  "user": { "…User…" },
  "permissions": {
    "products.manage": false,
    "orders.manage": false,
    "orders.create": true,
    "logs.create": false,
    "settings.manage": false,
    "stats.view_full": false,
    "stats.view_limited": false,
    "users.manage": false,
    "regulations.manage": false,
    "closures.manage": false,
    "audit.view": false
  },
  "pending_regulations": [ … ],
  "cart_items_count": 2,
  "active_orders_count": 1
}
```
The `permissions` object is the **exact** key set of §9. The frontend must gate UI on these booleans and never on `role` string comparisons.

### 7. `PATCH /api/v1/auth/me`

Request (all optional): `{ "phone": "3401234567", "course": "ICMC" }`. Only `phone` and `course` are self-editable. Response: `{ "user": {…} }`.

### 8. `GET /api/v1/settings/public`

No auth. Returns only settings with `is_public = true`, as a **flat key→value map** (not the `Setting` object) so the SPA can bootstrap before login:

```json
{
  "lab.name": "Visionary Lab",
  "lab.email": "visionarylab@polito.it",
  "lab.phone": "+39 011 090 0000",
  "lab.address": "Corso Duca degli Abruzzi 24, Torino",
  "lab.room": "Aula 3I - DAUIN",
  "booking.max_loan_days": 7,
  "booking.max_orders_per_month": 4,
  "booking.max_orders_per_year": null,
  "booking.min_advance_days": 1,
  "booking.max_advance_days": 90,
  "booking.max_items_per_order": 10,
  "booking.max_quantity_per_product_per_order": 2,
  "booking.require_professor": false,
  "booking.require_motivation": true,
  "booking.motivation_min_length": 20,
  "booking.cancellation_deadline_hours": 24,
  "hours.timezone": "Europe/Rome",
  "hours.weekly": [ … ],
  "hours.pickup_windows": [ … ],
  "hours.return_windows": [ … ],
  "hours.slot_duration_minutes": 30,
  "ui.primary_color": "#002B49",
  "ui.accent_color": "#F2A900",
  "ui.items_per_page": 24,
  "ui.catalog_default_view": "grid",
  "ui.banner_enabled": false,
  "ui.banner_message_it": "",
  "ui.banner_level": "info",
  "ui.hero_image_url": null,
  "ui.show_unit_codes_to_students": false,
  "ui.allow_anonymous_catalog": true
}
```

## 7.7 Catalog endpoints

### 9. `GET /api/v1/categories`

Query: `include_inactive` (bool, staff only, default false), `with_counts` (bool, default true).
Response: `{"data": [Category…], "meta": null}` ordered by `position ASC, name ASC`.

### 10. `GET /api/v1/categories/{idOrSlug}`
Response: `Category` + `"regulations": [ …reduced Regulation… ]`.

### 11. `POST /api/v1/categories` — T/AD
Request:
```json
{ "name": "Tecnologie Interattive", "slug": "tecnologie-interattive", "description": null,
  "icon": "vr", "image_url": null, "parent_id": null, "position": 70, "is_active": true }
```
`name` required 2..191. `slug` optional (auto-generated, uniquified with `-2`, `-3`). `409 duplicate_slug` if explicitly provided and taken. Response `201 Category`.

### 12. `PUT /api/v1/categories/{id}` — T/AD. Same body, all optional. Response `200 Category`.

### 13. `DELETE /api/v1/categories/{id}` — T/AD. Soft delete. `409 category_not_empty` if it still has non-deleted products. Response `204`.

### 14. `GET /api/v1/products`

Query parameters:

| Param | Type | Default | Notes |
|---|---|---|---|
| `q` | string | – | case-insensitive LIKE over `name`, `brand`, `model`, `description` |
| `category_id` | int | – | |
| `category_slug` | string | – | alternative to `category_id` |
| `brand` | string | – | exact match |
| `status` | string | – | `available\|maintenance\|retired`; staff only, students always see only non-`retired` |
| `loan_mode` | string | – | |
| `featured` | bool | – | |
| `available_from` | date | – | when both `available_from` and `available_to` are given, results include `available_quantity` and, if `only_available=true`, exclude products with `available_quantity < 1` |
| `available_to` | date | – | |
| `only_available` | bool | `false` | requires the two dates above |
| `has_units` | bool | – | `true` ⇒ only products with `units_total > 0` |
| `sort` | string | `position` | allowed: `position`, `name`, `created_at`, `units_available`, `popularity` |
| `order` | string | `asc` | |
| `page`, `per_page` | int | 1, 24 | |

`popularity` sorts by lifetime count of `order_items` rows in non-cancelled orders (computed with one grouped subquery).

Response:
```json
{
  "data": [ { "…ProductSummary…", "available_quantity": 4 } ],
  "meta": { "page": 1, "per_page": 24, "total": 137, "total_pages": 6 },
  "filters": {
    "categories": [ { "id": 7, "name": "Tecnologie Interattive", "slug": "tecnologie-interattive", "count": 34 } ],
    "brands": [ { "name": "Rode", "count": 22 } ]
  }
}
```
`filters` reflects the current result set **ignoring** the `category_id`/`brand` filters respectively (classic faceting), so the sidebar stays usable. `available_quantity` is present **only** when both date params are supplied.

### 15. `GET /api/v1/products/{idOrSlug}`

Query: `available_from`, `available_to` (optional) → adds `"available_quantity": n`.
Response: `Product` (visibility-filtered per §7.4). `404 not_found` for soft-deleted, or for `status='retired'` when the reader is not staff.

### 16. `POST /api/v1/products` — T/AD

```json
{
  "name": "Visore VR Meta Quest 3 128GB",
  "slug": null,
  "category_id": 7,
  "brand": "Meta",
  "model": "Quest 3",
  "description": "…markdown…",
  "specs": [ { "label": "Risoluzione", "value": "2064x2208" } ],
  "image_url": "https://…/quest3.jpg",
  "images": [ { "url": "https://…/quest3.jpg", "alt": "Fronte", "position": 0 } ],
  "status": "available",
  "loan_mode": "takeaway",
  "requires_training": true,
  "min_loan_days": null,
  "max_loan_days": 3,
  "replacement_value_note": "€ 550 ca.",
  "source_notes": null,
  "position": 10,
  "is_featured": false,
  "initial_units": 6,
  "units": [
    { "label": "01", "serial_number": "QST3-1", "asset_code": "INV-1",
      "purchase_date": "2024-03-15", "inspection_date": "2026-01-20",
      "next_inspection_date": null, "status": "available",
      "condition_note": null, "location": "Armadio B" }
  ],
  "recommended_product_ids": [ { "product_id": 133, "relation": "accessory" } ]
}
```

Rules: `name` required 2..255; `category_id` required and must exist. If `units` is provided it wins over `initial_units`. If neither is provided, `initial_units` defaults to `1`. Auto-generated unit labels are `01`, `02`, … If `images` is omitted but `image_url` is given, one image row at position 0 is created. Response `201 Product`.

### 17. `PUT /api/v1/products/{id}` — T/AD
Same fields, all optional; `initial_units` is **ignored** on update (use the unit endpoints). Passing `images` replaces the whole image collection. Passing `recommended_product_ids` replaces the whole recommendation set. Response `200 Product`.

### 18. `DELETE /api/v1/products/{id}` — T/AD
Soft delete. Refused with `409 conflict` (`details.order_ids`) if the product appears in an order with a stock-locking status. Otherwise `204`.

### 19. `GET /api/v1/products/{id}/units` — T/B/AD
`{"data": [ProductUnit…], "meta": null}` ordered by `label ASC`.

### 20. `POST /api/v1/products/{id}/units` — T/AD
Body = one unit object (as in §16 `units[]`) **or** `{"count": 3}` to append N auto-labelled units. `201 {"data":[ProductUnit…]}`.

### 21. `PUT /api/v1/units/{unitId}` — T/AD. Any unit field. `200 ProductUnit`.

### 22. `DELETE /api/v1/units/{unitId}` — T/AD. Soft delete; `409 unit_in_use` when assigned to a non-terminal order. `204`.

### 23. `GET /api/v1/products/{id}/logs`
Query: `type`, `severity`, `unit_id`, `from` (date), `to` (date), `page`, `per_page`, `sort=occurred_at`, `order=desc` (default).
Anonymous/students receive only `is_public = true` rows with `user` replaced by `null`. Response: paginated `ProductLog`.

### 24. `POST /api/v1/products/{id}/logs` — T/B/AD
```json
{ "product_unit_id": 512, "order_id": null, "type": "damage", "severity": "warning",
  "title": "Molla del cinturino persa", "body": "…", "occurred_at": "2026-06-12T15:40:00Z",
  "is_public": true }
```
`type` and `title` required. `occurred_at` defaults to now. `product_unit_id` must belong to the product. Side effect: if `type` is `damage` or `loss` and `severity = "critical"` and a `product_unit_id` is given, the unit's status is set to `maintenance` (`damage`) or `missing` (`loss`) and an audit log is written. Response `201 ProductLog`.

### 25. `PUT /api/v1/logs/{logId}` — author (T/B) or T/AD. Editable: `type`, `severity`, `title`, `body`, `occurred_at`, `resolved_at`, `is_public`. `200 ProductLog`.

### 26. `DELETE /api/v1/logs/{logId}` — T/AD. Soft delete. `204`.

### 27. `PUT /api/v1/products/{id}/recommended` — T/AD
```json
{ "items": [ { "product_id": 133, "relation": "accessory", "position": 0 } ] }
```
Replaces the full set. `422 self_recommendation` if `product_id == {id}`. Response `200 {"data": [ {relation, position, product: ProductSummary} ]}`.

### 28. `GET /api/v1/products/{id}/availability`

Query: `from` (date, required), `to` (date, required, `to >= from`, max span 366 days), `exclude_order_id` (int, staff/owner only).

Response `200`:
```json
{
  "product_id": 128,
  "capacity": 6,
  "range": { "from": "2026-08-01", "to": "2026-08-31" },
  "days": [
    { "date": "2026-08-01", "available": 4, "reserved": 2,
      "is_open": true, "can_pickup": true, "can_return": true, "closure_id": null },
    { "date": "2026-08-08", "available": 6, "reserved": 0,
      "is_open": false, "can_pickup": false, "can_return": false, "closure_id": 2 }
  ]
}
```
One entry per calendar day in the range, inclusive.

### 29. `GET /api/v1/brands`
`{"data": [ { "name": "Rode", "products_count": 22 } ], "meta": null}` sorted by name.

### 87. `PUT /api/v1/products/{id}/substitutes` — T/AD

Mirrors #27 (`recommended`), same replace-the-full-set semantics.
```json
{ "items": [ { "product_id": 133, "priority": 1 }, { "product_id": 140, "priority": 2 } ] }
```
Replaces the full substitute set for `{id}`. Priorities in the payload need not be pre-sorted; the response is always priority-ordered. `422 self_substitution` if `product_id == {id}`. The relation is directional — replacing `{id}`'s set never creates or touches rows on the other side. Response `200 {"data": [ {priority, product: ProductSummary} ]}`.

## 7.8 Availability endpoints (both booking directions)

### 30. `GET /api/v1/availability/products` — **dates → products**

"I know when I need the gear; show me what's free."

Query: `start_date` (required), `end_date` (required), plus every filter of `GET /products` (`q`, `category_id`, `category_slug`, `brand`, `loan_mode`, `sort`, `order`, `page`, `per_page`), plus:

| Param | Type | Default | Notes |
|---|---|---|---|
| `min_quantity` | int | `1` | only products with `available_quantity >= min_quantity` |
| `include_unavailable` | bool | `false` | when true, unavailable products are returned too (with `available_quantity: 0`) instead of filtered out |
| `exclude_order_id` | int | – | staff/owner |

Response:
```json
{
  "data": [
    { "…ProductSummary…", "available_quantity": 4, "capacity": 6,
      "bottleneck_date": "2026-08-03" }
  ],
  "meta": { "page": 1, "per_page": 24, "total": 52, "total_pages": 3 },
  "range": { "start_date": "2026-08-01", "end_date": "2026-08-05", "days": 5 },
  "range_validity": {
    "pickup_date_valid": true,
    "return_date_valid": true,
    "violations": []
  },
  "filters": { "categories": [ … ], "brands": [ … ] }
}
```
`range_validity.violations` uses the violation object of §5.4 (e.g. `date_not_bookable`, `max_loan_days_exceeded` with `severity: "soft"`). The endpoint **never** refuses a range; it reports.

### 31. `POST /api/v1/availability/dates` — **products → dates**

"I know what I need; show me when I can have it."

Request:
```json
{
  "items": [ { "product_id": 128, "quantity": 1 }, { "product_id": 133, "quantity": 2 } ],
  "from": "2026-08-01",
  "to": "2026-10-31",
  "duration_days": 3,
  "exclude_order_id": null
}
```
- `items` required, 1..50 entries, `quantity >= 1`.
- `from` optional, defaults to `today + booking.min_advance_days`.
- `to` optional, defaults to `from + booking.max_advance_days`. Max span 366 days.
- `duration_days` optional; when omitted defaults to `booking.max_loan_days`. Inclusive day count (a 1-day loan means `pickup_date == return_date`).

Response `200`:
```json
{
  "range": { "from": "2026-08-01", "to": "2026-10-31" },
  "duration_days": 3,
  "days": [
    {
      "date": "2026-08-01",
      "all_available": true,
      "is_open": true,
      "can_pickup": true,
      "can_return": true,
      "closure_id": null,
      "per_product": [
        { "product_id": 128, "requested": 1, "available": 4, "sufficient": true },
        { "product_id": 133, "requested": 2, "available": 2, "sufficient": true }
      ]
    }
  ],
  "windows": [
    {
      "pickup_date": "2026-08-01",
      "return_date": "2026-08-03",
      "days": 3,
      "all_available": true,
      "blocking_product_ids": []
    },
    {
      "pickup_date": "2026-08-02",
      "return_date": "2026-08-04",
      "days": 3,
      "all_available": false,
      "blocking_product_ids": [133]
    }
  ],
  "first_available_window": {
    "pickup_date": "2026-08-01", "return_date": "2026-08-03", "days": 3
  },
  "unavailable_products": []
}
```
- `days[]` — one entry per calendar day; the raw per-day picture the calendar heat-map renders.
- `windows[]` — every candidate contiguous window of exactly `duration_days` whose pickup date is a valid pickup date and whose return date is a valid return date. Windows where the pickup or return day is closed are **omitted entirely** (not returned with `all_available:false`), because they can never be booked. `blocking_product_ids` lists the products short on stock somewhere inside the window.
- `first_available_window` — first entry with `all_available = true`, or `null`.
- `unavailable_products` — products whose `capacity` is 0 for the whole horizon (retired/maintenance/no units), with `{ "product_id": 200, "reason": "no_capacity" }`.

Performance requirement: one query for the orders, one for the units. `windows` is capped at 400 entries (respond with the first 400 by `pickup_date`).

### 32. `POST /api/v1/availability/check` — pre-flight cart validation (auth required)

The frontend calls this on every meaningful change of the checkout form and **must not** enable the submit button while a `hard` violation is present.

Request:
```json
{
  "items": [ { "product_id": 128, "quantity": 1 } ],
  "pickup_date": "2026-08-01",
  "pickup_time": "09:30",
  "return_date": "2026-08-12",
  "return_time": "16:00",
  "exclude_order_id": null
}
```
If `items` is omitted, the caller's current cart is used.

Response `200` (**always 200 — violations are data, not errors**):
```json
{
  "ok": false,
  "can_submit": true,
  "exceeds_limits": true,
  "violations": [
    { "code": "max_loan_days_exceeded", "severity": "soft",
      "message": "La durata richiesta (12 giorni) supera il limite di 7 giorni.",
      "limit": 7, "actual": 12, "product_ids": [] }
  ],
  "duration_days": 12,
  "availability": [
    { "product_id": 128, "requested": 1, "available": 4, "capacity": 6, "sufficient": true },
    { "product_id": 140, "requested": 1, "available": 0, "capacity": 1, "sufficient": false,
      "suggested_substitutes": [
        { "product_id": 133, "name": "Rode NTG5", "slug": "rode-ntg5",
          "image_url": "https://…/ntg5.jpg", "available_quantity": 2, "priority": 1 }
      ] }
  ],
  "required_regulations": [
    { "id": 4, "slug": "avvertenze-vr", "title": "Avvertenze uso visori VR",
      "version": 2, "accepted": false, "scope": "category" }
  ],
  "pickup_slots": [ { "start": "09:00", "end": "09:30" }, { "start": "09:30", "end": "10:00" } ],
  "return_slots": [ { "start": "15:30", "end": "16:00" } ],
  "quota": {
    "orders_this_month": 2,
    "max_orders_per_month": 4,
    "orders_this_year": 9,
    "max_orders_per_year": null,
    "active_orders": 1,
    "max_active_orders": 2
  }
}
```
- `ok` = no violations at all.
- `can_submit` = no **hard** violation and every required regulation is either already accepted or acceptable at checkout (i.e. `can_submit` ignores unaccepted regulations because the checkout form collects them).
- `exceeds_limits` = at least one **soft** violation.
- `availability[].suggested_substitutes` is present **only** on entries with `sufficient: false`; a `sufficient: true` entry never carries the key. Suggestions are the item's DIRECT `product_substitutes` rows (§6.22) whose target product has `available_quantity >= requested` over the checked range, ordered by `priority ASC`, capped at **3**. Suggestions are explicitly **non-recursive**: a substitute's own substitutes are never consulted. Shape: `{product_id, name, slug, image_url, available_quantity, priority}`. Same computation and shape feed `GET /cart` (#34) via its embedded `check`.

### 33. `GET /api/v1/calendar/opening`

Query: `from` (date, default today), `to` (date, default `from + 90d`, max span 366 days).

```json
{
  "timezone": "Europe/Rome",
  "weekly": [
    { "weekday": 1, "label": "Lunedì", "closed": false, "open": "09:00", "close": "17:00" },
    { "weekday": 0, "label": "Domenica", "closed": true, "open": null, "close": null }
  ],
  "closures": [ { "…Closure…" } ],
  "days": [
    { "date": "2026-08-01", "weekday": 6, "is_open": false,
      "can_pickup": false, "can_return": false, "closure_id": null,
      "pickup_slots": [], "return_slots": [] },
    { "date": "2026-08-03", "weekday": 1, "is_open": true,
      "can_pickup": true, "can_return": true, "closure_id": null,
      "pickup_slots": [ { "start": "09:00", "end": "09:30" } ],
      "return_slots": [ { "start": "14:00", "end": "14:30" } ] }
  ],
  "booking_window": { "min_date": "2026-08-01", "max_date": "2026-10-30" }
}
```
`weekday` uses **ISO-ish numbering with Sunday = 0** (`0=Domenica, 1=Lunedì … 6=Sabato`) — identical to JS `Date.getDay()`. This numbering is used in `hours.weekly` too.

## 7.9 Cart & order endpoints

### 34. `GET /api/v1/cart` — student

Returns the caller's `draft` order, creating it lazily if absent.

```json
{
  "id": 91,
  "status": "draft",
  "pickup_date": "2026-08-01",
  "pickup_time": "09:30",
  "return_date": "2026-08-04",
  "return_time": "16:00",
  "items": [
    { "id": 812, "product_id": 128, "quantity": 1, "notes": null,
      "product": { "…ProductSummary…" },
      "available_quantity": 4,
      "sufficient": true }
  ],
  "items_count": 1,
  "distinct_products": 1,
  "check": { "…the full POST /availability/check response…" },
  "updated_at": "2026-07-31T08:59:00Z"
}
```
`available_quantity`/`sufficient` and `check` are present **only** when both cart dates are set; otherwise `available_quantity: null`, `sufficient: null`, `check: null`.

### 35. `POST /api/v1/cart/items` — student
```json
{ "product_id": 128, "quantity": 1, "notes": null }
```
If the product is already in the cart, `quantity` is **added** to the existing row. Validations: product exists, not soft-deleted, `status != 'retired'`, resulting quantity ≤ `booking.max_quantity_per_product_per_order` (else `422 limit_violation`), distinct products ≤ `booking.max_items_per_order`. Response `200` = the full cart object (§34).

### 36. `PATCH /api/v1/cart/items/{itemId}` — student
```json
{ "quantity": 2, "notes": "Serve la custodia rigida." }
```
`quantity: 0` deletes the row. Response `200` = full cart.

### 37. `DELETE /api/v1/cart/items/{itemId}` — student. Response `200` = full cart.

### 38. `PUT /api/v1/cart/dates` — student
```json
{ "pickup_date": "2026-08-01", "pickup_time": "09:30",
  "return_date": "2026-08-04", "return_time": "16:00" }
```
Any field may be `null` to clear. Dates are stored even if invalid (so the user can keep editing); validity is reported via `check`. Response `200` = full cart.

### 39. `DELETE /api/v1/cart` — student. Empties items and clears dates. Response `200` = full (now empty) cart.

### 88. `POST /api/v1/cart/items/{itemId}/swap` — student

Atomically replaces one cart row with a configured substitute product, keeping the same quantity.
```json
{ "product_id": 133 }
```
`product_id` must be a row of `product_substitutes` with `product_id` = the item's current product (i.e. a DIRECT, configured substitute — never derived from `suggested_substitutes` availability, only from the relation itself); otherwise `422 not_a_substitute`. If the cart already holds a row for the target product, the two rows are **merged** (quantities summed into the existing row, the swapped row deleted) subject to the same `booking.max_quantity_per_product_per_order` check as #35 (`422 limit_violation` on overflow); otherwise the existing row's `product_id` is simply updated in place. Response `200` = full cart (§34).

### 40. `POST /api/v1/orders` — student — **checkout**

Request:
```json
{
  "from_cart": true,
  "items": null,
  "pickup_date": "2026-08-01",
  "pickup_time": "09:30",
  "return_date": "2026-08-04",
  "return_time": "16:00",
  "subject": "Laboratorio di Ripresa e Montaggio",
  "motivation": "Riprese del cortometraggio d'esame.",
  "professor": "Prof.ssa Rossi",
  "notes": null,
  "accepted_regulation_ids": [4],
  "acknowledge_exceeds_limits": true
}
```

Field rules:

| Field | Required | Rules |
|---|---|---|
| `from_cart` | no (default `true`) | when `true`, the draft order is promoted; `items` is ignored |
| `items` | required when `from_cart=false` | `[{product_id, quantity, notes?}]`, 1..50 |
| `pickup_date` | **yes** | valid pickup date (§5.3) |
| `pickup_time` | **yes** | must equal the `start` of an available pickup slot |
| `return_date` | **yes** | `>= pickup_date` |
| `return_time` | **yes** | must equal the `start` of an available return slot |
| `subject` | **yes** | 2..191 |
| `motivation` | yes if `booking.require_motivation` | min length `booking.motivation_min_length` |
| `professor` | yes if `booking.require_professor` | 0..191 |
| `notes` | no | 0..2000 |
| `accepted_regulation_ids` | yes if any required regulation is unaccepted | must cover **every** required regulation for the cart contents at its current version |
| `acknowledge_exceeds_limits` | yes if soft violations exist | `false`/absent while soft violations exist ⇒ `422 limit_violation` with the violation list; the SPA re-prompts with a confirm dialog |

Behaviour: inside one transaction — re-validate availability, evaluate limits, assign `code` and `year_sequence`, set `status='pending'`, `submitted_at=now`, snapshot product name/brand into `order_items`, write `regulation_acceptances` rows for `accepted_regulation_ids`, write an `order_events` row (`action: "submit"`, `from_status: "draft"`, `to_status: "pending"`), recompute `items_count`.

Response `201 Order` (detail form) + `Location: /api/v1/orders/{id}`.

Errors: `422 validation_failed`, `422 limit_violation` (details = `{"violations":[…]}`), `422 date_not_bookable` (details = `{"field":"pickup_date","suggestions":["2026-08-03","2026-08-04","2026-08-05"]}`), `409 insufficient_availability` (details = `{"products":[{"product_id":128,"requested":2,"available":1}]}`), `409 regulation_acceptance_required` (details = `{"regulation_ids":[4]}`).

### 41. `GET /api/v1/orders`

Query:

| Param | Type | Notes |
|---|---|---|
| `status` | string or CSV | e.g. `pending` or `pending,approved` |
| `scope` | `mine\|all` | default `mine` for students (and forced), default `all` for staff |
| `user_id` | int | staff only |
| `q` | string | matches `code`, `subject`, user `display_name`, user `ldap_uid` (staff only for the user fields) |
| `product_id` | int | orders containing that product |
| `from` / `to` | date | filters on `pickup_date` |
| `late_only` | bool | `status in (overdue, returned_late)` |
| `exceeds_limits` | bool | |
| `sort` | `created_at\|submitted_at\|pickup_date\|return_date\|code\|status` (default `submitted_at`) | |
| `order` | `asc\|desc` (default `desc`) | |
| `page`, `per_page` | | |

Students always get only their own orders regardless of `scope`/`user_id`. `draft` orders are **excluded by default**; pass `status=draft` explicitly to include the cart.

Response: paginated `OrderSummary`, plus:
```json
"summary": { "pending": 4, "approved": 7, "picked_up": 3, "overdue": 1 }
```
(counts over the filtered set ignoring the `status` filter itself).

### 42. `GET /api/v1/orders/{id}` — `Order` detail. `403 forbidden` if a student requests someone else's order.

### 43. `PUT /api/v1/orders/{id}` — T/B/AD — edit an order **before pickup**

Allowed in statuses `pending` and `approved` only (else `409 invalid_transition`).
```json
{ "pickup_date": "2026-08-02", "pickup_time": "10:00",
  "return_date": "2026-08-06", "return_time": "16:00",
  "subject": "…", "professor": "…", "staff_notes": "…",
  "items": [ { "product_id": 128, "quantity": 2 } ] }
```
Passing `items` replaces the item set. Availability is re-validated with `exclude_order_id = {id}`. Limits are re-evaluated and `exceeds_limits`/`limit_violations` refreshed. Writes an `order_events` row with `action: "note"` and a `meta.changes` diff. Response `200 Order`.

### 44. `POST /api/v1/orders/{id}/approve` — T/B/AD
```json
{ "comment": "Confermato, ritiro alle 9:30.", "staff_notes": null,
  "pickup_date": null, "pickup_time": null, "return_date": null, "return_time": null }
```
Optional date/time fields let staff counter-propose in one call. Re-checks availability (excluding this order); on failure `409 insufficient_availability`. Response `200 Order`.

### 45. `POST /api/v1/orders/{id}/reject` — T/B/AD
```json
{ "reason": "Attrezzatura già impegnata in quel periodo." }
```
`reason` required, 3..2000. Stored in `rejection_reason`. Response `200 Order`.

### 46. `POST /api/v1/orders/{id}/cancel` — order owner (student) or T/B/AD
```json
{ "reason": "Non mi serve più." }
```
`reason` optional for staff, optional for students. A **student** may cancel only while `status ∈ {pending, approved}` **and** (for `approved`) at least `booking.cancellation_deadline_hours` before `pickup_date pickup_time`; otherwise `409 invalid_transition` with `message` explaining the deadline. Staff may cancel from `pending`/`approved` without the deadline. Response `200 Order`.

### 47. `POST /api/v1/orders/{id}/pickup` — T/B/AD
```json
{
  "picked_up_at": null,
  "comment": "Consegnato tutto.",
  "assignments": [
    { "order_item_id": 812, "product_unit_ids": [512], "condition_out": "ok", "note": null }
  ]
}
```
- `picked_up_at` defaults to now.
- `assignments` optional. When provided, for each item the number of unit ids must equal the item `quantity` (else `422 validation_failed`), each unit must belong to the item's product, be `status='available'`, and not be assigned to another active order (else `409 unit_in_use`).
- When `assignments` is omitted, the service auto-assigns the lowest-labelled available units. Auto-assignment failure is NOT fatal: the order still transitions, and `order_events.meta.auto_assignment` records `"partial"`.
- Side effect: assigned units are **not** status-changed (their occupancy is derived from the order), but `ProductUnit.current_order` will now report this order.

Response `200 Order`.

### 48. `POST /api/v1/orders/{id}/return` — T/B/AD
```json
{
  "returned_at": null,
  "comment": "Rientro completo.",
  "returns": [
    { "order_item_id": 812, "returned_quantity": 1,
      "units": [ { "product_unit_id": 512, "condition_in": "damaged", "note": "Cinturino lento" } ] }
  ],
  "logs": [
    { "product_id": 128, "product_unit_id": 512, "type": "damage", "severity": "warning",
      "title": "Cinturino lento", "body": null, "is_public": true }
  ]
}
```
- `returns` optional; when omitted every item is considered fully returned.
- `logs` optional; each entry creates a `product_logs` row with `order_id` pre-filled — this is how a return inspection produces damage records in one round-trip.
- Any `condition_in` of `damaged` sets the unit's status to `maintenance`; `missing` sets it to `missing`.
- Resulting status: `returned` if `returned_at` (in lab tz, date part) `<= return_date`, else `returned_late` with `late_days = date_diff`. `late_days` is also written when returning from `overdue`.

Response `200 Order`.

### 49. `POST /api/v1/orders/{id}/no-show` — T/B/AD. Body `{ "comment": "Non presentato." }`. Only from `approved`. Response `200 Order`.

### 50. `POST /api/v1/orders/{id}/reopen` — **admin only**
```json
{ "to_status": "approved", "reason": "Errore di registrazione." }
```
Moves a terminal order back to a non-terminal status. `to_status` ∈ {`pending`, `approved`, `picked_up`}. `reason` required. Always re-validates availability. This is the deliberate escape hatch; it is audit-logged. Response `200 Order`.

### 51. `POST /api/v1/orders/{id}/notes` — T/B/AD
```json
{ "staff_notes": "Testo che sostituisce le note interne.", "comment": "Aggiunta nota." }
```
`comment` (if present) creates an `order_events` row with `action: "note"`. Response `200 Order`.

### 52. `GET /api/v1/orders/{id}/events` — `{"data": [OrderEvent…], "meta": null}` ordered `created_at ASC`.

### 53. `GET /api/v1/orders/calendar` — T/B/AD

Staff planning view. Query: `from` (required), `to` (required, max span 186 days), `status` (CSV, default the locking statuses).

```json
{
  "range": { "from": "2026-08-01", "to": "2026-08-31" },
  "days": [
    {
      "date": "2026-08-01",
      "is_open": true,
      "closure_id": null,
      "pickups": [ { "order_id": 88, "code": "VL-2026-0088", "time": "09:30",
                     "user_display_name": "Marco Rossi", "items_count": 3, "status": "approved" } ],
      "returns": [],
      "overdue": []
    }
  ],
  "totals": { "pickups": 12, "returns": 11, "overdue": 1 }
}
```

### 89. `GET /api/v1/orders/{id}/pdf` — A (own) / T/B/AD (any)

Printable loan form ("modulo di ritiro/riconsegna"). Auth follows the same owner-or-staff rule as #42 (`GET /orders/{id}`), checked against the bearer token; when no bearer token is presented, a `?token=<access_token>` query parameter is accepted instead and resolved through the same JWT validation as the header (used when the frontend opens the PDF in a new tab/iframe with no Authorization header available). Missing/invalid token in both places → `401 unauthenticated`. A student requesting another student's order → `403 forbidden`.

Available only once the order has been confirmed at least once — `PRINTABLE_STATUSES`: `approved, picked_up, overdue, returned, returned_late, no_show`. Earlier statuses (`draft`, `pending`, `rejected`, `cancelled`) → `409 pdf_not_available`:
```json
{ "error": { "code": "pdf_not_available", "message": "…",
             "details": { "current_status": "pending",
               "available_from_statuses": ["approved","picked_up","overdue","returned","returned_late","no_show"] } } }
```

Response `200`: `Content-Type: application/pdf`, `Content-Disposition: inline; filename="modulo-{code}.pdf"` (falls back to `modulo-ordine-{id}.pdf` when the order has no `code` yet), `Content-Length` set to the byte length of the body. Rendered with dompdf, A4 portrait, core fonts only, `isRemoteEnabled = false` (no network access at render time). The layout is entirely owned by `backend/templates/order_form.php` — **the single file to swap** to adopt an institutional template; `App\Domain\Orders\OrderPdfService` only assembles the data array (`lab`, `order`, `user`, `items[]`, `generated_at` — keys documented in the template's own header comment) and drives dompdf. No application logic lives in the template.

## 7.10 Regulations endpoints

### 54. `GET /api/v1/regulations`
Query: `scope`, `is_active` (staff only; students always get active+published), `product_id`, `category_id`, `requires_acceptance`, `page`, `per_page`, `sort=position|title|version|published_at`.
Response: paginated `Regulation` **without `body`**. Drafts (`published_at = null`) are visible only to staff.

### 55. `GET /api/v1/regulations/{idOrSlug}` — full `Regulation` including `body`.

### 56. `GET /api/v1/regulations/{id}/file`
Streams the PDF with `Content-Type: application/pdf`, `Content-Disposition: inline; filename="<file_name>"`, `Content-Length`. `404` if the regulation is not a PDF. **This endpoint accepts the access token either in the `Authorization` header or as a `?token=` query parameter**, because `<iframe>`/`<object>` embeds cannot set headers. The query-token path must accept only access tokens (never refresh tokens).

### 57. `POST /api/v1/regulations` — T/AD
```json
{
  "title": "Avvertenze uso visori VR",
  "slug": null,
  "summary": "Rischi fotosensibilità ed epilessia.",
  "scope": "category",
  "content_type": "markdown",
  "body": "# Avvertenze\n…",
  "requires_acceptance": true,
  "is_active": true,
  "position": 20,
  "targets": [ { "target_type": "category", "target_id": 7 } ],
  "publish": false
}
```
`scope='global'` ⇒ `targets` must be empty. `scope='category'|'product'` ⇒ `targets` must be non-empty and every `target_id` must exist. `publish: true` sets `published_at = now` immediately (version stays 1). Response `201 Regulation`.

### 58. `PUT /api/v1/regulations/{id}` — T/AD. Same fields. Editing a **published** regulation's `body`/`file` does **not** auto-bump the version — the editor must call `publish` to make the change re-acceptance-worthy. This is deliberate: it lets staff fix typos without re-prompting 800 students. Response `200 Regulation`.

### 59. `POST /api/v1/regulations/{id}/file` — T/AD — `multipart/form-data`, field name `file`.
Accepts `application/pdf` only, max `UPLOAD_MAX_BYTES`. Stores under `storage/uploads/regulations/{id}/{sha1}.pdf`. Sets `content_type='pdf'`, `file_path`, `file_name`, `file_size`, `file_mime`. Errors `415 unsupported_media_type`, `413 payload_too_large`. Response `200 Regulation`.

### 60. `POST /api/v1/regulations/{id}/publish` — T/AD
```json
{ "bump_version": true, "note": "Aggiornata sezione 4." }
```
`bump_version` default `true`. Sets `published_at = now`, `version += 1` when bumping. Bumping invalidates all prior acceptances (they no longer match `version`). Response `200 Regulation`.

### 61. `DELETE /api/v1/regulations/{id}` — **admin only**. Soft delete. `204`.

### 62. `GET /api/v1/regulations/{id}/acceptances` — T/B/AD
Query: `version`, `user_id`, `page`, `per_page`.
```json
{ "data": [ { "id": 5501, "user": { "id": 3, "display_name": "Marco Rossi", "ldap_uid": "student1" },
             "version": 2, "order_id": 88, "accepted_at": "2026-07-25T11:01:55Z", "ip": "10.0.0.5" } ],
  "meta": { … },
  "stats": { "current_version": 2, "accepted_current_version": 214, "total_users": 380 } }
```

### 63. `GET /api/v1/me/regulations/pending`
```json
{ "data": [ { "id": 1, "slug": "regolamento-generale", "title": "…", "summary": "…",
              "scope": "global", "version": 3, "content_type": "markdown",
              "file_url": null, "blocking": true } ], "meta": null }
```
`blocking: true` for `scope = "global"` (blocks the whole SPA); `false` for scoped regulations (collected at checkout). This endpoint returns **only global** pending ones; product/category ones come from `/availability/check`.

### 64. `POST /api/v1/me/regulations/{id}/accept`
```json
{ "version": 3, "order_id": null }
```
`version` required and must equal the regulation's current version (else `409 conflict`, `message: "Il regolamento è stato aggiornato, ricarica la pagina."`). Idempotent: re-accepting the same version returns `200` with the existing row. Response:
```json
{ "accepted": true, "regulation_id": 1, "version": 3, "accepted_at": "2026-07-31T09:10:00Z",
  "pending_regulations": [] }
```

## 7.11 Settings, closures, users

### 65. `GET /api/v1/settings` — T/B/AD (read-only for T/B)
Query: `group` (optional filter).
```json
{
  "data": [ { "…Setting…" } ],
  "meta": null,
  "groups": [
    { "key": "lab", "label_it": "Laboratorio", "position": 10 },
    { "key": "hours", "label_it": "Orari e chiusure", "position": 20 },
    { "key": "booking", "label_it": "Prenotazioni e limiti", "position": 30 },
    { "key": "regulations", "label_it": "Regolamenti", "position": 40 },
    { "key": "ldap", "label_it": "LDAP", "position": 50 },
    { "key": "security", "label_it": "Sicurezza", "position": 60 },
    { "key": "notifications", "label_it": "Notifiche", "position": 70 },
    { "key": "ui", "label_it": "Aspetto", "position": 80 },
    { "key": "stats", "label_it": "Statistiche", "position": 90 }
  ]
}
```
Technicians/assistants receive the list **without** the `ldap` and `security` groups and without any `is_secret` setting.

### 66. `PUT /api/v1/settings` — **admin only** — bulk update
```json
{ "settings": { "booking.max_loan_days": 10, "booking.max_orders_per_month": null,
                "lab.name": "Visionary Lab" } }
```
Atomic: validate every key first (`422 unknown_setting_key` with `details.keys`, or `422 validation_failed` with per-key messages), then write all in one transaction. Sending the literal string `"********"` for a secret leaves it unchanged. Response `200` = the same shape as `GET /settings`.

Type validation rules: `int` must be an integer (or `null` when `nullable`); `bool` must be a JSON boolean; `time` must match `^([01]\d|2[0-3]):[0-5]\d$`; `date` must be `YYYY-MM-DD`; `enum` must be in `options`; `json` must match the documented shape (§10 gives the shape for each json setting — validate `hours.weekly` and the window arrays strictly).

### 67. `PUT /api/v1/settings/{key}` — admin — `{ "value": 10 }`. Response `200 Setting`.

### 68. `POST /api/v1/settings/ldap/test` — admin
```json
{ "host": "ldap.polito.it", "port": 389, "encryption": "tls",
  "base_dn": "dc=polito,dc=it", "bind_dn": "cn=svc,dc=polito,dc=it",
  "bind_password": "…", "user_filter": "(uid=%s)", "test_username": "student1" }
```
All fields optional — omitted ones fall back to the stored settings. Never persists anything.
```json
{ "ok": true, "message": "Connessione riuscita, 1 utente trovato.",
  "latency_ms": 43, "entries_found": 1, "mode": "real" }
```
Returns `200` even on failure with `ok: false` and a diagnostic `message`.

### 69. `GET /api/v1/closures`
Query: `from`, `to`, `include_past` (bool, default false → only closures with `end_date >= today`), `page`, `per_page`.
Response: paginated `Closure`, sorted `start_date ASC`.

### 70. `POST /api/v1/closures` — T/AD. Body = `Closure` minus id/timestamps. `end_date >= start_date` required. Response `201`.
### 71. `PUT /api/v1/closures/{id}` — T/AD. `200`.
### 72. `DELETE /api/v1/closures/{id}` — T/AD. `204`.

> Creating a closure that overlaps existing **approved** orders does not fail; the response includes `"affected_orders": [ { "id": 88, "code": "VL-2026-0088", "pickup_date": "…" } ]` so the UI can warn staff to contact those students.

### 73. `GET /api/v1/users` — T/B/AD
Query: `q` (matches `display_name`, `ldap_uid`, `email`, `matricola`), `role`, `is_active`, `has_active_orders` (bool), `sort=display_name|last_login_at|created_at`, `order`, `page`, `per_page`.
Response: paginated `User` + per-user aggregates:
```json
{ "…User…", "orders_count": 14, "active_orders_count": 1, "late_returns_count": 2 }
```

### 74. `GET /api/v1/users/{id}` — T/B/AD. Same object plus `"recent_orders": [OrderSummary…]` (last 10).

### 75. `PUT /api/v1/users/{id}` — **admin only**
```json
{ "role": "technician", "role_locked": true, "is_active": true, "notes": "Borsista 2026/27." }
```
Changing `role` or setting `is_active: false` increments `token_version` (invalidating that user's JWTs) and writes an audit log. An admin cannot demote or deactivate **themselves** → `422 validation_failed` (`details.role: ["Non puoi modificare il tuo stesso ruolo."]`). Response `200 User`.

### 76. `GET /api/v1/users/{id}/orders` — T/B/AD. Same query params and response as `GET /orders` with `user_id` forced.

### 85. `GET /api/v1/audit-logs` — admin
Query: `action`, `entity_type`, `entity_id`, `user_id`, `from`, `to`, `page`, `per_page`.
```json
{ "data": [ { "id": 4001, "action": "settings.update", "entity_type": "Setting",
              "entity_id": "booking.max_loan_days",
              "user": { "id": 9, "display_name": "Anna Ricci" },
              "changes": { "before": { "value": 7 }, "after": { "value": 10 } },
              "ip": "10.0.0.9", "created_at": "2026-07-30T16:00:00Z" } ],
  "meta": { … } }
```

### 86. `GET /api/v1/logs` — T/B/AD — cross-product log feed

Same response shape as `GET /products/{id}/logs` (paginated `ProductLog`), for the staff-wide "Registro" view.
Query: `product_id`, `category_id`, `product_unit_id`, `type`, `severity`, `unresolved` (bool — `resolved_at IS NULL`), `user_id`, `from`, `to`, `q` (matches `title`/`body`), `page`, `per_page`, `sort=occurred_at|severity` (default `occurred_at`), `order` (default `desc`). Includes `is_public = false` entries (staff-only endpoint).
Additionally returns:
```json
"summary": { "damage": 14, "maintenance": 9, "inspection": 31, "note": 6, "loss": 2, "repair": 4, "unresolved": 11 }
```

## 7.12 Statistics endpoints

All stats endpoints accept `from` (date) and `to` (date); defaults `to = today`, `from = today - stats.default_range_days`. Max span 1830 days (5 years). Orders counted are those with `submitted_at` in range unless stated otherwise. `draft` orders are always excluded.

### 77. `GET /api/v1/stats/overview` — T/B/AD

Assistants (`borsista`) receive the **limited** variant: the `operational` block only, with `financial`-ish and global-trend blocks omitted. Field presence is explicit so the frontend can branch on `scope`.

```json
{
  "scope": "full",
  "range": { "from": "2026-05-02", "to": "2026-07-31" },
  "operational": {
    "orders_pending": 4,
    "orders_approved": 7,
    "orders_picked_up": 3,
    "orders_overdue": 1,
    "pickups_today": 2,
    "returns_today": 3,
    "returns_next_7_days": 9
  },
  "totals": {
    "orders_total": 128,
    "orders_approved": 96,
    "orders_rejected": 12,
    "orders_cancelled": 14,
    "orders_no_show": 6,
    "orders_returned_late": 11,
    "approval_rate": 0.75,
    "late_rate": 0.114,
    "items_loaned": 341,
    "unique_students": 74,
    "avg_loan_days": 4.2,
    "avg_approval_hours": 18.6
  },
  "inventory": {
    "products_total": 412,
    "units_total": 1187,
    "units_available": 1042,
    "units_maintenance": 61,
    "units_missing": 9,
    "units_retired": 75,
    "units_on_loan_now": 38,
    "utilization_now": 0.036
  }
}
```
For `scope: "limited"` the `totals` and `inventory` blocks are **omitted entirely** (key absent, not null).

`approval_rate` = approved+picked_up+returned+returned_late+overdue over all non-draft, non-cancelled. `utilization_now` = `units_on_loan_now / units_available`. All ratios are floats rounded to 3 decimals; division by zero yields `0`.

### 78. `GET /api/v1/stats/loans-over-time` — T/AD
Query: `granularity` = `day|week|month` (default `week`), `from`, `to`, `category_id` (optional), `metric` = `orders|items` (default `orders`).
```json
{
  "granularity": "week",
  "metric": "orders",
  "series": [
    { "bucket": "2026-W20", "bucket_start": "2026-05-11", "bucket_end": "2026-05-17",
      "submitted": 9, "approved": 7, "rejected": 1, "cancelled": 1,
      "returned": 6, "returned_late": 1 }
  ],
  "totals": { "submitted": 128, "approved": 96, "rejected": 12,
              "cancelled": 14, "returned": 84, "returned_late": 11 }
}
```
Bucket keys: day → `2026-05-11`; week → ISO week `2026-W20`; month → `2026-05`. Buckets with zero activity ARE emitted (dense series) so charts don't lie.

### 79. `GET /api/v1/stats/top-products` — T/AD
Query: `limit` (1..100, default 10), `category_id`, `metric` = `orders|quantity|days` (default `orders`).
```json
{ "metric": "orders", "data": [
  { "product_id": 128, "name": "Visore VR Meta Quest 3 128GB", "slug": "visore-vr-meta-quest-3",
    "brand": "Meta", "category": { "id": 7, "name": "Tecnologie Interattive" },
    "image_url": "https://…", "orders_count": 41, "quantity_total": 48,
    "loan_days_total": 173, "units_total": 6, "utilization": 0.31 } ] }
```
`utilization` = `loan_days_total / (units_total * days_in_range)`, rounded to 3 decimals.

### 80. `GET /api/v1/stats/by-category` — T/AD
```json
{ "data": [ { "category_id": 7, "name": "Tecnologie Interattive", "slug": "tecnologie-interattive",
              "orders_count": 63, "quantity_total": 88, "loan_days_total": 310,
              "products_count": 34, "units_total": 96, "share": 0.24, "utilization": 0.11 } ],
  "totals": { "orders_count": 262, "quantity_total": 341, "loan_days_total": 1290 } }
```
`share` = category `orders_count` / total, 3 decimals.

### 81. `GET /api/v1/stats/late-returns` — T/B/AD
Query: `from`, `to`, `min_days` (int, default 1), `page`, `per_page`, `include_open` (bool, default true → include currently `overdue` orders using today as the reference).
```json
{ "data": [ { "order_id": 77, "code": "VL-2026-0077", "status": "returned_late",
              "user": { "id": 3, "display_name": "Marco Rossi", "ldap_uid": "student1" },
              "return_date": "2026-06-10", "returned_at": "2026-06-14T10:12:00Z",
              "late_days": 4, "items_count": 2 } ],
  "meta": { … },
  "summary": { "late_orders": 11, "late_days_total": 29, "avg_late_days": 2.6,
               "students_involved": 8, "currently_overdue": 1 } }
```

### 82. `GET /api/v1/stats/utilization` — T/AD
Query: `granularity` = `day|week|month` (default `week`), `category_id`, `product_id`.
```json
{ "granularity": "week",
  "series": [ { "bucket": "2026-W20", "bucket_start": "2026-05-11",
                "units_on_loan_avg": 27.4, "units_available": 1042, "utilization": 0.026 } ],
  "peak": { "bucket": "2026-W22", "utilization": 0.061 } }
```

### 83. `GET /api/v1/stats/my-activity` — T/B/AD (any staff role, own data)
```json
{ "user_id": 5,
  "range": { "from": "2026-05-02", "to": "2026-07-31" },
  "counts": { "approved": 41, "rejected": 6, "pickups": 38, "returns": 35,
              "logs_created": 22, "notes_added": 9, "products_created": 3,
              "products_updated": 17 },
  "series": [ { "bucket": "2026-W20", "bucket_start": "2026-05-11", "actions": 12 } ],
  "recent_events": [ { "…OrderEvent…", "order": { "id": 88, "code": "VL-2026-0088" } } ] }
```
`products_created`/`products_updated` are read from `audit_logs`; for assistants they are always `0`.

### 84. `GET /api/v1/stats/export` — T/AD
Query: `dataset` = `orders|products|late_returns|logs` (required), `from`, `to`, `format` = `csv` (only value).
Returns `text/csv; charset=utf-8` with `Content-Disposition: attachment; filename="vlab-<dataset>-<from>_<to>.csv"`, comma-separated, `"` quoting, CRLF line endings, UTF-8 BOM (so Excel on Windows opens it correctly).

`orders` columns (in order): `code,status,student_uid,student_name,subject,professor,pickup_date,pickup_time,return_date,return_time,picked_up_at,returned_at,late_days,items_count,exceeds_limits,decided_by,submitted_at`.

---

# 8. Order state machine

## 8.1 States

| State | Italian label | Terminal | Locks stock | Meaning |
|---|---|---|---|---|
| `draft` | Bozza | no | **no** | the student's cart; one per user; no `code` yet |
| `pending` | In attesa | no | yes¹ | submitted, awaiting lab decision |
| `approved` | Approvato | no | yes | confirmed by the lab, equipment reserved, not yet collected |
| `rejected` | Respinto | **yes** | no | refused by the lab, `rejection_reason` set |
| `cancelled` | Annullato | **yes** | no | withdrawn by the student or by staff |
| `picked_up` | Ritirato | no | yes | equipment is physically out |
| `overdue` | In ritardo | no | yes | picked up and past the due date |
| `returned` | Restituito | **yes** | no | returned on or before the due date |
| `returned_late` | Restituito in ritardo | **yes** | no | returned after the due date; `late_days > 0` |
| `no_show` | Non ritirato | **yes** | no | approved but never collected within the grace period |

¹ `pending` locks stock only when setting `booking.pending_locks_stock` is `true` (default). This is the only configurable part of the machine.

## 8.2 Transition table (BINDING)

| # | From | Action | To | Who may trigger | Guards | Side effects |
|---|---|---|---|---|---|---|
| 1 | *(none)* | `create_cart` | `draft` | student (implicit, on first `GET /cart`) | – | creates the single draft order |
| 2 | `draft` | `submit` | `pending` | **owner student** (`POST /orders`) | all hard limit checks pass; availability sufficient; required regulations accepted; `acknowledge_exceeds_limits` when soft violations exist | assigns `code`+`year_sequence`; sets `submitted_at`; snapshots product names; writes acceptances; `items_count` recomputed |
| 3 | `pending` | `approve` | `approved` | technician, assistant, admin | availability still sufficient (excluding self) | sets `decided_by`, `decided_at`; may overwrite dates/times |
| 4 | `pending` | `reject` | `rejected` | technician, assistant, admin | `reason` non-empty | sets `rejection_reason`, `decided_by`, `decided_at` |
| 5 | `pending` | `cancel` | `cancelled` | **owner student**, technician, assistant, admin | – | sets `cancelled_by`, `cancelled_at` |
| 6 | `approved` | `cancel` | `cancelled` | **owner student** (only if now < pickup datetime − `booking.cancellation_deadline_hours`), technician, assistant, admin | deadline for students only | as above |
| 7 | `approved` | `pickup` | `picked_up` | technician, assistant, admin | – | sets `picked_up_at`, `handed_over_by`; writes `order_item_units` assignments |
| 8 | `approved` | `mark_no_show` | `no_show` | technician, assistant, admin | – | – |
| 9 | `approved` | *(system)* `mark_no_show` | `no_show` | **system** | `now > pickup_date 23:59 + booking.no_show_grace_hours` | `actor_type='system'` |
| 10 | `picked_up` | `return` | `returned` | technician, assistant, admin | return date ≤ due date | sets `returned_at`, `received_by`, `returned_quantity`, unit `condition_in`; may create product logs |
| 11 | `picked_up` | `return` | `returned_late` | technician, assistant, admin | return date > due date | as above **plus** `late_days` |
| 12 | `picked_up` | *(system)* `mark_overdue` | `overdue` | **system** | `now > due_date 23:59 + booking.overdue_grace_hours` | `actor_type='system'` |
| 13 | `overdue` | `return` | `returned_late` | technician, assistant, admin | – | as #11 |
| 14 | `rejected`,`cancelled`,`no_show`,`returned`,`returned_late` | `reopen` | `pending` \| `approved` \| `picked_up` | **admin only** | `reason` required; availability re-validated | audit-logged; `order_events.meta.reason` |
| 15 | any non-terminal | `note` | *(unchanged)* | technician, assistant, admin | – | appends an `order_events` row; `staff_notes` may be replaced |
| 16 | `pending`,`approved` | `edit` | *(unchanged)* | technician, assistant, admin | availability re-validated excluding self | limits re-evaluated; `order_events` diff |

**Anything not in this table is forbidden** → `409 invalid_transition` with:
```json
{ "error": { "code": "invalid_transition", "message": "Operazione non consentita nello stato attuale.",
  "details": { "current_status": "returned", "action": "approve",
               "allowed_actions": [] } } }
```

## 8.3 Diagram

```
                     ┌────────┐
                     │ draft  │ (cart)
                     └───┬────┘
                 submit  │ (student)
                         ▼
                    ┌─────────┐  reject (staff)   ┌──────────┐
                    │ pending ├──────────────────►│ rejected │◄─┐
                    └────┬────┘                   └──────────┘  │
             approve     │      cancel (student/staff)          │
             (staff)     │            └──────────► ┌───────────┐│
                         ▼                         │ cancelled ││
                   ┌──────────┐                    └───────────┘│
                   │ approved ├── mark_no_show ──► ┌──────────┐ │
                   └────┬─────┘   (staff/system)   │ no_show  │ │
             pickup     │                          └──────────┘ │
             (staff)    │                                       │
                        ▼                                       │
                  ┌───────────┐  return (on time)  ┌──────────┐ │
                  │ picked_up ├───────────────────►│ returned │ │
                  └────┬──────┘                    └──────────┘ │
        mark_overdue   │  return (late)  ┌────────────────────┐ │
        (system)       │  ──────────────►│  returned_late     │ │
                       ▼                 └────────────────────┘ │
                  ┌──────────┐  return    ▲                     │
                  │ overdue  ├────────────┘                     │
                  └──────────┘                                  │
                                                                │
   reopen (ADMIN ONLY, from any terminal state) ────────────────┘
      → pending | approved | picked_up
```

## 8.4 `allowed_actions` computation

`OrderStateMachine::allowedActions(Order $o, User $viewer): string[]` returns the subset of `{submit, approve, reject, cancel, pickup, return, mark_no_show, reopen, edit, note}` reachable **for that viewer** given §8.2, including the student cancellation deadline. This is the value serialized into `Order.allowed_actions`. Frontend buttons are rendered exclusively from it.

---

# 9. Permission matrix

## 9.1 Roles

| Key | Italian | Description |
|---|---|---|
| `student` | Studente | Default for anyone authenticating without a staff group. |
| `technician` | Tecnico | Lab technician: full operational control over catalog and orders. |
| `assistant` | Borsista | Scholarship assistant: a **limited technician**. Handles orders and adds product logs; cannot touch the catalog structure, settings, or global statistics. |
| `admin` | Amministratore | Everything, plus settings, user roles, regulation deletion, order reopen, audit log. |

## 9.2 Capability matrix

Legend: ✔ allowed · ✖ denied · **O** only own records · ◐ limited (see note).

| Capability | Anon | Student | Assistant (borsista) | Technician | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| **Catalog** |
| View categories & products | ✔ ◐¹ | ✔ | ✔ | ✔ | ✔ |
| View retired products / unit codes | ✖ | ✖ ◐² | ✔ | ✔ | ✔ |
| View product units (serials, asset codes) | ✖ | ✖ | ✔ | ✔ | ✔ |
| Create / edit / delete category | ✖ | ✖ | **✖** | ✔ | ✔ |
| Create / edit / delete product | ✖ | ✖ | **✖** | ✔ | ✔ |
| Create / edit / delete product unit | ✖ | ✖ | **✖** | ✔ | ✔ |
| Manage recommended products | ✖ | ✖ | **✖** | ✔ | ✔ |
| **Product logs** |
| View public logs | ✔ | ✔ | ✔ | ✔ | ✔ |
| View private (`is_public=false`) logs | ✖ | ✖ | ✔ | ✔ | ✔ |
| Create product log | ✖ | ✖ | **✔** | ✔ | ✔ |
| Edit product log | ✖ | ✖ | **O** | ✔ | ✔ |
| Delete product log | ✖ | ✖ | ✖ | ✔ | ✔ |
| **Availability** |
| Query availability (both directions) | ✔ | ✔ | ✔ | ✔ | ✔ |
| Query with `exclude_order_id` | ✖ | O | ✔ | ✔ | ✔ |
| **Cart & orders** |
| Use cart / create order | ✖ | ✔ | ✖³ | ✖³ | ✖³ |
| View own orders | ✖ | ✔ | ✔ | ✔ | ✔ |
| View all orders | ✖ | ✖ | ✔ | ✔ | ✔ |
| Approve / reject order | ✖ | ✖ | **✔** | ✔ | ✔ |
| Cancel order | ✖ | O ◐⁴ | ✔ | ✔ | ✔ |
| Mark picked up / returned | ✖ | ✖ | **✔** | ✔ | ✔ |
| Mark no-show | ✖ | ✖ | ✔ | ✔ | ✔ |
| Edit order dates/items (pre-pickup) | ✖ | ✖ | ✔ | ✔ | ✔ |
| Add staff notes to order | ✖ | ✖ | ✔ | ✔ | ✔ |
| View staff notes | ✖ | ✖ | ✔ | ✔ | ✔ |
| **Reopen** a terminal order | ✖ | ✖ | ✖ | **✖** | ✔ |
| Staff order calendar | ✖ | ✖ | ✔ | ✔ | ✔ |
| **Statistics** |
| `stats/overview` (limited: operational block only) | ✖ | ✖ | **✔ ◐⁵** | ✔ | ✔ |
| `stats/late-returns` | ✖ | ✖ | ✔ | ✔ | ✔ |
| `stats/my-activity` | ✖ | ✖ | ✔ | ✔ | ✔ |
| `stats/loans-over-time`, `top-products`, `by-category`, `utilization` | ✖ | ✖ | **✖** | ✔ | ✔ |
| `stats/export` | ✖ | ✖ | **✖** | ✔ | ✔ |
| **Regulations** |
| Read published regulations | ✔ | ✔ | ✔ | ✔ | ✔ |
| Read drafts | ✖ | ✖ | ✔ | ✔ | ✔ |
| Accept regulations | ✖ | ✔ | ✔ | ✔ | ✔ |
| Create / edit / publish / upload | ✖ | ✖ | **✖** | ✔ | ✔ |
| Delete regulation | ✖ | ✖ | ✖ | **✖** | ✔ |
| View acceptance report | ✖ | ✖ | ✔ | ✔ | ✔ |
| **Closures** |
| View closures | ✔ | ✔ | ✔ | ✔ | ✔ |
| Create / edit / delete closure | ✖ | ✖ | **✖** | ✔ | ✔ |
| **Settings** |
| Read settings (non-secret, non-ldap/security groups) | ✖ | ✖ | ✔ | ✔ | ✔ |
| Read `ldap`/`security` groups & secrets | ✖ | ✖ | ✖ | ✖ | ✔ |
| **Modify any setting** | ✖ | ✖ | **✖** | **✖** | ✔ |
| Test LDAP connection | ✖ | ✖ | ✖ | ✖ | ✔ |
| **Users** |
| List / view users | ✖ | ✖ | ✔ | ✔ | ✔ |
| Change role / activate / deactivate | ✖ | ✖ | ✖ | **✖** | ✔ |
| **Audit log** | ✖ | ✖ | ✖ | ✖ | ✔ |

Notes:
1. Anonymous catalog browsing is gated by setting `ui.allow_anonymous_catalog` (default `true`). When `false`, catalog endpoints require authentication.
2. Students see unit labels only if `ui.show_unit_codes_to_students` is `true`, and never see serial numbers, asset codes or locations.
3. Staff accounts do not have a cart in v1. `POST /cart/*` and `POST /orders` return `403 role_required` for non-students. If a technician needs to book for a student, they create the order through the student's record — **out of scope for v1**; the documented workaround is to have the student submit and the technician approve.
4. A student may cancel `pending` freely, and `approved` only before the cancellation deadline.
5. The assistant's `stats/overview` response has `scope: "limited"` and omits the `totals` and `inventory` blocks.

## 9.3 `permissions` object returned by `/auth/me`

| Key | student | assistant | technician | admin |
|---|:--:|:--:|:--:|:--:|
| `products.manage` | false | **false** | true | true |
| `orders.manage` | false | **true** | true | true |
| `orders.create` | **true** | false | false | false |
| `logs.create` | false | **true** | true | true |
| `settings.manage` | false | false | false | **true** |
| `settings.view` | false | true | true | true |
| `stats.view_full` | false | **false** | true | true |
| `stats.view_limited` | false | **true** | true | true |
| `users.manage` | false | false | false | **true** |
| `users.view` | false | true | true | true |
| `regulations.manage` | false | **false** | true | true |
| `regulations.delete` | false | false | false | **true** |
| `closures.manage` | false | **false** | true | true |
| `orders.reopen` | false | false | false | **true** |
| `audit.view` | false | false | false | **true** |

This table is the definitive answer to "what exactly is a borsista allowed to do". Backend `RequireRoleMiddleware` and frontend guards must both derive from it.

---

# 10. Settings registry

Complete list of seeded keys. **`Default` shows the decoded value; the DB stores it JSON-encoded.**
`Public` = returned by `GET /settings/public` without auth. `Null?` = `null` is a legal value (meaning *infinite* / *not set*).

## 10.1 Group `lab` (position 10)

| Key | Type | Default | Public | Null? | Description (label_it) |
|---|---|---|:--:|:--:|---|
| `lab.name` | string | `"Visionary Lab"` | ✔ | ✖ | Nome del laboratorio |
| `lab.subtitle` | string | `"Politecnico di Torino — Prestito attrezzature"` | ✔ | ✖ | Sottotitolo mostrato nell'header |
| `lab.department` | string | `"DAUIN — Ingegneria del Cinema e dei Mezzi di Comunicazione"` | ✔ | ✖ | Dipartimento / corso di riferimento |
| `lab.email` | string | `"visionarylab@polito.it"` | ✔ | ✖ | Email di contatto |
| `lab.phone` | string | `""` | ✔ | ✖ | Telefono |
| `lab.address` | string | `"Corso Duca degli Abruzzi 24, 10129 Torino"` | ✔ | ✖ | Indirizzo |
| `lab.room` | string | `""` | ✔ | ✖ | Aula / locale di ritiro |
| `lab.website_url` | string | `"https://www.polito.it"` | ✔ | ✖ | Sito web istituzionale |
| `lab.logo_url` | string | `""` | ✔ | ✖ | URL del logo (vuoto = logo di default) |
| `lab.support_note_it` | string | `"Per assistenza scrivi a visionarylab@polito.it"` | ✔ | ✖ | Nota di supporto nel footer |

## 10.2 Group `hours` (position 20)

| Key | Type | Default | Public | Null? | Description |
|---|---|---|:--:|:--:|---|
| `hours.timezone` | string | `"Europe/Rome"` | ✔ | ✖ | Fuso orario del laboratorio |
| `hours.weekly` | json | see below | ✔ | ✖ | Orari di apertura per giorno della settimana |
| `hours.pickup_windows` | json | see below | ✔ | ✖ | Fasce orarie per il ritiro |
| `hours.return_windows` | json | see below | ✔ | ✖ | Fasce orarie per la riconsegna |
| `hours.slot_duration_minutes` | int | `30` | ✔ | ✖ | Durata di ogni slot orario (minuti) |

`hours.weekly` — **exactly 7 entries, weekday 0..6, Sunday = 0**:
```json
[
  { "weekday": 0, "closed": true,  "open": null,    "close": null },
  { "weekday": 1, "closed": false, "open": "09:00", "close": "17:00" },
  { "weekday": 2, "closed": false, "open": "09:00", "close": "17:00" },
  { "weekday": 3, "closed": false, "open": "09:00", "close": "17:00" },
  { "weekday": 4, "closed": false, "open": "09:00", "close": "17:00" },
  { "weekday": 5, "closed": false, "open": "09:00", "close": "14:00" },
  { "weekday": 6, "closed": true,  "open": null,    "close": null }
]
```
Validation: 7 entries, unique weekdays 0..6, `closed` boolean, when `closed=false` both `open` and `close` are `HH:MM` and `open < close`.

`hours.pickup_windows` default:
```json
[
  { "weekday": 1, "from": "09:00", "to": "12:30" },
  { "weekday": 2, "from": "09:00", "to": "12:30" },
  { "weekday": 3, "from": "09:00", "to": "12:30" },
  { "weekday": 4, "from": "09:00", "to": "12:30" },
  { "weekday": 5, "from": "09:00", "to": "12:00" }
]
```
`hours.return_windows` default:
```json
[
  { "weekday": 1, "from": "14:00", "to": "17:00" },
  { "weekday": 2, "from": "14:00", "to": "17:00" },
  { "weekday": 3, "from": "14:00", "to": "17:00" },
  { "weekday": 4, "from": "14:00", "to": "17:00" },
  { "weekday": 5, "from": "12:00", "to": "14:00" }
]
```
Validation for both: array of `{weekday:0..6, from:"HH:MM", to:"HH:MM"}` with `from < to`; multiple entries per weekday are allowed (morning + afternoon); a weekday absent from the array means *no slots that day*; an **empty array** means "fall back to `hours.weekly` open/close".

## 10.3 Group `booking` (position 30)

| Key | Type | Default | Public | Null? | Description |
|---|---|---|:--:|:--:|---|
| `booking.max_loan_days` | int | `7` | ✔ | ✖ | Durata massima standard del prestito (giorni, estremi inclusi) |
| `booking.max_loan_days_hard_cap` | int | `30` | ✔ | ✔ | Limite invalicabile di durata; `null` = nessun limite assoluto |
| `booking.max_orders_per_month` | int | `4` | ✔ | ✔ | Numero massimo di prestiti al mese; `null` = illimitato |
| `booking.max_orders_per_year` | int | `null` | ✔ | ✔ | Numero massimo di prestiti all'anno; `null` = illimitato |
| `booking.max_active_orders` | int | `2` | ✔ | ✔ | Prestiti contemporaneamente attivi per studente; `null` = illimitato |
| `booking.max_items_per_order` | int | `10` | ✔ | ✔ | Prodotti distinti per richiesta; `null` = illimitato |
| `booking.max_quantity_per_product_per_order` | int | `2` | ✔ | ✖ | Quantità massima dello stesso prodotto in una richiesta |
| `booking.min_advance_days` | int | `1` | ✔ | ✖ | Preavviso minimo per il ritiro (giorni) |
| `booking.max_advance_days` | int | `90` | ✔ | ✖ | Anticipo massimo di prenotazione (giorni) |
| `booking.buffer_days_between_loans` | int | `0` | ✖ | ✖ | Giorni di margine dopo la riconsegna prima di un nuovo prestito |
| `booking.pending_locks_stock` | bool | `true` | ✖ | ✖ | Le richieste in attesa impegnano già la disponibilità |
| `booking.allow_exceeding_limits` | bool | `true` | ✔ | ✖ | Consenti l'invio di richieste fuori limite (con avviso) |
| `booking.cancellation_deadline_hours` | int | `24` | ✔ | ✖ | Ore prima del ritiro entro cui lo studente può annullare |
| `booking.no_show_grace_hours` | int | `48` | ✖ | ✖ | Ore dopo la data di ritiro oltre le quali la richiesta diventa "non ritirata" |
| `booking.overdue_grace_hours` | int | `0` | ✖ | ✖ | Ore di tolleranza dopo la scadenza prima di segnalare il ritardo |
| `booking.require_motivation` | bool | `true` | ✔ | ✖ | La motivazione è obbligatoria |
| `booking.motivation_min_length` | int | `20` | ✔ | ✖ | Lunghezza minima della motivazione (caratteri) |
| `booking.require_professor` | bool | `false` | ✔ | ✖ | Il docente di riferimento è obbligatorio |
| `booking.require_subject` | bool | `true` | ✔ | ✖ | La materia/corso è obbligatoria |
| `booking.cart_ttl_hours` | int | `72` | ✖ | ✖ | Ore dopo cui un carrello inattivo viene svuotato |
| `booking.auto_assign_units_on_pickup` | bool | `true` | ✖ | ✖ | Assegna automaticamente le unità al momento della consegna |

## 10.4 Group `regulations` (position 40)

| Key | Type | Default | Public | Null? | Description |
|---|---|---|:--:|:--:|---|
| `regulations.enforce_global_acceptance` | bool | `true` | ✔ | ✖ | Blocca l'uso della piattaforma finché i regolamenti globali non sono accettati |
| `regulations.enforce_checkout_acceptance` | bool | `true` | ✔ | ✖ | Richiedi l'accettazione dei regolamenti di prodotto/categoria al checkout |
| `regulations.reaccept_on_version_bump` | bool | `true` | ✖ | ✖ | Richiedi una nuova accettazione a ogni nuova versione |

## 10.5 Group `ldap` (position 50) — admin-only visibility

| Key | Type | Default | Secret | Null? | Description |
|---|---|---|:--:|:--:|---|
| `ldap.mode` | enum(`fake`,`real`) | `"fake"` | ✖ | ✖ | Modalità di autenticazione (l'env `LDAP_MODE` ha priorità) |
| `ldap.host` | string | `""` | ✖ | ✖ | Host del server LDAP |
| `ldap.port` | int | `389` | ✖ | ✖ | Porta |
| `ldap.encryption` | enum(`none`,`ssl`,`tls`) | `"none"` | ✖ | ✖ | Cifratura della connessione |
| `ldap.base_dn` | string | `"dc=polito,dc=it"` | ✖ | ✖ | Base DN per la ricerca utenti |
| `ldap.bind_dn` | string | `""` | ✖ | ✖ | DN dell'account di servizio (vuoto = bind anonimo) |
| `ldap.bind_password` | secret | `""` | **✔** | ✖ | Password dell'account di servizio |
| `ldap.user_filter` | string | `"(uid=%s)"` | ✖ | ✖ | Filtro di ricerca utente; `%s` = username |
| `ldap.attr_uid` | string | `"uid"` | ✖ | ✖ | Attributo username |
| `ldap.attr_email` | string | `"mail"` | ✖ | ✖ | Attributo email |
| `ldap.attr_first_name` | string | `"givenName"` | ✖ | ✖ | Attributo nome |
| `ldap.attr_last_name` | string | `"sn"` | ✖ | ✖ | Attributo cognome |
| `ldap.attr_display_name` | string | `"cn"` | ✖ | ✖ | Attributo nome visualizzato |
| `ldap.attr_matricola` | string | `"employeeNumber"` | ✖ | ✖ | Attributo matricola |
| `ldap.attr_groups` | string | `"memberOf"` | ✖ | ✖ | Attributo dei gruppi sull'utente |
| `ldap.group_base_dn` | string | `""` | ✖ | ✖ | Base DN per la ricerca gruppi (vuoto = usa `attr_groups`) |
| `ldap.group_filter` | string | `"(&(objectClass=groupOfNames)(member=%s))"` | ✖ | ✖ | Filtro gruppi; `%s` = DN utente |
| `ldap.timeout_seconds` | int | `5` | ✖ | ✖ | Timeout di connessione |
| `ldap.default_role` | enum(role) | `"student"` | ✖ | ✖ | Ruolo assegnato quando nessun gruppo corrisponde |
| `ldap.role_map` | json | see below | ✖ | ✖ | Mappa gruppo LDAP → ruolo applicativo |

`ldap.role_map` default (**order matters — first match wins**):
```json
{
  "cn=vlab-admin,ou=groups,dc=polito,dc=it": "admin",
  "cn=tecnici,ou=groups,dc=polito,dc=it": "technician",
  "cn=borsisti,ou=groups,dc=polito,dc=it": "assistant",
  "cn=studenti,ou=groups,dc=polito,dc=it": "student"
}
```
Matching is **case-insensitive** and succeeds if the LDAP group string *equals* the key OR the key is a bare `cn=xxx` prefix of the group DN. Validation: object with string keys and values in the role enum.

## 10.6 Group `security` (position 60) — admin-only visibility

| Key | Type | Default | Null? | Description |
|---|---|---|:--:|---|
| `security.jwt_ttl_minutes` | int | `480` | ✖ | Durata del token di accesso (minuti) |
| `security.jwt_refresh_ttl_days` | int | `14` | ✖ | Durata del token di rinnovo (giorni) |
| `security.jwt_issuer` | string | `"vlab"` | ✖ | Emittente dei token |
| `security.login_max_attempts` | int | `10` | ✖ | Tentativi di accesso consentiti per finestra |
| `security.login_window_minutes` | int | `15` | ✖ | Ampiezza della finestra anti-forza-bruta (minuti) |
| `security.audit_retention_days` | int | `730` | ✔ | Giorni di conservazione del registro attività; `null` = per sempre |

## 10.7 Group `notifications` (position 70)

Email sending is **not implemented in v1** (no SMTP dependency). The settings exist and the `NotificationService` writes a log line instead of sending; the frontend surfaces nothing. This keeps the schema stable for v1.1.

| Key | Type | Default | Description |
|---|---|---|---|
| `notifications.enabled` | bool | `false` | Abilita l'invio di email |
| `notifications.from_email` | string | `"noreply@polito.it"` | Mittente |
| `notifications.from_name` | string | `"Visionary Lab"` | Nome mittente |
| `notifications.staff_inbox` | string | `""` | Email dello staff per le nuove richieste |
| `notifications.events` | json | `["order.submitted","order.approved","order.rejected","order.overdue"]` | Eventi notificati |
| `notifications.reminder_days_before_return` | int | `1` | Giorni di anticipo per il promemoria di riconsegna |

## 10.8 Group `ui` (position 80)

| Key | Type | Default | Public | Description |
|---|---|---|:--:|---|
| `ui.primary_color` | string | `"#002B49"` | ✔ | Colore primario (blu Politecnico) |
| `ui.accent_color` | string | `"#F2A900"` | ✔ | Colore d'accento |
| `ui.highlight_color` | string | `"#00C2CB"` | ✔ | Colore secondario (accento VR/cinema) |
| `ui.locale` | string | `"it-IT"` | ✔ | Lingua dell'interfaccia |
| `ui.date_format` | string | `"dd/MM/yyyy"` | ✔ | Formato data visualizzato |
| `ui.items_per_page` | int | `24` | ✔ | Elementi per pagina nel catalogo |
| `ui.catalog_default_view` | enum(`grid`,`list`) | `"grid"` | ✔ | Vista predefinita del catalogo |
| `ui.show_unit_codes_to_students` | bool | `false` | ✔ | Mostra le sigle delle unità agli studenti |
| `ui.allow_anonymous_catalog` | bool | `true` | ✔ | Consenti la consultazione del catalogo senza login |
| `ui.hero_image_url` | string | `""` | ✔ | Immagine di sfondo della homepage |
| `ui.banner_enabled` | bool | `false` | ✔ | Mostra un avviso in cima al sito |
| `ui.banner_message_it` | string | `""` | ✔ | Testo dell'avviso |
| `ui.banner_level` | enum(`info`,`warning`,`danger`) | `"info"` | ✔ | Tipo di avviso |
| `ui.footer_note_it` | string | `"© Politecnico di Torino"` | ✔ | Nota nel footer |

## 10.9 Group `stats` (position 90)

| Key | Type | Default | Description |
|---|---|---|---|
| `stats.default_range_days` | int | `90` | Intervallo predefinito delle statistiche (giorni) |
| `stats.default_granularity` | enum(`day`,`week`,`month`) | `"week"` | Granularità predefinita dei grafici |
| `stats.top_products_limit` | int | `10` | Numero di prodotti nella classifica |

## 10.10 Settings contract notes

- `SettingsSeeder` is **idempotent**: it upserts metadata (`type`, `group`, labels, `is_public`, `nullable`, `options`, `position`) for every key and inserts `value` **only when the row does not exist**. Running `seed` on an existing DB never resets an admin's configuration.
- Every consumer reads through `SettingsRepository::get()`. **Grepping the backend for a hardcoded `7`, `24`, `"09:00"` etc. in domain code must return nothing.**
- Changing `hours.*`, `booking.*` or `closures` never retroactively invalidates existing orders. Already-approved orders stay valid; only new checks use the new values.

---

# 11. Frontend specification

## 11.1 Stack & tooling

- React **18**, TypeScript **strict** (`strict: true`, `noUncheckedIndexedAccess: true`), Vite 5.
- `react-router-dom` v6 (data-router not required; plain `<Routes>` is fine).
- Server state: **TanStack Query v5** (`@tanstack/react-query`). No Redux. Local UI state with `useState`/`useReducer`; auth + settings in React Context.
- Forms: controlled components + a small `useForm` hook. **Do not** add a form library.
- Dates: `date-fns` + `date-fns/locale/it`.
- Charts: **Recharts**. No other chart lib.
- Markdown rendering: `react-markdown` + `remark-gfm`, with **HTML disabled** (never `rehype-raw`) — regulation bodies are staff-authored but must not be able to inject scripts.
- Styling: **CSS Modules + CSS custom properties** (`*.module.css`). No Tailwind, no CSS-in-JS runtime. Global tokens in `src/styles/tokens.css`.
- Icons: `lucide-react`.
- Tests: **Vitest** + `@testing-library/react` + `@testing-library/user-event` + `msw` (Mock Service Worker) for API mocking. `jsdom` environment.

`vite.config.ts` (required behaviour):
```ts
server: {
  port: 8080,
  strictPort: true,
  host: true,
  proxy: { '/api': { target: 'http://localhost:8081', changeOrigin: true } }
}
```
The SPA calls `/api/v1/...` with **relative** URLs only. `VITE_API_BASE_URL` may override the base for non-proxied deployments; default `''`.

## 11.2 API client contract

`src/api/client.ts` exports `apiFetch<T>(path, init)` which:
1. Prefixes `import.meta.env.VITE_API_BASE_URL ?? ''` + path.
2. Adds `Authorization: Bearer <accessToken>` when a token is in memory.
3. Serializes JSON bodies and sets `Content-Type` (except for `FormData`).
4. On `401` with `code ∈ {token_expired, token_stale}`: attempts **exactly one** refresh (de-duplicated via a module-level promise), then retries the original request once. On refresh failure: clears auth state and redirects to `/login`.
5. On any non-2xx: throws `ApiError { status, code, message, details, traceId }`. Components render `error.message` directly (it is already Italian).
6. Never throws on `POST /availability/check` non-2xx handling differences — that endpoint returns 200 always.

Tokens: the **access token lives in memory only**; the **refresh token in `localStorage`** under `vlab.refresh_token`. On app boot, if a refresh token exists, the app calls `/auth/refresh` before rendering routes (shows a full-page splash). Rationale: no access token at rest, survives reloads, no cookie/CSRF machinery.

`src/types/api.ts` mirrors §7.4 exactly. Every interface name matches the resource name (`User`, `Category`, `ProductSummary`, `Product`, `ProductUnit`, `ProductLog`, `OrderItem`, `OrderSummary`, `Order`, `OrderEvent`, `Setting`, `Closure`, `Regulation`, `Violation`, `AvailabilityCheckResponse`, `AvailabilityDatesResponse`, `Paginated<T>`, `ApiErrorBody`).

## 11.3 Route map

Guard legend: `public` · `auth` · `student` · `staff` (= technician | assistant | admin) · `technician+` (= technician | admin) · `admin`.
Every guarded route that fails redirects to `/login?next=<path>` (unauthenticated) or `/403` (wrong role).

| Route | Page component | Guard | Data (endpoints) | Notes |
|---|---|---|---|---|
| `/` | `HomePage` | public | `GET /settings/public`, `GET /categories`, `GET /products?featured=true&per_page=8` | Dark hero, category tiles, featured gear, "Come funziona" 3-step strip, CTA to catalog |
| `/login` | `LoginPage` | public | `POST /auth/login` | Username/password, LDAP mode badge when `fake`, dev credentials hint shown only when `health.ldap_mode === 'fake'` |
| `/catalogo` | `CatalogPage` | public¹ | `GET /products` or `GET /availability/products` (when a date range is active), `GET /categories`, `GET /brands` | The main browse view. Sidebar facets, search, grid/list toggle, sort, pagination, **date-range picker** that switches the query to the availability endpoint |
| `/catalogo/:categorySlug` | `CatalogPage` | public¹ | as above with `category_slug` | Same component, category pre-filtered |
| `/prodotto/:slug` | `ProductDetailPage` | public¹ | `GET /products/{slug}`, `GET /products/{id}/availability`, `GET /products/{id}/logs` | Gallery, specs table, availability calendar, recommended accessories carousel, regulation warnings, public log timeline, "Aggiungi al carrello" |
| `/disponibilita` | `AvailabilityFinderPage` | public¹ | `POST /availability/dates`, `GET /calendar/opening` | **Products → dates** flow: pick products, get a calendar heat-map of feasible windows |
| `/carrello` | `CartPage` | student | `GET /cart`, `PATCH/DELETE /cart/items/*`, `PUT /cart/dates`, `POST /availability/check` | Item list with per-item availability chips, date/slot pickers, live limit warnings |
| `/carrello/checkout` | `CheckoutPage` | student | `POST /availability/check`, `POST /orders`, `POST /me/regulations/{id}/accept` | Form (materia, motivazione, docente, note), required-regulation acceptance blocks, `exceeds_limits` confirm dialog |
| `/ordini` | `MyOrdersPage` | auth | `GET /orders?scope=mine` | Status filter chips, cards with status timeline |
| `/ordini/:id` | `OrderDetailPage` | auth (own) / staff | `GET /orders/{id}`, `GET /orders/{id}/events`, `POST /orders/{id}/cancel` | Big status banner, item list, event timeline, cancel button when allowed |
| `/regolamento` | `RegulationsPage` | public | `GET /regulations` | List of published regulations, grouped by scope |
| `/regolamento/:slug` | `RegulationDetailPage` | public | `GET /regulations/{slug}` | Markdown render or embedded PDF (`<object data="/api/v1/regulations/{id}/file?token=…">`) |
| `/regolamento/accetta` | `AcceptRegulationsPage` | auth | `GET /me/regulations/pending`, `POST /me/regulations/{id}/accept` | **Interstitial**: rendered instead of any other route while blocking global regulations are pending |
| `/profilo` | `ProfilePage` | auth | `GET /auth/me`, `PATCH /auth/me` | Personal data (read-only from LDAP), phone/course editable, accepted-regulations list, quota widget |
| `/gestione` | `StaffDashboardPage` | staff | `GET /stats/overview`, `GET /orders?status=pending`, `GET /orders/calendar` | Operational dashboard: pending queue, today's pickups/returns, overdue alerts |
| `/gestione/ordini` | `StaffOrdersPage` | staff | `GET /orders`, transition endpoints | Table with filters, bulk-free single-row actions, drawer for detail |
| `/gestione/ordini/:id` | `StaffOrderDetailPage` | staff | `GET /orders/{id}`, all transition endpoints, `POST /products/{id}/logs` | Approve/reject/pickup/return workflows, unit assignment UI, return inspection with log creation |
| `/gestione/calendario` | `StaffCalendarPage` | staff | `GET /orders/calendar`, `GET /calendar/opening` | Month grid of pickups/returns/closures |
| `/gestione/prodotti` | `AdminProductsPage` | technician+ | `GET /products?status=*`, `DELETE /products/{id}` | Table with search/filter, quick status toggle |
| `/gestione/prodotti/nuovo` | `ProductFormPage` | technician+ | `POST /products`, `GET /categories` | Full create form incl. initial units and recommendations |
| `/gestione/prodotti/:id` | `ProductFormPage` | technician+ | `GET/PUT /products/{id}`, unit + log + recommendation endpoints | Tabs: Dati · Immagini · Unità · Consigliati · Registro |
| `/gestione/categorie` | `AdminCategoriesPage` | technician+ | categories CRUD | Inline editing, drag-free `position` number input |
| `/gestione/registro` | `ProductLogsPage` | staff | `GET /logs` (endpoint #86), `POST /products/{id}/logs` | Cross-product log feed with type/severity/unresolved filters and a "nuova voce" dialog |
| `/gestione/regolamenti` | `AdminRegulationsPage` | technician+ | regulations CRUD, publish, upload, `GET /regulations/{id}/acceptances` | Markdown editor with preview, PDF upload, target picker |
| `/gestione/chiusure` | `AdminClosuresPage` | technician+ | closures CRUD | List + form; warns about `affected_orders` |
| `/gestione/statistiche` | `StatsPage` | staff | `/stats/*` | Full for technician/admin; **limited** variant for assistant (only overview-operational, late-returns, my-activity) |
| `/gestione/utenti` | `AdminUsersPage` | staff | `GET /users`, `PUT /users/{id}` (admin only) | Role editing UI visible only with `permissions['users.manage']` |
| `/gestione/utenti/:id` | `UserDetailPage` | staff | `GET /users/{id}`, `GET /users/{id}/orders` | |
| `/gestione/impostazioni` | `SettingsPage` | staff (read) / admin (write) | `GET /settings`, `PUT /settings`, `POST /settings/ldap/test` | Tabbed by `group`; inputs rendered from `type`; save-all with dirty tracking; LDAP test button |
| `/gestione/audit` | `AuditLogPage` | admin | `GET /audit-logs` | |
| `/403` | `ForbiddenPage` | public | – | |
| `*` | `NotFoundPage` | public | – | |

¹ When `ui.allow_anonymous_catalog` is `false`, these routes become `auth`.

## 11.4 Cross-cutting frontend behaviour

**`AuthProvider`** exposes `{ user, permissions, pendingRegulations, isLoading, login, logout, refresh }`. `useAuth()` and `usePermission('orders.manage')` are the only ways components ask about authorization. **No component compares `user.role` to a string** except the debug banner.

**`SettingsProvider`** loads `GET /settings/public` once at boot and exposes `useSetting('booking.max_loan_days')`. Colors from `ui.primary_color` / `ui.accent_color` / `ui.highlight_color` are written to `document.documentElement.style` as `--color-primary` etc. at boot, so admin-set branding actually applies.

**Regulation gate.** A `<RegulationGate>` wrapper inside the authenticated layout: if `pendingRegulations.some(r => r.blocking)`, render `<AcceptRegulationsPage>` regardless of the current route (except `/logout`, `/regolamento/*`).

**Cart badge.** Item count comes from `GET /auth/me` (`cart_items_count`) at boot and is thereafter kept in sync by the TanStack Query cache for `['cart']` — every cart mutation returns the full cart object, so the badge derives from the cache with zero extra requests.

**Error surface.** A global `<Toaster>` renders `ApiError.message`. `422 validation_failed` errors additionally map `details` onto the form's field errors via a shared `applyFieldErrors(form, details)` helper.

**Loading.** Skeleton components (not spinners) for catalog grid, product detail, order list, stats cards. Mutations disable their button and show an inline spinner.

**Empty states.** Every list has a designed empty state with an illustration-free icon + Italian copy + a primary action.

**Accessibility (required, testable).** Semantic landmarks (`header`/`nav`/`main`/`footer`); one `<h1>` per page; all interactive elements keyboard-reachable with a visible `:focus-visible` ring; modals trap focus, close on `Esc`, and restore focus; form inputs have `<label for>`; error text linked with `aria-describedby`; status changes announced via an `aria-live="polite"` region; images have `alt`; contrast ≥ 4.5:1 for body text; date pickers usable via keyboard with a text input fallback (`gg/mm/aaaa`); `prefers-reduced-motion` disables all transitions.

**Responsive.** Breakpoints `sm 480`, `md 768`, `lg 1024`, `xl 1280`. Mobile: single-column catalog, bottom-anchored cart bar on product pages, hamburger nav, staff tables collapse to cards below `md`. No horizontal page scroll at 320px.

## 11.5 Key component inventory (shared)

`AppShell` (header + nav + banner + footer) · `RoleNav` · `Button` (variants `primary|secondary|ghost|danger`, sizes `sm|md|lg`) · `Badge` / `StatusBadge` (maps order/product/unit status → color + Italian label from `/meta/enums`) · `Card` · `Modal` · `Drawer` · `Toast` · `DataTable` (sortable headers, responsive card fallback) · `Pagination` · `SearchInput` (debounced 300 ms) · `FacetSidebar` · `ProductCard` · `ProductGrid` · `QuantityStepper` · `DateRangePicker` (with closure/weekday disabling driven by `/calendar/opening`) · `TimeSlotPicker` · `AvailabilityCalendar` (heat-map, 3 states: disponibile / parziale / non disponibile / chiuso) · `AvailabilityBadge` · `LimitWarningList` (renders `Violation[]`, soft = warning amber, hard = danger red) · `OrderStatusTimeline` · `OrderActions` (renders buttons from `allowed_actions`) · `UnitAssignmentTable` · `ReturnInspectionForm` · `MarkdownView` · `PdfViewer` · `RegulationAcceptBlock` (scrollable body + "Ho letto e accetto" checkbox that is disabled until scrolled to bottom) · `StatCard` · `ChartCard` · `SettingField` (renders by `Setting.type`) · `EmptyState` · `Skeleton` · `ConfirmDialog`.

## 11.6 The two booking flows (exact UX)

**Flow A — dates first (`/catalogo`).**
1. User opens the catalog and sets a date range in the sticky filter bar (`DateRangePicker`, disabled days from `/calendar/opening`).
2. The page switches its query from `GET /products` to `GET /availability/products?start_date&end_date`.
3. Every card shows `AvailabilityBadge` = `available_quantity` ("3 disponibili" / "Non disponibile in queste date").
4. `include_unavailable=false` by default with a toggle "Mostra anche non disponibili".
5. Adding to cart carries the range: the cart's dates are set via `PUT /cart/dates` if the cart has none, otherwise the user is asked to confirm overwriting.

**Flow B — products first (`/disponibilita`).**
1. User adds products to the cart (or to a scratch list on this page) with quantities.
2. Chooses a desired duration (`duration_days`) and a search horizon.
3. `POST /availability/dates` returns `days` + `windows`; the page renders a month heat-map from `days` and a ranked list of `windows` where `all_available = true`.
4. Clicking a window writes it to the cart via `PUT /cart/dates` and navigates to `/carrello`.
5. Windows that are unavailable show which products block them (`blocking_product_ids` → product names), with a "rimuovi dal carrello" shortcut.

Both flows converge on `/carrello` → `/carrello/checkout`, which continuously calls `POST /availability/check` (debounced 400 ms) and renders `LimitWarningList`. The submit button is disabled while `can_submit === false`; when `exceeds_limits === true` the button label becomes *"Invia comunque"* and opens a `ConfirmDialog` explaining that the request may be rejected, which then posts with `acknowledge_exceeds_limits: true`.

---

# 12. Design system

## 12.1 Direction

**Institutional Politecnico di Torino, modernized, with a restrained VR/cinema flavour.**

- The **hero and staff dashboards are dark** (deep Polito blue → near-black gradient), evoking a screening room / VR void; content surfaces are **light** and calm so the catalog stays legible and printable.
- Rectangular, precise geometry — small radii (4–8 px), 1 px hairline borders, generous white space. Nothing bubbly.
- One accent (Polito orange) for primary actions, one highlight (cyan) reserved for *availability/VR* semantics only. Never both on the same element.
- Photography-forward: equipment images on neutral surfaces, `object-fit: contain` on a subtle checkered-free grey, never cropped in a way that loses the gear's silhouette.
- Micro-interaction budget: 150–200 ms ease-out transitions on hover/focus, a single subtle lift on cards. No parallax, no autoplay carousels (the legacy site's `owl.carousel` is not to be reproduced).

## 12.2 Tokens (`src/styles/tokens.css`)

```css
:root {
  /* Brand */
  --color-primary:        #00284B;  /* blu Politecnico */
  --color-primary-700:    #013A61;
  --color-primary-500:    #0A5A8A;
  --color-primary-300:    #4E8FB5;
  --color-primary-050:    #E8F0F6;

  --color-accent:         #EF7B02;  /* arancio — azioni primarie */
  --color-accent-600:     #C98A00;
  --color-accent-050:     #FFF6E0;

  --color-highlight:      #00C2CB;  /* ciano VR — solo disponibilità */
  --color-highlight-050:  #E0FAFB;

  /* Neutrals */
  --color-ink:            #0E1620;
  --color-ink-muted:      #4A5765;
  --color-ink-subtle:     #6E7B8A;
  --color-line:           #D8DEE5;
  --color-line-strong:    #B8C2CD;
  --color-surface:        #FFFFFF;
  --color-surface-alt:    #F4F6F9;
  --color-surface-sunken: #E9EDF2;

  /* Dark surfaces (hero, staff shell) */
  --color-dark:           #001A2E;
  --color-dark-alt:       #00253F;
  --color-dark-line:      #12354F;
  --color-on-dark:        #EAF1F7;
  --color-on-dark-muted:  #9DB2C4;

  /* Semantic */
  --color-success:        #1E8E5A;
  --color-success-050:    #E4F5EC;
  --color-warning:        #B5730B;
  --color-warning-050:    #FDF3E2;
  --color-danger:         #C0392B;
  --color-danger-050:     #FBEAE8;
  --color-info:           #0A5A8A;
  --color-info-050:       #E8F0F6;

  /* Typography */
  --font-sans: "Roboto", "Segoe UI", system-ui, -apple-system, sans-serif;
  --font-display: "Poppins", "Roboto", system-ui, sans-serif;
  --fs-xs: 0.75rem;  --fs-sm: 0.875rem; --fs-md: 1rem;
  --fs-lg: 1.125rem; --fs-xl: 1.375rem; --fs-2xl: 1.75rem;
  --fs-3xl: 2.25rem; --fs-4xl: 3rem;
  --lh-tight: 1.2; --lh-normal: 1.5; --lh-loose: 1.7;
  --fw-regular: 400; --fw-medium: 500; --fw-semibold: 600; --fw-bold: 700;

  /* Space (4px scale) */
  --sp-1: 0.25rem; --sp-2: 0.5rem;  --sp-3: 0.75rem; --sp-4: 1rem;
  --sp-5: 1.5rem;  --sp-6: 2rem;    --sp-7: 3rem;    --sp-8: 4rem;

  /* Radii & elevation */
  --radius-sm: 4px; --radius-md: 6px; --radius-lg: 10px; --radius-pill: 999px;
  --shadow-sm: 0 1px 2px rgba(0,27,45,.08);
  --shadow-md: 0 4px 12px rgba(0,27,45,.10);
  --shadow-lg: 0 12px 32px rgba(0,27,45,.16);

  /* Layout */
  --container-max: 1280px;
  --header-height: 64px;
  --transition: 180ms cubic-bezier(.2,.8,.3,1);
}
```
Fonts are **self-hosted** in `public/fonts/` (Poppins 300–700 for headings/UI + Roboto variable for body copy, woff2, `font-display: swap`). **No Google Fonts CDN** — the legacy site's external font/analytics/Facebook/Twitter includes are explicitly not reproduced. No third-party analytics, no social SDKs.

`--color-primary` and `--color-accent` are overwritten at runtime from `GET /settings/public`; everything else is fixed.

## 12.3 Status colour mapping (binding)

| Status | Token | Usage |
|---|---|---|
| `pending` | `--color-warning` on `--color-warning-050` | In attesa |
| `approved` | `--color-info` on `--color-info-050` | Approvato |
| `picked_up` | `--color-primary` on `--color-primary-050` | Ritirato |
| `overdue` | `--color-danger` on `--color-danger-050` | In ritardo |
| `returned` | `--color-success` on `--color-success-050` | Restituito |
| `returned_late` | `--color-warning` on `--color-warning-050` | Restituito in ritardo |
| `rejected` / `cancelled` / `no_show` | `--color-ink-subtle` on `--color-surface-sunken` | Chiusi senza prestito |
| `draft` | `--color-ink-subtle` | Bozza |
| Availability: disponibile | `--color-highlight` | Chip/heat-map |
| Availability: parziale | `--color-accent` | |
| Availability: non disponibile | `--color-danger` | |
| Availability: chiuso | `--color-surface-sunken` + diagonal hatch | |

Status is **never communicated by colour alone** — every badge carries its Italian label, and the availability heat-map uses distinct fill patterns for the "chiuso" state.

## 12.4 Layout anatomy

- **Header** (64 px, sticky, light with a 1 px bottom hairline; dark variant on the homepage hero until scroll): Polito-style wordmark + `lab.name`, nav (`Catalogo`, `Disponibilità`, `Regolamento`, and for staff `Gestione`), right side: cart button with badge (students), user menu.
- **Homepage hero**: full-bleed dark gradient (`--color-dark` → `#000C16`) with an optional `ui.hero_image_url` at 18% opacity and a subtle horizontal scan-line overlay (2 px repeating-linear-gradient at 3% white) for the cinema flavour. `h1` in `--font-display`, 3rem desktop / 2rem mobile. Two CTAs: primary "Esplora il catalogo", ghost "Verifica disponibilità".
- **Catalog**: 12-column grid; sidebar 280 px on `lg+`, collapsing into a `Drawer` below `lg`. Product cards 4-up on `xl`, 3-up on `lg`, 2-up on `md`, 1-up below.
- **Product detail**: 2-column on `lg+` (gallery 58% / info 42%), single column below; sticky "Aggiungi al carrello" panel on desktop, sticky bottom bar on mobile.
- **Staff area**: dark left rail (72 px collapsed / 240 px expanded) on `lg+`, top tab bar below; content on `--color-surface-alt`.
- **Footer**: `--color-dark`, three columns (lab contacts from settings, quick links, Politecnico attribution), `ui.footer_note_it`.

## 12.5 Copy tone

Formal-but-friendly Italian, `tu` form ("Aggiungi al carrello", "La tua richiesta è stata inviata"). Dates as `gg/mm/aaaa`, times as `HH:MM`. Currency never appears. Statuses always use the labels from `/meta/enums`.

---

# 13. Testing requirements

## 13.1 Backend — PHPUnit

`phpunit.xml`: bootstrap `tests/bootstrap.php`, testsuites `Unit` and `Feature`, `APP_ENV=test`, `DB_DRIVER=sqlite`, `DB_DATABASE=:memory:`, `LDAP_MODE=fake`, `JWT_SECRET=test-secret-at-least-32-characters-long`.

`tests/TestCase.php` must provide: a fresh in-memory SQLite DB with all migrations + `SettingsSeeder` per test (`setUp`), helpers `actingAs(User|string $roleOrUser)`, `json(string $method, string $uri, array $body = [])` returning a decoded array + status, `seedProduct(array $overrides = [], int $units = 3)`, `seedOrder(array $overrides = [])`, `setSetting(string $key, mixed $value)`, `travelTo(string $datetime)`.

**Minimum coverage: 80% line coverage on `src/Domain/`.** The following cases are mandatory.

### Auth (`tests/Feature/AuthTest.php`, `tests/Unit/RoleResolverTest.php`)
1. Login with each seeded fake user returns 200 and the expected `user.role`.
2. Login with a wrong password returns `401 invalid_credentials`.
3. Login with an unknown username returns `401 invalid_credentials` (identical body to #2 — no user enumeration).
4. Login for a user with `is_active = false` returns `403 account_disabled`.
5. 11 failed logins inside the window return `429 too_many_attempts`.
6. `GET /auth/me` without a token → `401 unauthenticated`; with a malformed token → `401 token_invalid`; with an expired token → `401 token_expired`.
7. Bumping `users.token_version` invalidates an existing access token → `401 token_stale`.
8. Refresh rotates: old refresh token is revoked; presenting it again → `401 refresh_reused` **and** the whole family is revoked.
9. Logout revokes the presented refresh token; a subsequent refresh → `401`.
10. `RoleResolver`: group → role mapping for each of the 4 default map entries.
11. `RoleResolver`: `role_locked = true` keeps the local role even when LDAP groups say otherwise.
12. `RoleResolver`: no matching group falls back to `ldap.default_role`.
13. `RoleResolver`: matching is case-insensitive and matches a bare `cn=` prefix.
14. First login creates the user; second login updates `email`/`display_name`/`last_login_at` without duplicating.
15. `RealLdapAuthenticator` reads **every** parameter from settings (assert via a settings-backed fake transport / reflection; no network).
16. `LdapUnavailableException` surfaces as `503 ldap_unavailable`.
17. JWT claims contain `sub`, `role`, `uid`, `ver`, `exp` and honour `security.jwt_ttl_minutes`.

### Availability engine (`tests/Unit/AvailabilityServiceTest.php`, `tests/Feature/AvailabilityTest.php`)
18. Capacity counts only units with `status='available'`; maintenance/missing/retired/internal_use are excluded.
19. `products.status != 'available'` forces capacity 0.
20. A single overlapping `approved` order reduces availability by its quantity.
21. Orders in `rejected`/`cancelled`/`returned`/`returned_late`/`no_show`/`draft` do **not** reduce availability.
22. `pending` reduces availability when `booking.pending_locks_stock = true` and does not when `false`.
23. Bottleneck logic: two orders on different days inside the requested range → availability equals capacity minus the **max** overlap, not the sum.
24. Boundary: an order ending exactly on the requested start date **does** overlap (inclusive ranges).
25. `booking.buffer_days_between_loans = 2` extends the block by 2 days past the due date.
26. `exclude_order_id` removes an order's own reservation.
27. Availability never returns a negative number.
28. `GET /availability/products` filters out products with `available_quantity < min_quantity` and includes them when `include_unavailable=true`.
29. `POST /availability/dates` returns dense `days` (one per calendar day) and `windows` of exactly `duration_days`.
30. `POST /availability/dates` omits windows whose pickup or return day is closed.
31. `POST /availability/dates` reports `blocking_product_ids` correctly for a multi-product cart.
32. `first_available_window` is `null` when nothing fits.
33. Concurrency: two simultaneous checkouts for the last unit — one succeeds, the other gets `409 insufficient_availability` (simulate by invoking the service twice inside/around a transaction).
34. Performance guard: `POST /availability/dates` with 20 products over 180 days issues ≤ 4 SQL queries (assert with a query-count listener).

### Calendar (`tests/Unit/CalendarServiceTest.php`)
35. A weekday marked `closed` is not bookable.
36. A date inside a closure with `blocks_pickup = true` is not a valid pickup date but *is* a valid return date when `blocks_return = false`.
37. `is_recurring_yearly` closures match the same month/day in a different year.
38. Slot generation respects `hours.slot_duration_minutes` and multiple windows per weekday.
39. Empty `pickup_windows` falls back to `hours.weekly` open/close.
40. `min_advance_days`/`max_advance_days` bound the booking window.
41. DST boundary: a range spanning the last Sunday of March produces the correct number of days.

### Order limits (`tests/Unit/LimitsEvaluatorTest.php`)
42. Duration over `max_loan_days` → **soft** `max_loan_days_exceeded`.
43. Duration over `max_loan_days_hard_cap` → **hard**.
44. `max_orders_per_month = null` (infinite) skips the check entirely.
45. `max_orders_per_month = 2` with 2 existing orders in the month → soft violation; orders in another month don't count; `cancelled`/`rejected` don't count.
46. `max_orders_per_year` behaves analogously.
47. `max_active_orders` counts only `pending|approved|picked_up|overdue`.
48. `max_items_per_order` and `max_quantity_per_product_per_order` are **hard**.
49. `booking.allow_exceeding_limits = false` turns every soft violation into a hard one.
50. A product-level `max_loan_days` narrower than the global setting wins.
51. `on_site_only` product with `pickup_date != return_date` → hard `on_site_only_multi_day`.
52. `POST /orders` with soft violations and `acknowledge_exceeds_limits = false` → `422 limit_violation`; with `true` → `201` and `exceeds_limits = true`, `limit_violations` persisted.

### Order state machine (`tests/Unit/OrderStateMachineTest.php`, `tests/Feature/OrderTransitionTest.php`)
53. Every allowed transition of §8.2 succeeds for every permitted role (table-driven test over the full matrix).
54. Every **disallowed** (from-status, action) pair returns `409 invalid_transition` (table-driven over the complement).
55. A student cannot approve, reject, pickup, return, no-show or reopen → `403`.
56. An assistant **can** approve/reject/pickup/return/no-show but **cannot** reopen → `403`.
57. A technician cannot reopen → `403`; an admin can.
58. A student can cancel their own `pending` order but not another student's → `403`.
59. A student cannot cancel an `approved` order inside the `cancellation_deadline_hours` window → `409`; can outside it.
60. `return` before the due date → `returned`, `late_days` null; after → `returned_late` with the correct `late_days`.
61. Returning from `overdue` yields `returned_late`.
62. `refreshOverdue()` moves a past-due `picked_up` order to `overdue` and writes a `system` event.
63. `no_show_grace_hours` moves an uncollected `approved` order to `no_show`.
64. Every transition writes exactly one `order_events` row with the right `from_status`, `to_status`, `action`, `actor_id`, `actor_role`.
65. `allowed_actions` in the JSON matches `OrderStateMachine::allowedActions` for each of the 4 roles across all 10 statuses.
66. Pickup with explicit `assignments` writes `order_item_units`; wrong unit count → `422`; a unit already on another active order → `409 unit_in_use`.
67. Pickup without `assignments` and `auto_assign_units_on_pickup = true` auto-assigns the lowest labels.
68. Return with `condition_in = 'damaged'` sets the unit to `maintenance`; `'missing'` sets it to `missing`.
69. Return with `logs[]` creates `product_logs` rows carrying `order_id`.
70. Order `code` is `VL-{year}-{0001}` and increments per year; concurrent creates do not collide (transactional test).
71. Approve re-validates availability and returns `409` when stock vanished meanwhile.

### Settings (`tests/Feature/SettingsTest.php`, `tests/Unit/SettingsRepositoryTest.php`)
72. Seeder creates every key of §10 with the documented type/group/default.
73. Re-running the seeder does **not** overwrite a modified value but does add newly introduced keys.
74. `GET /settings/public` returns only `is_public` keys, no auth, and never a secret.
75. `GET /settings` hides the `ldap` and `security` groups and all secrets from technicians and assistants.
76. `PUT /settings` requires admin (`403` for technician and assistant).
77. `PUT /settings` with an unknown key → `422 unknown_setting_key`; nothing is written (atomicity assertion).
78. Type validation: string into an `int` key → `422`; `null` into a non-nullable key → `422`; `null` into a nullable key → `200`.
79. `hours.weekly` shape validation rejects 6 entries, duplicate weekdays, `open >= close`.
80. Secrets: `PUT` with `"********"` leaves the stored value unchanged; `GET` always redacts.
81. Changing `booking.max_loan_days` immediately changes `POST /availability/check` output (no caching across requests).
82. Every domain service reads its constants from settings — a smoke test that sets 5 different settings and asserts observable behaviour changes.

### Regulations (`tests/Feature/RegulationTest.php`)
83. A global regulation appears in `pending_regulations` on login for a user who never accepted it.
84. Accepting it clears it from `GET /me/regulations/pending`.
85. Publishing with `bump_version` makes it pending again for everyone.
86. Accepting with a stale `version` → `409 conflict`.
87. Re-accepting the same version is idempotent (no duplicate row, `200`).
88. Checkout with a cart containing a product in a regulated category and no acceptance → `409 regulation_acceptance_required` with `details.regulation_ids`.
89. Checkout with `accepted_regulation_ids` covering them → `201`, and `regulation_acceptances` rows carry `order_id`.
90. A regulation with `requires_acceptance = false` never blocks checkout.
91. An `is_active = false` or unpublished regulation never blocks anything and is hidden from students (`404` on detail for students, `200` for staff).
92. `POST /availability/check` lists the required regulations with the correct `accepted` flag.
93. Product-scoped and category-scoped regulations both resolve (and de-duplicate when both apply).
94. PDF upload rejects a non-PDF (`415`) and an oversized file (`413`); `GET /regulations/{id}/file` streams with the right headers and accepts `?token=`.
95. Only technicians/admins may create; assistants get `403`; only admins may delete.

### Catalog & permissions (`tests/Feature/ProductTest.php`, `CategoryTest.php`, `PermissionMatrixTest.php`)
96. Product create with `initial_units: 5` generates 5 units labelled `01`..`05`.
97. Product create with an explicit `units` array honours serials/asset codes/dates.
98. `PUT /products/{id}` with `images` replaces the collection and re-syncs `products.image_url`.
99. `DELETE /products/{id}` is refused (`409`) while a locking order references it.
100. `DELETE /categories/{id}` is refused (`409 category_not_empty`) with products attached.
101. Unit delete is refused (`409 unit_in_use`) while assigned to an active order.
102. Recommended products: self-recommendation → `422`; replacement semantics verified.
103. Students never receive `units`, `serial_number`, `asset_code`, `location`, or non-public logs; staff do.
104. `ui.show_unit_codes_to_students = true` exposes `{id,label,status}` only.
105. **Table-driven permission test**: for every (role × endpoint) pair in §9.2, assert the expected 2xx / 403. This single test is the enforcement mechanism for the permission matrix and must be exhaustive over the endpoint index of §7.5.
106. Anonymous access to catalog endpoints returns 200 when `ui.allow_anonymous_catalog = true` and 401 when `false`.
107. Product log creation by an assistant succeeds; product creation by an assistant → `403`.
108. A `critical` `damage` log sets the referenced unit to `maintenance`.

### Statistics (`tests/Feature/StatsTest.php`)
109. `stats/overview` for an assistant has `scope: "limited"` and no `totals`/`inventory` keys; for a technician `scope: "full"` with all blocks.
110. `stats/loans-over-time` emits dense buckets including zero-activity ones, with correct `bucket` keys for `day`/`week`/`month`.
111. `stats/top-products` respects `limit` and the `metric` parameter; `utilization` maths verified on a fixture.
112. `stats/by-category` `share` values sum to 1.0 (±0.005) when there is data, and the endpoint returns zeros (not an error) with no data.
113. `stats/late-returns` counts both `returned_late` and currently `overdue` orders and computes `avg_late_days`.
114. `stats/my-activity` only reflects the calling user's events.
115. Assistants get `403` on `loans-over-time`, `top-products`, `by-category`, `utilization`, `export`.
116. `stats/export?dataset=orders` returns CSV with the exact documented header row, a BOM, and CRLF endings.

### Cart, errors, infrastructure
117. `GET /cart` lazily creates exactly one draft order; calling it twice does not create a second.
118. Adding the same product twice increments quantity instead of creating a second row.
119. Cart item quantity beyond `max_quantity_per_product_per_order` → `422`.
120. Submitting the cart empties it (the draft becomes the order; a new `GET /cart` yields a fresh empty draft).
121. Drafts older than `booking.cart_ttl_hours` are pruned.
122. A staff account gets `403 role_required` on `POST /cart/items` and `POST /orders`.
123. Every error response matches the §7.3 envelope (schema assertion helper used across the suite).
124. `404` on an unknown route and `405` on a wrong method return the envelope, not an HTML page.
125. `GET /health` reports `ldap_mode`, `database.connected: true` and the applied migration count.
126. `GET /meta/enums` contains every enum of Appendix A with a non-empty Italian label for each value.
127. Migrations run cleanly from scratch and `migrate:fresh` is idempotent.
128. The `CatalogSeeder` imports `data/catalog.json`, is idempotent (re-running does not duplicate categories/products), and creates `quantity` units per product.

## 13.2 Frontend — Vitest + Testing Library

`vitest.config.ts`: `environment: 'jsdom'`, `setupFiles: ['src/test/setup.ts']`, `globals: true`, coverage via `v8` with thresholds `lines 70 / functions 70 / branches 60`. `src/test/setup.ts` installs `msw` handlers and `@testing-library/jest-dom`.

All API interaction is mocked with **msw**; no test hits a real backend. A shared `src/test/fixtures.ts` provides typed fixtures matching §7.4 exactly — **these fixtures are the frontend team's contract sample data and must be written first, before any component.**

Mandatory tests:

**API client**
1. Attaches the `Authorization` header when a token exists and omits it otherwise.
2. On `401 token_expired` performs one refresh and retries the original request once.
3. Two concurrent 401s trigger exactly one refresh call.
4. A failed refresh clears auth state and redirects to `/login`.
5. Non-2xx responses throw `ApiError` carrying `code`, `message`, `details`.

**Auth & guards**
6. `LoginPage` submits credentials, stores the refresh token, and navigates to `next` (or `/`).
7. Invalid credentials render the Italian error message from the response.
8. `RequireAuth` redirects an anonymous visitor from `/carrello` to `/login?next=/carrello`.
9. `RequireRole` renders `/403` for a student hitting `/gestione`.
10. `usePermission('products.manage')` is false for an assistant and true for a technician.
11. `RegulationGate` renders the acceptance screen when a blocking global regulation is pending, and lets the app through once accepted.
12. Boot with a stored refresh token calls `/auth/refresh` before rendering routes.

**Catalog**
13. `CatalogPage` renders a grid from a mocked paginated response and shows the total count.
14. Typing in the search box debounces and issues one request with `q`.
15. Selecting a category updates the query params and the URL.
16. Setting a date range switches the request from `/products` to `/availability/products` with `start_date`/`end_date`.
17. `AvailabilityBadge` shows "3 disponibili" / "Non disponibile" for the respective `available_quantity`.
18. Pagination changes the `page` param and scrolls to top.
19. Empty results render the empty state with its CTA.
20. Error state renders a retry affordance.

**Product detail**
21. Renders name, brand, specs table, and gallery from the fixture.
22. Recommended accessories are listed and link to their product pages.
23. A product with `has_required_regulations` shows the warning callout (VR epilepsy case).
24. Public product logs render as a timeline; private ones are absent from the student fixture.
25. "Aggiungi al carrello" calls `POST /cart/items` and updates the header badge.
26. A product with `status: 'maintenance'` disables the add-to-cart button with an explanatory label.

**Cart & checkout (highest-value area)**
27. `CartPage` lists items with quantities and lets the user change a quantity (`PATCH`).
28. Removing an item calls `DELETE` and updates the list.
29. Setting dates calls `PUT /cart/dates` and triggers an availability check.
30. `LimitWarningList` renders soft violations in amber and hard ones in red, using the `message` from the response.
31. Submit is **disabled** while `can_submit === false`.
32. With `exceeds_limits === true` the submit button reads "Invia comunque" and opens the confirm dialog; confirming posts `acknowledge_exceeds_limits: true`.
33. Required regulations render as acceptance blocks; the checkbox is disabled until the body is scrolled to the end; submit is blocked until all are checked.
34. A successful checkout navigates to `/ordini/{id}` and shows the confirmation state.
35. A `409 insufficient_availability` response renders the per-product message and offers to re-check dates.
36. A `422 validation_failed` response maps `details` onto the correct form fields.
37. `TimeSlotPicker` renders only slots returned by the check response and marks the selected one.

**Availability finder (products → dates)**
38. Submitting products + duration calls `POST /availability/dates` with the right body.
39. The heat-map renders one cell per day with the correct availability class, and closed days are visually distinct.
40. Available windows are listed; clicking one calls `PUT /cart/dates` and navigates to `/carrello`.
41. Unavailable windows name the blocking products.
42. `first_available_window: null` renders the "nessuna finestra disponibile" empty state.

**Orders**
43. `MyOrdersPage` filters by status chips and shows `StatusBadge` labels from `/meta/enums`.
44. `OrderDetailPage` renders the event timeline in chronological order.
45. `OrderActions` renders exactly the buttons in `allowed_actions` and nothing else (table-driven across roles/statuses).
46. Cancel opens a confirm dialog and posts to `/cancel`.
47. A rejected order displays `rejection_reason` prominently.
48. Staff order detail shows `staff_notes`; the student fixture (which lacks the field) renders without crashing.

**Staff workflows**
49. `StaffOrdersPage` renders the queue and approving a row posts to `/approve` and optimistically updates the row's status.
50. Reject requires a non-empty reason before the submit button enables.
51. The pickup dialog validates that the number of selected units equals the item quantity.
52. The return inspection form can attach a damage log and posts both in one `POST /return` body.
53. `ProductFormPage` create-mode posts the full payload including `initial_units`; edit-mode pre-fills and PUTs.
54. `SettingsPage` renders inputs by `Setting.type` (number, switch, text, select, time, JSON editor), tracks dirty state, and PUTs only changed keys.
55. `SettingsPage` is read-only (all inputs disabled, no save button) for a technician fixture and editable for admin.
56. `StatsPage` renders the full dashboard for a technician and the limited variant for an assistant without requesting the forbidden endpoints.

**Shared components & a11y**
57. `DateRangePicker` disables closed days and closures from a `/calendar/opening` fixture and is operable by keyboard.
58. `Modal` traps focus, closes on `Esc`, and restores focus to the trigger.
59. `StatusBadge` maps every order status to its Italian label.
60. `Pagination` disables prev on page 1 and next on the last page.
61. Every page component renders exactly one `<h1>`.
62. `DataTable` collapses to cards below the `md` breakpoint (assert via a matchMedia mock).

## 13.3 Definition of done (both teams)

- `composer test` (backend) and `npm test` (frontend) pass with zero failures and zero skipped tests.
- `composer lint` runs `php -l` over `src/` plus PHP-CS-Fixer in `--dry-run` (PSR-12).
- `npm run lint` (ESLint, `@typescript-eslint` recommended + `react-hooks`) and `npm run typecheck` (`tsc --noEmit`) pass with zero errors.
- `./run.sh` on a clean clone brings up a working, seeded system reachable at `http://localhost:8080` where `student1/password` can log in, browse, book, and `tecnico1/password` can approve.

---

# 14. run.sh contract

A single POSIX-`bash` script at `/home/user/vlab/run.sh`, executable (`chmod +x`). It must work on Linux, macOS, and Windows Git Bash. **Implementation comes later; this section is the acceptance criteria.**

## 14.1 Invocation

```
./run.sh [command] [options]
```

| Command | Behaviour |
|---|---|
| *(none)* / `start` | Full flow: check prerequisites → install deps if missing → prepare DB → migrate → seed → start both servers → wait |
| `install` | Dependencies only |
| `migrate` | Migrations only |
| `seed` | Seeders only |
| `fresh` | Drop the SQLite file, re-migrate, re-seed, then start |
| `backend` | Backend only, on 8081 |
| `frontend` | Frontend only, on 8080 |
| `test` | Run backend PHPUnit then frontend Vitest; exit non-zero if either fails |
| `stop` | Kill processes recorded in the PID files |
| `help` | Usage text |

| Option | Effect |
|---|---|
| `--backend-port N` | override 8081 (also updates the Vite proxy target via `VITE_API_TARGET`) |
| `--frontend-port N` | override 8080 |
| `--no-install` | skip dependency installation |
| `--no-seed` | skip seeding |
| `--fresh` | same as the `fresh` command |
| `--real-ldap` | set `LDAP_MODE=real` instead of the default `fake` |
| `-h`, `--help` | usage |

## 14.2 Required behaviour, in order

1. `set -euo pipefail`. Resolve the script's own directory portably (`cd "$(dirname "$0")" && pwd`) and use it as the repo root — the script must work from any CWD.
2. **Prerequisite check.** Verify `php` ≥ 8.1, `composer`, `node` ≥ 18, `npm`. For each missing/too-old tool print a red, actionable message (including the install hint per OS) and exit `1`. Verify the PHP extensions `pdo`, `pdo_sqlite`, `mbstring`, `json`, `openssl`; warn (do not fail) if `ldap` is missing, since `LDAP_MODE=fake` does not need it.
3. **OS detection** for path/`sed`/`open` differences (`uname -s`: `Linux`, `Darwin`, `MINGW*|MSYS*|CYGWIN*`). On Git Bash, prefer `winpty` only if the terminal requires it and never rely on `lsof`.
4. **`.env` bootstrap.** If `backend/.env` is missing, copy `backend/.env.example` and generate a random 48-char `JWT_SECRET` (from `openssl rand -hex 24`, falling back to `head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'`). Never overwrite an existing `.env`.
5. **Dependency install (idempotent).**
   - Backend: if `backend/vendor/autoload.php` is absent **or** `composer.lock` is newer than `vendor`, run `composer install --no-interaction --prefer-dist` in `backend/`.
   - Frontend: if `frontend/node_modules` is absent **or** `package-lock.json` is newer than `node_modules`, run `npm ci` in `frontend/` (falling back to `npm install` when no lockfile exists).
   - Skipped entirely with `--no-install`.
6. **Database.** Ensure `backend/database/` exists; create the SQLite file if missing (`touch`). On `fresh`, delete it first. Run `php bin/console migrate` (must be idempotent — already-applied migrations are skipped).
7. **Seed.** Run `php bin/console seed` unless `--no-seed`. The seeder must be idempotent and must import `data/catalog.json` when present (printing a warning, not failing, when absent).
8. **Port availability.** Before binding, check each port. If occupied, print which port and how to override, then exit `1`. Detection must be portable: try `lsof -i :PORT` → fall back to `ss -ltn` → fall back to `netstat -an` → fall back to a bash `/dev/tcp` probe. **At least one method must work on all three platforms.**
9. **Start the backend**: `php -S 127.0.0.1:8081 -t backend/public backend/public/index.php` with `LDAP_MODE=fake` (or `real` with `--real-ldap`) exported. Redirect stdout/stderr to `backend/storage/logs/server.log` **and** echo through with a `[backend]` prefix. Record the PID in `.run/backend.pid`.
   > The front controller must handle the `php -S` static-file routing quirk: when `PHP_SELF` refers to an existing file, return `false` early; otherwise route.
10. **Wait for readiness**: poll `GET http://127.0.0.1:8081/api/v1/health` for up to 30 s (curl, 0.5 s interval). Abort with a clear error and the tail of the log if it never becomes healthy.
11. **Start the frontend**: `npm run dev -- --port 8080 --strictPort` in `frontend/`, prefixing output with `[frontend]`. Record `.run/frontend.pid`.
12. **Print a summary block**: frontend URL, backend URL, health URL, active LDAP mode, DB driver + path, and the **seeded credentials table** (`student1/password`, `tecnico1/password`, `borsista1/password`, `admin1/password`).
13. **Signal handling**: `trap 'cleanup' INT TERM EXIT` where `cleanup` kills both PIDs (and their process groups where supported), removes the PID files, and exits cleanly. `Ctrl-C` must never leave orphan `php`/`node` processes.
14. **Foreground wait**: `wait` on both children; if either exits non-zero, kill the other and exit with that status.
15. Exit codes: `0` success/clean shutdown, `1` prerequisite or port failure, `2` install failure, `3` migration/seed failure, `4` backend failed to become healthy.
16. All output goes through helper functions `info`, `ok`, `warn`, `err` that colourize **only when stdout is a TTY** (`[ -t 1 ]`) and respect `NO_COLOR`.
17. `.run/` and `backend/storage/logs/` must be in `.gitignore`.

## 14.3 Non-goals for run.sh

No Docker, no HTTPS, no process supervisor, no MySQL/PostgreSQL provisioning (those are configured by hand in `.env`), no `sudo`, no global package installs.

---

# 15. Seed data & catalog import

## 15.1 `data/catalog.json`

Shape produced by the scraper (already agreed):

```json
{
  "categories": [
    { "slug": "audio", "name": "Audio", "position": 10 },
    { "slug": "tecnologie-interattive", "name": "Tecnologie Interattive", "position": 70 }
  ],
  "products": [
    {
      "name": "Microfono Mezzo Fucile Rode NTG4",
      "category_slug": "audio",
      "brand": "Rode",
      "model": "NTG4",
      "description": "Microfono shotgun a condensatore…",
      "image_url": "https://prestitimultimedia.polito.it/foto/Rode_NTG4.jpg",
      "quantity": 2,
      "source_notes": "Elenco risorse DAUIN & ICMC"
    }
  ]
}
```

Field handling by `CatalogSeeder`:

| JSON field | Target | Rules |
|---|---|---|
| `categories[].slug` | `categories.slug` | match key; required |
| `categories[].name` | `categories.name` | required |
| `categories[].position` | `categories.position` | default: index × 10 |
| `products[].name` | `products.name` | required; **slug generated** from it (kebab-case, accent-folded, uniquified) |
| `products[].category_slug` | `products.category_id` | must resolve; unknown slug ⇒ the category is created on the fly with `position = 999` and a warning is printed |
| `products[].brand` / `model` | same columns | nullable |
| `products[].description` | `products.description` | nullable |
| `products[].image_url` | `products.image_url` + one `product_images` row at position 0 | nullable; must be `http(s)://` |
| `products[].quantity` | **N `product_units` rows**, labels `01..NN`, `status='available'` | missing/0/negative ⇒ 1 |
| `products[].source_notes` | `products.source_notes` | nullable |

The seeder is **idempotent**: it upserts categories by `slug` and products by `slug`; on an existing product it updates the descriptive fields and **adds missing units up to `quantity`** but never deletes units (they may carry serials and history). It prints a summary: `Categorie: 9 (3 nuove) · Prodotti: 412 (12 nuovi) · Unità: 1187 (34 nuove)`.

Expected real categories (use these `slug`/`name`/`position` when generating the file):

| slug | name | position |
|---|---|---|
| `audio` | Audio | 10 |
| `audio-accessori-e-cavi` | Audio - Accessori e Cavi | 20 |
| `hardware-e-software` | Hardware e Software | 30 |
| `luci-accessori-fondali` | Luci - Accessori - Fondali | 40 |
| `materiale-elettrico` | Materiale Elettrico | 50 |
| `supporti` | Supporti | 60 |
| `tecnologie-interattive` | Tecnologie Interattive | 70 |
| `video` | Video | 80 |
| `video-accessori-e-cavi` | Video - Accessori e Cavi | 90 |

Suggested `icon` values: `audio`, `cable`, `hardware`, `light`, `electric`, `support`, `vr`, `video`, `cable`.

## 15.2 `SettingsSeeder`

Creates every key of §10 with the documented metadata and default. Idempotent per §10.10.

## 15.3 `FakeUsersSeeder`

Inserts the 5 rows of §4.2 into `fake_ldap_users` **and** the corresponding `users` rows (so staff appear in `/users` before their first login), with `role_source = 'seed'` and `role_locked = false`. Runs only when `APP_ENV != 'production'`; in production it prints a skip notice.

## 15.4 `RegulationsSeeder`

Creates three published regulations:

1. `regolamento-generale` — scope `global`, `requires_acceptance: true`, version 1, markdown body containing the loan rules (durations, responsibilities, damages, sanctions), referencing settings values in prose. Title: *"Regolamento per il prestito delle attrezzature"*.
2. `avvertenze-vr` — scope `category`, target = `tecnologie-interattive`, `requires_acceptance: true`, markdown body with the **epilepsy/photosensitivity warning**, hygiene, minimum age, session-length recommendations. Title: *"Avvertenze per l'uso dei visori VR"*.
3. `uso-attrezzature-video` — scope `category`, target = `video`, `requires_acceptance: false`, informational care instructions. Title: *"Cura e trasporto delle attrezzature video"*.

## 15.5 `ClosuresSeeder`

Two rows: *Chiusura estiva* (August 8–23 of the current year) and *Festività natalizie* (Dec 24 – Jan 6, spanning years), both blocking pickup and return.

## 15.6 `DemoOrdersSeeder` (dev only, run by `seed --demo`)

Creates ~25 orders for `student1`/`student2` spread over the last 120 days across every status, including 2 `returned_late`, 1 `overdue`, 1 `pending`, 1 `approved` with a future pickup, plus matching `order_events`, a few `order_item_units` assignments, and 6 `product_logs`. This exists so the statistics dashboards and the staff queue are non-empty on a fresh install. **Not run by default** — `./run.sh` calls plain `seed`; `./run.sh seed --demo` adds it.

---

# Appendix A — enumerations

All values are lowercase snake_case. Italian labels come from `GET /meta/enums`; the frontend must never hardcode them.

| Enum | Values |
|---|---|
| `role` | `student`, `technician`, `assistant`, `admin` |
| `order_status` | `draft`, `pending`, `approved`, `rejected`, `cancelled`, `picked_up`, `overdue`, `returned`, `returned_late`, `no_show` |
| `order_action` | `submit`, `approve`, `reject`, `cancel`, `pickup`, `return`, `mark_no_show`, `mark_overdue`, `reopen`, `note`, `edit` |
| `actor_type` | `user`, `system` |
| `product_status` | `available`, `maintenance`, `retired` |
| `unit_status` | `available`, `maintenance`, `missing`, `retired`, `internal_use` |
| `loan_mode` | `takeaway`, `on_site_only` |
| `log_type` | `damage`, `maintenance`, `inspection`, `note`, `loss`, `repair` |
| `log_severity` | `info`, `warning`, `critical` |
| `condition` | `ok`, `damaged`, `incomplete`, `missing` |
| `regulation_scope` | `global`, `category`, `product` |
| `regulation_content_type` | `markdown`, `pdf` |
| `recommendation_relation` | `accessory`, `alternative`, `required_with` |
| `setting_type` | `string`, `int`, `bool`, `json`, `time`, `date`, `enum`, `secret` |
| `setting_group` | `lab`, `hours`, `booking`, `regulations`, `ldap`, `security`, `notifications`, `ui`, `stats` |
| `violation_severity` | `soft`, `hard` |
| `violation_code` | `max_loan_days_exceeded`, `max_loan_days_hard_cap_exceeded`, `max_orders_per_month_exceeded`, `max_orders_per_year_exceeded`, `max_active_orders_exceeded`, `max_items_per_order_exceeded`, `max_quantity_per_product_exceeded`, `advance_window_violated`, `date_not_bookable`, `slot_not_available`, `insufficient_availability`, `on_site_only_multi_day`, `regulation_acceptance_required` |
| `stats_granularity` | `day`, `week`, `month` |
| `banner_level` | `info`, `warning`, `danger` |

---

# Appendix B — Italian UI glossary

Use exactly these terms; consistency matters more than elegance.

| English concept | Italian UI term |
|---|---|
| Catalog | Catalogo |
| Equipment / gear | Attrezzature |
| Category | Categoria |
| Product | Prodotto / Attrezzatura |
| Unit (physical item) | Unità |
| Serial number | Numero di serie |
| Asset code | Codice inventario |
| Purchase date | Data di acquisto |
| Inspection date | Data di collaudo |
| Cart | Carrello |
| Booking / loan request | Richiesta di prestito |
| Loan | Prestito |
| Pickup | Ritiro |
| Return | Riconsegna |
| Due date | Data di riconsegna prevista |
| Availability | Disponibilità |
| Opening hours | Orari di apertura |
| Closure / holidays | Chiusure / Ferie |
| Time slot | Fascia oraria |
| Subject / course | Materia |
| Motivation | Motivazione |
| Professor | Docente di riferimento |
| Notes | Note |
| Staff notes | Note interne |
| Regulation | Regolamento |
| Accept the regulation | Accetto il regolamento |
| Approve | Approva |
| Reject | Respinga → **use "Rifiuta"** |
| Rejection reason | Motivo del rifiuto |
| Cancel (an order) | Annulla richiesta |
| Mark as picked up | Segna come ritirato |
| Mark as returned | Segna come restituito |
| Late | In ritardo |
| Log entry | Voce di registro |
| Damage | Danno |
| Maintenance | Manutenzione |
| Statistics | Statistiche |
| Settings | Impostazioni |
| User | Utente |
| Student | Studente |
| Technician | Tecnico |
| Scholarship assistant | Borsista |
| Administrator | Amministratore |
| Exceeds limits | Fuori limite |
| Recommended accessories | Accessori consigliati |
| On-site use only | Solo in sede |

---

**End of specification.** Version 1.0 — frozen. Changes require a spec revision agreed by both teams.

