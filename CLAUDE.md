# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Dev server

```bash
composer serve
# → php -S localhost:8080 index.php
```

### Tests

Run a single module:

```bash
php private/tests/test_items.php
php private/tests/test_quiz.php
php private/tests/test_calendars.php
php private/tests/test_pomo.php
php private/tests/test_tags.php
php private/tests/test_groups.php
php private/tests/test_files.php
php private/tests/test_public.php
php private/tests/test_users.php
php private/tests/test_stats.php
php private/tests/test_plans.php
php private/tests/test_subscriptions.php
php private/tests/test_maintenance.php
php private/tests/test_secret_admin.php
php private/tests/test_puzzle_admin.php
php private/tests/test_puzzle_share.php
php private/tests/test_quiz2.php
php private/tests/test_quiz3.php
```

Run all tests:

```bash
php private/tests/run_all_tests.php
```

Tests execute real HTTP requests via cURL against a running server. Each test file includes `private/tests/test_new_base.php` for shared helpers (`callNewApi`, `testNewResult`, `printNewSection`).

### Database initialization

```bash
mysql -u root -p < docs/build_cmem2_DB.sql
```

## Architecture

This is a **modular REST API** built in PHP 8.0+ on a custom micro-framework (no Laravel/Symfony). All requests enter via `index.php`, which bootstraps the app and delegates to a `Router` instance.

### Boot sequence

`index.php` → `src/auth_groups/loader.php` (validates .env, creates runtime dirs, loads plugins) → `PluginManager` (registers plugin routes) → `Router` (dispatches request)

### Module layout

Each module lives under `src/` and follows the same internal pattern:

```tree
src/<module>/
  Controllers/   # Request handlers
  Models/        # PDO queries
  Services/      # Business logic
  Routing/       # Route registration
```

| Module | Namespace | Purpose |
| - | - | - |
| `src/auth_groups/` | `AuthGroups\` | Core: users, JWT auth, groups, files, tags, webhooks |
| `src/ics/` | `ICS\` | CalDAV/ICS calendar sync (RFC 5545) |
| `src/quiz/` | `Quiz\` | Real-time quiz sessions with participant tokens |
| `src/items/` | `Items\` | Generic item manager — private / public / shared |
| `src/pomo/` | `Pomo\` | Engagement waitlist and support forms |
| `src/puzzle/` | `Puzzle\` | Collaborative puzzle with pick/drop mechanics |
| `src/Core/` | `Core\` | `PluginInterface`, `PluginManager`, `AbstractPlugin` |

Plugins are activated in `.env` and loaded dynamically; they register their own routes through `PluginManager`.

### Authentication

JWT (HS256, 15-day expiry) + OTP (email codes). Anti-brute-force: 5 attempts per 10 min per email+IP. Rate limiting: 60 req/min (configurable). Device tokens enable persistent login.

### Response format

All endpoints return:

```json
{ "success": bool, "message": "...", "data": {}, "errors": [] }
```

Standard HTTP codes used: 200, 201, 401, 403, 404, 409, 422, 429.

### Configuration

All settings live in a single `.env` file (see `.env.example` for the full 170+ variable reference). `src/auth_groups/loader.php` validates required keys on startup.

## Conventions

- Namespaces: PascalCase matching module name (e.g., `AuthGroups\Controllers\UserController`)
- Methods: camelCase; DB columns: snake_case
- SQL via PDO with prepared statements — no ORM
- API docs per module: `docs/<module>/GUIDE.md` and `docs/<module>/API_*_ENDPOINTS.json`
- Version history: `CHANGELOG.md`; project roadmap: `docs/PLAN_GLOBAL_CMEM2.md`

## SQL migrations

- Pending migrations (between releases) go in `docs/` as `YYYYMMDD_description.sql`.
- **Never modify** a `build_DB-v-x-x-x.sql` that belongs to an already-released version.
- At the next version bump: integrate the pending `docs/*.sql` files into the new `build_DB-v-x-x-x.sql`, then move those files into `docs/v-x-x-x/`.

## Cross-Project Directives & Plans

- Before implementing changes that span multiple projects or have architectural implications, present a plan for approval FIRST. Do not apply DB migrations, code changes, or commits to production without explicit confirmation.
- When a user says 'pas le bon chemin' or rejects an approach, immediately revert all related commits before proposing a new direction.

## Personal Data & Memory

- Never auto-fill contact info, emails, or personal identifiers from MEMORY.md or persistent memory into public-facing pages, docs, or code. Always ask the user what value to use.

## Git & Release Discipline

- Never use `git add -f` to bypass .gitignore. If a file seems needed, ask the user.
- Do not create git tags until the corresponding PR is merged.
- Do not commit/push until tests pass locally; for PHP backend always run the full test suite before commit.

## Windows / File Editing

- Files in this environment use CRLF line endings. If the Edit tool fails, fall back to PowerShell line-by-line replacement rather than retrying Edit.

## Changelog Workflow

- After every user-visible change, update CHANGELOG.md before committing. Inspect git history if unsure what to include. Disable MD013 (line length) rather than wrapping changelog entries.
