# Changelog

All notable changes to AuthGroups API will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2025-10-07

### 🎉 Major Features
- **API Keys System**: Complete implementation for machine-to-machine authentication

### ✨ Added
- `ApiKey` model for key generation, validation, and management
- `ApiKeyController` for CRUD operations on API keys
- `ApiKeyAuthMiddleware` for API key authentication
- `ApiKeyRouteHandler` for API keys endpoints routing
- Database table `api_keys` with comprehensive tracking
- `ENDPOINTS_API_KEYS.md` - Complete API keys documentation
- Support for two key environments: `ag_live_*` (production) and `ag_test_*` (test)
- Scopes system: `read`, `write`, `delete`, `admin`, `*` (all)
- Rate limiting per key (per minute and per hour)
- Key expiration and automatic revocation
- Usage statistics per key (total requests, last used, last IP)
- Key regeneration endpoint
- Metadata and notes support for keys

### 🔐 Security
- SHA-256 hashing for API keys storage
- Only last 4 characters visible after creation
- API key shown only once upon creation
- Support for key revocation with reason tracking
- Automatic cleanup of expired keys

### 📡 API Endpoints
- `POST /api-keys` - Create new API key
- `GET /api-keys` - List user's API keys
- `GET /api-keys/{id}` - Get key details and statistics
- `DELETE /api-keys/{id}` - Revoke an API key
- `POST /api-keys/{id}/regenerate` - Regenerate an API key

### 🔄 Changed
- Updated `Router.php` to include `ApiKeyRouteHandler`
- Enhanced authentication to support both JWT and API keys
- Updated `LoggingMiddleware` to properly redact API keys

### 📝 Documentation
- Added API keys section to `README.md`
- Updated `API_REFERENCE.md` with API keys examples
- Updated `API_OVERVIEW.md` with ApiKeyRouteHandler and middleware
- Updated `API_ENDPOINTS.json` with API keys module (v1.3.0)
- Complete `ENDPOINTS_API_KEYS.md` with examples in JS, Python, PHP, Bash
- Added `API_KEYS_QUICK_REFERENCE.md` - Quick reference guide (531 lines)
- Added `API_KEYS_IMPLEMENTATION.md` - Implementation guide (405 lines)
- Added `API_KEYS_ARCHITECTURE.md` - Architecture documentation (583 lines)
- Added `MIGRATION_v1.3.0.md` - Migration guide from v1.2.x (478 lines)
- Added `QUICKSTART.md` - Quick start guide (267 lines)
- Added `API_KEYS_COMPLETION_SUMMARY.md` - Complete implementation summary
- Updated roadmap to mark API key setup as completed

### 🧪 Testing
- Added `tests/api_keys/test_api_keys_basic.php` - Complete test suite (23 tests)
- Added `tests/api_keys/check_table_exists.php` - Database verification
- Added `tests/api_keys/add_deleted_at_remote.php` - Migration helper
- All tests passing (23/23) ✅

## [1.2.0] - 2025-10-07

### 🎉 Major Changes
- **Rebranding**: Project renamed from "Collective Memories" to "AuthGroups API"
- **Namespace Update**: All `Memories\*` namespaces changed to `AuthGroups\*`
- **Focus Shift**: Removed memories and elements modules to focus on authentication and groups management

### ✨ Added
- Comprehensive `README.md` with full documentation
- `API_OVERVIEW.md` for detailed architecture documentation
- `API_ENDPOINTS_v2.json` with updated endpoint specifications
- `CHANGELOG.md` to track version history

### 🗑️ Removed
- Memories module (tables, controllers, routes, documentation)
- Elements module (tables, controllers, routes, documentation)
- Memory-related upload directories
- Documentation files:
  - `ENDPOINTS_MEMORIES.md`
  - `ENDPOINTS_ELEMENTS.md`
  - `create_proc_reset_memories_elements.sql`
  - `create_proc_reset_memories_elements_data.sql`
  - `create_triggers_memories_elements.sql`

### 🔄 Changed
- API name: "Collective Memories API" → "AuthGroups API"
- Email addresses: 
  - `noreply@memories.app` → `noreply@authgroups.local`
  - `support@memories.app` → `support@authgroups.local`
- Default table association for tags: `memories` → `groups`
- Valid tag tables: removed `memories` and `elements`, kept `groups`, `files`, `all`
- API description in all documentation
- Email templates to reflect new branding

### 🐛 Fixed
- Removed references to deprecated modules in:
  - Router configuration
  - PublicRouteHandler
  - TagController validation rules
  - StatsController statistics
  - EmailService digest templates

### 📝 Documentation
- Updated all endpoint documentation
- New comprehensive README
- API overview and architecture guide
- Updated license information in `THIRD_PARTY_LICENSES.md`

### 🔐 Security
- No security changes in this version (maintained existing security features)

## [1.1.0] - 2025-09-XX

### Added
- Modular architecture with separate route handlers
- Enhanced logging system with rotation
- Email service with PHPMailer integration
- Tag system with color support
- File upload with validation
- Stats and analytics endpoints
- Soft delete for users and groups

### Changed
- Improved authentication flow
- Better error handling
- Optimized database queries

## [1.0.0] - 2025-08-XX

### Added
- Initial release
- JWT authentication
- User management
- Group management
- Basic file upload
- Email notifications

---

## Versioning Strategy

- **Major version** (X.0.0): Breaking changes, major features, architectural changes
- **Minor version** (1.X.0): New features, non-breaking changes
- **Patch version** (1.0.X): Bug fixes, minor improvements

## Upgrade Notes

### From 1.1.0 to 1.2.0

**Breaking Changes:**
- All namespaces changed from `Memories\*` to `AuthGroups\*`
- Memories and Elements endpoints no longer available
- Tag tables `memories` and `elements` no longer valid

**Migration Steps:**
1. Update all code references from `Memories\` to `AuthGroups\`
2. Remove any client code using memories/elements endpoints
3. Update tag associations to use `groups` or `files` instead
4. Update email configuration if using default addresses
5. Clear any cached data referencing old namespaces

**Database Changes:**
- No database schema changes required
- Memories and elements tables can be kept or dropped (not used by API)
- Consider archiving data before dropping tables

**Configuration Updates:**
```php
// Old
use Memories\Services\AuthService;

// New  
use AuthGroups\Services\AuthService;
```

## Future Roadmap

### [1.3.0] - Planned
- [ ] API key authentication system
- [ ] Rate limiting implementation
- [ ] Enhanced caching layer

### [1.4.0] - Planned
- [ ] Dynamic admin feature creation
- [ ] Auto-generate endpoints from schema
- [ ] Calendar module example
- [ ] Todo list module example

### [2.0.0] - Future
- [ ] WebSocket support for real-time features
- [ ] Redis integration
- [ ] Message queue system
- [ ] Advanced analytics dashboard
- [ ] Multi-language support

---

For questions or issues, please contact: support@authgroups.local
