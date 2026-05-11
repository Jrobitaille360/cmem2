# Plan — Auth, Subscription & Google Play Integration

Unified findings from parallel exploration of PHP API auth flow, Flutter subscription
client, and Play Console integration. No code changes applied yet.

---

## 1. Current State

### 1.1 PHP API — Auth System

Five independent auth flows coexist:

| Flow | Token | Lifetime | Use |
| - | - | - | - |
| JWT | HS256 Bearer | 15 days | cmem2 web/Windows users |
| OTP | 6-digit code | 15 min | Login step (email) |
| Device token | 64-char hex, SHA256 | 365 days | Puzzle app (mobile) |
| Stripe webhook | Signed payload | n/a | Web/Windows subscriptions |
| Google Play | purchase\_token | per-receipt | Android subscriptions |

Key files:

- `src/auth_groups/Services/JwtService.php` — issue, validate, blacklist
- `src/auth_groups/Services/OtpService.php` — generate, verify
- `src/auth_groups/Services/DeviceTokenService.php` — issue, replay-revoke
- `src/auth_groups/Services/SubscriptionService.php` — activate, expire, status
- `src/auth_groups/Models/Subscription.php` — upsert, findActive, markExpired
- `src/puzzle/Services/GooglePlayService.php` — OAuth2 + subscriptionsv2 verification
- `src/puzzle/Controllers/AuthController.php` — verify-subscription endpoint

**Subscription table:** `(user_id, app_id)` unique; fields: `is_premium`, `show_ads`,
`is_trial`, `trial_end`, `expires_at`, `provider`, `purchase_token`, `status`.

**Premium gating (puzzle):** dual lookup — by `user_id` if linked, else by
`purchase_token` for anonymous devices.

### 1.2 Flutter Client — Subscription Flow

**Project:** `c:\code\puzzle`
**Package:** `in_app_purchase: ^3.2.0`

Platform paths:

| Platform | Purchase | Verification |
| - | - | - |
| Android/iOS | Google Play Billing / App Store | `POST /puzzle/auth/verify-subscription` |
| Web/Windows | Stripe Checkout Session | `GET /subscription/status` (poll) |

State storage: `SharedPreferences` (`purchase_premium`, `purchase_show_ads`) +
`ValueNotifier<bool>` for reactive UI.

Premium gates active in client:

- Ad display (`ad_service.dart`)
- Piece rotation, double-tap rotation (`puzzle_screen.dart`)
- Exploit history, theme catalog (`image_selection_screen.dart`)
- Cloud backup/sync (`sync_service.dart`)

**Known gaps:**

1. Optimistic unlock on API failure (purchase\_service.dart:255) — premium enabled
   locally if verification call fails.
2. No explicit `expires_at` enforcement client-side — relies on API returning 401.
3. No subscription-cancelled UX — user cancels in Stripe portal, client must poll.
4. No retry logic on verification failure.

### 1.3 Google Play — Verification Flow

**API:** `androidpublisher/v3/.../subscriptionsv2/tokens/{token}`
**Auth:** Service account JWT → OAuth2 Bearer token (RS256, 1-hour)
**Config key:** `PUZZLE_GOOGLE_SERVICE_ACCOUNT_JSON` (path in `.env`)

Verification sequence:

1. Client sends `purchase_token` + `product_id` to `POST /puzzle/auth/verify-subscription`
2. `GooglePlayService::getAccessToken()` — signs JWT with service account private key,
   exchanges for OAuth Bearer
3. Call Google API (10s timeout, no retry)
4. Parse `subscriptionState`, `lineItems[0].expiryTime`, `offerTags` (trial detection),
   `obfuscatedExternalAccountId` (user linkage)
5. Handle `linkedPurchaseToken` — expire old subscription on upgrade/downgrade
6. `SubscriptionService::activatePremium()` — upsert to `subscriptions` table

**Error handling:** All failures collapse to null → 422. No distinction between
bad credentials (401) and bad token (404). No retry, no caching.

**Failure modes:**

| Failure | Handled |
| - | - |
| Missing service account file | Yes — null → 422 |
| OAuth token exchange 401 | Yes — null → 422 (no detail) |
| Google API timeout | Yes — null → 422 |
| Google API error field in JSON | Yes — null → 422 |
| Invalid RFC 3339 date (strtotime fails) | Partial — no explicit catch |
| Missing lineItems | Yes — null → 422 |

**No retry, no caching** — every verify call hits Google API directly.

---

## 2. Identified Issues

| # | Area | Issue | Risk |
| - | - | - | - |
| 1 | Flutter client | Optimistic unlock on API failure | User gets premium free if API unreachable |
| 2 | Flutter client | No `expires_at` enforcement | Expired sub stays active until next API call |
| 3 | GooglePlayService | No retry on transient failure | Valid purchase returns 422 if Google momentarily down |
| 4 | GooglePlayService | RFC 3339 date parse not guarded | PHP warning / null expiry on malformed date |
| 5 | GooglePlayService | All errors return same 422 | Cannot distinguish bad credentials from bad token |
| 6 | Subscription model | `user_id` nullable | Anonymous purchase cannot be migrated to account |
| 7 | Flutter client | No cancellation UX | User cancels Stripe sub, client never notified |
| 8 | Auth surface | 5 parallel auth flows, no shared middleware | Auth logic spread; easy to miss a gate |

---

## 3. Proposed Improvements (not yet prioritised)

### Phase A — Hardening (low risk, no schema change)

- A1: Guard `strtotime()` call in `GooglePlayService` — explicit fallback
- A2: Add 1-2 retry attempts on Google API timeout (exponential backoff, max 3 attempts)
- A3: Log distinct error codes from Google API (401 vs 404 vs 500) for ops visibility
- A4: Client: enforce `expires_at` locally — disable premium if cached expiry passed
  without re-verification

### Phase B — UX & Reliability

- B1: Client: replace optimistic unlock with "verifying…" loading state; only unlock
  on confirmed API success
- B2: Client: add subscription-expired/cancelled screen with renew CTA
- B3: Add background CRON endpoint to re-verify active Google Play tokens weekly
  (catch silent cancellations)

### Phase C — Account Linking

- C1: Allow anonymous device subscription to migrate to user account on login
  (currently `user_id` nullable but no migration path exists)
- C2: Add `GET /puzzle/auth/subscription-status` for client polling after Stripe
  portal return

---

## 4. Open Questions

1. Should optimistic unlock (Flutter) be removed entirely or kept with a shorter
   grace period?
2. Is account-linking (Phase C) in scope for v2.5.x or deferred to v2.6?
3. Should Google Play verification errors distinguish credential problems (ops alert)
   from invalid tokens (user-facing 422)?

---

## 5. Conditions for Approval

- [ ] Issues list reviewed and prioritised by user
- [ ] Phases A/B/C scope confirmed before any code change
- [ ] No DB migrations applied without explicit confirmation
- [ ] No commits until full test suite passes

