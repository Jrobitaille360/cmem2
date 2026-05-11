# /release — Full Release Orchestrator

Orchestrates a complete release. STOP and ask at every gate listed below.
Never skip a gate. Never use `git add -f`. Never tag before PR merge.

---

## GATE 1 — Working tree & branch

```bash
git status
git branch --show-current
git log --oneline -5
```

Requirements:
- Branch must be `main` (or active release branch). If not, STOP and tell user.
- Working tree must be clean (no uncommitted changes). If dirty, STOP — do not
  stash silently. Show `git status` output and ask user to confirm or clean.
- If `release/vX.Y.Z` branch already exists, STOP and ask if resuming or
  starting fresh.

---

## GATE 2 — Full test suite

```bash
php private/tests/run_all_tests.php
```

- If ANY test fails: STOP. Show the failing test name and error. Do NOT continue
  to version bump or commit.
- SSL note: tests use cURL against localhost. Never add `-k` / `CURLOPT_SSL_VERIFYPEER=false`
  to fix a test failure — diagnose root cause instead.
- Only proceed when output confirms 0 failures.

---

## GATE 3 — Determine version bump

Read `CHANGELOG.md`. Find the `## [Unreleased` entry. Scan its contents:

- Contains `BREAKING` or `### Removed` → **major** bump
- Contains `### Added` or `### Changed` → **minor** bump
- Contains only `### Fixed` or `### Security` → **patch** bump

Read current version from `composer.json` (`"version"` field).
Compute new version. Show user: "Bumping X.Y.Z → A.B.C (patch/minor/major). Confirm?"
**Wait for explicit confirmation before writing any file.**

---

## GATE 4 — Update CHANGELOG.md

```bash
git log $(git describe --tags --abbrev=0)..HEAD --oneline
```

- Transform `## [Unreleased ...]` entry to `## [A.B.C] — YYYY-MM-DD`
- Add empty `## [Unreleased]` above it
- Group commits into Added / Changed / Fixed / Security sections
- Disable MD013 (line length) — do not wrap changelog lines
- Write CHANGELOG.md, then show diff. Wait for confirmation.

---

## GATE 5 — Bump version in files

Update ALL THREE — never only one:

1. `composer.json` → `"version": "A.B.C"`
2. `.env` → `APP_VERSION=A.B.C`
3. `.env.example` → `APP_VERSION=A.B.C`

Then verify no gitignored files crept into staging:

```bash
git status
git diff --name-only --cached
```

**If any file in `private/` or `.env` (not `.env.example`) appears staged: STOP.**
Never use `git add -f`. If a file seems needed, ask user.

---

## GATE 6 — Commit & push release branch

```bash
git checkout -b release/vA.B.C
git add composer.json .env.example CHANGELOG.md
git commit -m "chore: release vA.B.C"
git push -u origin release/vA.B.C
```

Then create draft PR:

```bash
gh pr create \
  --title "Release vA.B.C" \
  --body-file docs/v-A-B-C/PR_BODY.md \
  --base main \
  --head release/vA.B.C \
  --draft
```

If `docs/v-A-B-C/PR_BODY.md` does not exist, create it from the CHANGELOG entry.

Show PR URL. Then **STOP and wait for user to confirm PR is merged.**
Do NOT proceed to tagging until user explicitly says "PR merged" or "merged".

---

## GATE 7 — Tag & GitHub Release (ONLY after merge confirmed)

```bash
git checkout main
git pull origin main
git log --oneline -3
```

Verify the release commit is present on main. If not, STOP — do not tag.

```bash
git tag -a vA.B.C -m "Release vA.B.C"
git push origin vA.B.C

gh release create vA.B.C \
  --title "vA.B.C" \
  --notes-file docs/v-A-B-C/RELEASE_NOTES.md \
  --draft
```

---

## GATE 8 — Post-deploy smoke test

After production deployment (manual step, ask user to confirm deployed):

```bash
curl --fail --silent --show-error --max-time 10 \
  https://cmem2.journauxdebord.com/health
```

SSL rules:
- Never use `-k` / `--insecure`
- Never use `CURLOPT_SSL_VERIFYPEER = false`
- If SSL error: diagnose cert (expired? wrong domain?) — do not bypass

Expected: HTTP 200, `{"success":true}`. If not, report exact response and STOP.

---

## GATE 9 — Sync & summary

```bash
powershell -ExecutionPolicy Bypass -File private/sync.ps1
```

(Run only if `private/sync.ps1` exists.)

Post summary:

```
Release vA.B.C complete.
- Tests: N passed
- Tag: vA.B.C pushed
- PR: <url> (merged)
- GitHub Release: <url> (draft — publish manually)
- Smoke test: HTTP 200 OK
- Changelog: updated
```

---

## Lessons embedded (from past incidents)

| Incident | Rule enforced |
| - | - |
| Premature v1.1.4 tag before PR merge | GATE 6 hard-stops; tagging only in GATE 7 after explicit "merged" |
| `git add -f` force-added `private/` files | GATE 5 checks staged files; hook blocks `git add -f` at PreToolUse |
| Missing SSL verification in tests | GATE 2 and GATE 8 both call out no `-k` flag |
| `.env` committed accidentally | GATE 5 stops if `.env` (not `.env.example`) appears staged |
| Wrong version file updated | GATE 5 requires all three files updated together |
| sync.ps1 not run after release | GATE 9 explicitly calls it |
