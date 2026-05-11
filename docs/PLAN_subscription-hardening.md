# Plan — Subscription & Auth Hardening

Pre-implementation plan. No source file touched until user approves.
Builds on findings in `docs/PLAN_auth-subscription-googleplay.md`.

---

## 1. Affected Components

### Backend — `c:\code\cmem2_API`

| File | Change needed |
| - | - |
| `src/puzzle/Services/GooglePlayService.php` | Guard RFC 3339 parse; add retry (max 3, backoff); log distinct Google error codes |
| `src/puzzle/Controllers/AuthController.php` | Surface distinct error types to client (bad credentials vs bad token) |
| `src/auth_groups/Services/SubscriptionService.php` | Add `migrateAnonymousToUser()` for account-linking (Phase C) |
| `src/auth_groups/Models/Subscription.php` | Add `findActiveByPurchaseToken()` if missing; add `reVerifyGooglePlay()` batch method |
| `src/cron/` (new file) | `reverify_google_play.php` — weekly re-verify active Google Play tokens |
| `private/tests/test_subscriptions.php` | Add cases: retry on timeout, RFC 3339 edge, account-linking migration |

### Flutter Client — `c:\code\puzzle`

| File | Change needed |
| - | - |
| `lib/services/purchase_service.dart` | Remove optimistic unlock; add `expires_at` local enforcement; add retry |
| `lib/services/api_service.dart` | Handle new error codes from backend (credential error vs invalid token) |
| `lib/screens/purchase_screen.dart` | Add subscription-expired/cancelled screen with renew CTA |
| `lib/services/sync_service.dart` | Re-check `expires_at` before auto-save, not just `isPremium` flag |

### No DB schema change in Phase A or B. Phase C requires one column addition (see §3)

---

## 2. Auth Model Differences

### Google Play (Android)

- Client sends `purchase_token` + `product_id` to `POST /puzzle/auth/verify-subscription`
- No user JWT required — device token suffices (anonymous purchase allowed)
- Backend calls Google subscriptionsv2 API with service account OAuth2
- `user_id` nullable in `subscriptions` — device identified via `purchase_token`
- Subscription validity = `subscriptionState: ACTIVE | IN_GRACE_PERIOD` + `expiryTime > NOW()`
- Silent cancellations not pushed to server — must poll Google or use RTDN

### Web / Windows (Stripe)

- Requires logged-in user (JWT) before purchase
- Checkout via `POST /subscription/checkout` → Stripe-hosted page → redirect
- Webhook (`stripe_sub_id`) updates `subscriptions` table server-side
- Client polls `GET /subscription/status` after Stripe redirect
- `user_id` always populated; no anonymous path
- Cancellations pushed via Stripe webhook — no polling needed

### JWT (cmem2 core users)

- HS256, 15-day expiry, no refresh token
- Used for web/Windows auth context (not puzzle device flow)
- Subscription check via `SubscriptionService::getStatus($userId, $appId)`
- Blacklist via `jti` in `JwtBlacklist` table

### Key difference summary

| Concern | Google Play | Stripe / Web | JWT |
| - | - | - | - |
| User identity | Optional (device token) | Required (JWT) | Required (JWT) |
| Expiry source | Google API | Stripe webhook | JWT `exp` field |
| Silent cancellation | Yes — must poll | No — webhook | n/a |
| Anonymous allowed | Yes | No | No |
| Backend credential | Service account JSON | Stripe secret key | `JWT_SECRET` |

---

## 3. Migration Steps

### Phase A — Backend hardening (no schema change, no Flutter change)

1. `GooglePlayService::validateSubscription()`:
   - Wrap `strtotime()` call — explicit `false` check, log + return null
   - Add retry loop: max 3 attempts, 500ms / 1000ms / 2000ms backoff on network failure
   - Log HTTP status code from Google separately (401 = credential problem, 404 = bad
     token, 5xx = Google outage)
2. `AuthController::verifySubscription()`:
   - Return distinct error codes: `CREDENTIAL_ERROR` (ops alert) vs
     `INVALID_PURCHASE_TOKEN` (user-facing)
3. `reverify_google_play.php` cron:
   - Query `subscriptions` where `provider='google_play'` AND `status='active'`
     AND `expires_at > NOW() - 7 days`
   - Re-call `GooglePlayService::validateSubscription()` per token
   - Mark expired if Google returns non-active state
4. Tests: add 3 new cases to `test_subscriptions.php`

### Phase B — Flutter client hardening (no schema change)

1. `purchase_service.dart`:
   - Remove optimistic unlock (lines 254–256)
   - Add `expires_at` field to SharedPreferences alongside `purchase_premium`
   - On app launch, check `expires_at < DateTime.now()` → set `isPremium = false`
     without waiting for API call
   - Add 1 retry on verify failure (network only, not 422)
2. `purchase_screen.dart`:
   - Add `SubscriptionExpiredScreen` widget shown when `isPremium=false` and
     `expires_at` exists in prefs (was previously premium)
   - Add "Restore Purchase" button calling `InAppPurchase.restorePurchases()`
3. Bump Flutter app version after Phase B changes

### Phase C — Account linking (one schema change, requires approval separately)

**Schema change (single migration file):**

```sql
-- docs/YYYYMMDD_subscription_account_link.sql
ALTER TABLE subscriptions
  ADD COLUMN anonymous_device_id VARCHAR(64) NULL AFTER user_id,
  ADD INDEX idx_sub_device (anonymous_device_id, app_id);
```

Steps after migration:

1. `SubscriptionService::migrateAnonymousToUser($deviceId, $userId, $appId)`:
   - Find active subscription by `anonymous_device_id`
   - Set `user_id`, clear `anonymous_device_id`
   - Merge if user already has subscription (keep later `expires_at`)
2. Call migration on successful login in `puzzle/Controllers/AuthController.php`
3. Add `GET /puzzle/auth/subscription-status` polling endpoint for post-Stripe-portal
   return

---

## 4. Rollback Strategy

### Phase A (backend only, no schema change)

- All changes isolated to `GooglePlayService.php`, `AuthController.php`, new cron file
- Rollback: `git revert` the Phase A commit
- No DB state affected — safe to revert at any time
- Cron: disable by commenting out cron entry; no cleanup needed

### Phase B (Flutter only, no schema change)

- SharedPreferences gains `purchase_expires_at` key — backward compatible (absent = no expiry cached)
- Old app version ignores new key — safe
- Rollback: revert Flutter commit; old behavior resumes on next app launch (SharedPrefs key ignored)

### Phase C (schema change)

**Warning:** Column addition on `subscriptions` is reversible but requires care.

- Forward: `ALTER TABLE subscriptions ADD COLUMN anonymous_device_id ...` — non-blocking on MySQL 8
- Rollback SQL:

  ```sql
  ALTER TABLE subscriptions DROP COLUMN anonymous_device_id;
  DROP INDEX idx_sub_device ON subscriptions;
  ```

- Rollback safe if: no data has been written to `anonymous_device_id` yet
- Rollback unsafe if: migration has already linked devices — data loss on device→user mapping
- **Phase C must not be deployed until Phase A + B are stable in production**

---

## 5. Conditions for Approval

- [ ] Phase scope confirmed (A only? A+B? all three?)
- [ ] Phase C schema change approved separately before SQL runs
- [ ] No source file edited until this plan is approved
- [ ] Full test suite passes before each phase commit
- [ ] CHANGELOG.md updated per phase, not per file
