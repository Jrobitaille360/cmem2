# Root Cause — Premium locked on Windows, works on Android

Produced by 3-layer parallel investigation (Flutter + Backend + Infra).
No code changed. Fix requires plan approval.

---

## Verdict: two root causes, one primary

### Root Cause 1 — PRIMARY (backend)

**`PuzzleRouteHandler::requireDeviceToken()` has no path to Stripe subscriptions.**

Android flow: device_token → `device.purchase_token` → `Subscription::findActiveByPurchaseToken()` ✓

Windows flow: device_token → `device.user_id` (NULL — never set for Windows devices) →
lookup skipped → `$sub = null` → `requirePremium()` returns 403.

The Windows Stripe subscription IS correctly stored in `subscriptions` table (by `user_id`),
but puzzle endpoints authenticate via device_token only — no JWT context available — so
`findActive(user_id, 'puzzle')` is never called.

Evidence:

- `src/puzzle/Routing/PuzzleRouteHandler.php:325-335` — dual lookup checks
  `$device['user_id']` (NULL for Windows) then `$device['purchase_token']` (NULL for Stripe)
- `src/puzzle/Models/PuzzleDevice.php:23-43` — `upsert()` never sets `user_id`; no
  method exists to link device to user after JWT login
- `src/auth_groups/Models/Subscription.php:75-85` — `findActive()` requires `user_id`
  parameter; no fallback by Stripe subscription ID in puzzle context

### Root Cause 2 — SECONDARY (Flutter client)

**`getSubscriptionStatus()` on Windows requires JWT; throws `UserNotLoggedInException`
silently if not logged in, leaving `isPremium=false`.**

Evidence:

- `lib/services/api_service.dart:102` — `userAuth: _userJwt != null` evaluates false when
  JWT absent
- `lib/services/api_service.dart:353` — throws `UserNotLoggedInException` when
  `userAuth=true && _userJwt == null`
- `lib/services/purchase_service.dart:106-113` — bare `catch (_)` silently absorbs the
  exception; `isPremium.value` stays `false`

Android never hits this path: Play Billing writes `isPremium` directly via
`_deliverProduct()` without going through `getSubscriptionStatus()`.

---

## Infra — Not a root cause (but watch list)

- `.htaccess` Authorization header passthrough confirmed correct (lines 6-8)
- `PUZZLE_DEBUG_PREMIUM` confirmed dev-only; should not differ between platforms in prod
- No CORS or SSL issue found
- **Risk:** if `mod_rewrite` disabled on prod Apache, `HTTP_AUTHORIZATION` empty →
  device_token auth fails entirely for all platforms

---

## Impact

| Platform | Purchase path | Premium check path | Result |
| - | - | - | - |
| Android | Google Play → `purchase_token` stored on device | `findActiveByPurchaseToken()` | ✓ Works |
| iOS | App Store → similar to Android | same | ✓ Works |
| Windows | Stripe → `user_id` stored in `subscriptions` | `device.user_id` NULL → no lookup | ✗ Broken |
| Web | Stripe → same | same | ✗ Broken |

---

## Fix outline (pending plan approval)

Two independent fixes needed — either alone only partially resolves:

**Fix A (backend):** After device_token validation, if `device.user_id` is set, fall back
to `Subscription::findActive(device.user_id, 'puzzle')`. Requires device-to-user linking
first (see Phase C of `PLAN_subscription-hardening.md`).

**Fix B (backend — faster):** Add endpoint `POST /puzzle/auth/link-device` accepting JWT
+ device_token → sets `puzzle_devices.user_id`. Call this from Windows client on login.
Then Fix A works immediately.

**Fix C (Flutter):** On Windows login success, call `link-device` endpoint, then
re-fetch subscription status.

**Fix D (Flutter):** Replace bare `catch (_)` in `getSubscriptionStatus()` with explicit
`on UserNotLoggedInException` — show login prompt instead of silently locking premium.

Do NOT fix before tests written and plan approved.
