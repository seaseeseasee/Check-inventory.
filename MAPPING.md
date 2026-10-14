# File Mapping: Old Structure → New Structure

## Overview
This document maps the old single-file structure to the new modular architecture.

## Backend Mapping

| Old File | New Location | Description |
|----------|-------------|-------------|
| `public/api.php` | Split into multiple files | Main API handler split into controllers, services, models |
| | `app/Controllers/AuthController.php` | Authentication endpoints |
| | `app/Controllers/DashboardController.php` | Dashboard endpoints |
| | `app/Controllers/NurseController.php` | Nurse management |
| | `app/Controllers/VehicleController.php` | Vehicle/Item management |
| | `app/Controllers/InspectionController.php` | Inspection/Checklist |
| | `app/Controllers/RestockController.php` | Restock requests |
| | `app/Controllers/HistoryController.php` | History/Reports |
| | `app/Services/AuthService.php` | Auth business logic |
| | `app/Services/InspectionService.php` | Inspection business logic |
| | `app/Services/RestockService.php` | Restock business logic |
| | `app/Models/*.php` | Data models (Vehicle, Nurse, Item, Inspection, RestockRequest, etc.) |
| | `app/Middleware/AuthMiddleware.php` | Authentication middleware |
| | `app/Middleware/CsrfMiddleware.php` | CSRF protection |
| | `app/Middleware/RateLimitMiddleware.php` | Rate limiting |
| | `app/Config/Database.php` | Database connection |
| | `app/Config/App.php` | App configuration |
| | `app/Helpers/Response.php` | Response formatting |
| | `app/Helpers/Validator.php` | Validation |
| | `app/Helpers/functions.php` | Global helper functions |
| | `config/app.php` | App config |
| | `config/database.php` | Database config |
| | `routes/web.php` | Routing |

## Frontend Mapping

### HTML/CSS
| Old Section | New Location |
|-------------|-------------|
| Full HTML in `index.html` | `resources/views/layouts/app.php` (main layout) |
| | `resources/views/pages/dashboard/index.php` (all tab content) |
| Inline CSS | `resources/assets/css/base.css` (reset, variables) |
| | `resources/assets/css/layout.css` (header, nav, container) |
| | `resources/assets/css/components.css` (chips, cards, forms) |
| | `resources/assets/css/pages/*.css` (page-specific styles) |

### JavaScript
| Old Section | New Location |
|-------------|-------------|
| Global variables | `resources/assets/js/core/` |
| `api()` function | `resources/assets/js/core/api.js` |
| `boot()`, `mutate()`, `persist()` | `resources/assets/js/core/api.js` |
| Auth logic | `resources/assets/js/core/auth.js` |
| UI helpers (`$`, `esc`, `uid`, etc.) | `resources/assets/js/core/ui.js` |
| Modal/Dialog | `resources/assets/js/components/modal.js` |
| Toast/Alert | `resources/assets/js/components/alert.js` |
| DataTable | `resources/assets/js/components/table.js` |
| Login page logic | `resources/assets/js/pages/login.js` |
| Dashboard logic | `resources/assets/js/pages/dashboard.js` |
| Inspection/Check logic | `resources/assets/js/pages/inspections.js` |
| Restock logic | `resources/assets/js/pages/restock.js` |
| Nurses logic | `resources/assets/js/pages/nurses.js` |
| History logic | `resources/assets/js/pages/history.js` |
| Vehicles/Stock logic | `resources/assets/js/pages/vehicles.js` |

## Database Mapping

| Old Table | New Model |
|-----------|-----------|
| `refer_vehicles` | `App\Models\Vehicle` |
| `refer_nurses` | `App\Models\Nurse` |
| `refer_items` | `App\Models\Item` |
| `refer_checks` | `App\Models\Inspection` |
| `refer_check_items` | `App\Models\InspectionItem` |
| `refer_drafts` | `App\Models\Draft` |
| `refer_draft_items` | `App\Models\DraftItem` |
| `refer_restock_requests` | `App\Models\RestockRequest` |
| `refer_restock_items` | `App\Models\RestockItem` |
| `refer_restock_actions` | `App\Models\RestockAction` |
| `refer_metadata` | `App\Models\Metadata` |
| `refer_login_attempts` | `App\Models\LoginAttempt` |
| `refer_revision` | `App\Models\Revision` |

## Configuration Mapping

| Old | New |
|-----|-----|
| `config.example.php` | `config/app.php`, `config/database.php`, `.env.example` |
| `schema.sql` | `database/migrations/001-initial.sql`, `002-restock.sql` |
| `migrations/001-add-restock.sql` | `database/migrations/002-restock.sql` |

## Test Files
| Old | New |
|-----|-----|
| `tests/test-php.php` | Updated to use new structure (models, services) |
| `tests/test-restock.php` | Updated to use new structure |
| `tests/mock-server.mjs` | Still works (simulates API) |
| `tests/check-build.mjs` | Updated for new build process |

## Key Changes

1. **Separation of Concerns**: Business logic moved from API handler to Services
2. **MVC Pattern**: Controllers handle HTTP, Services handle logic, Models handle data
3. **Frontend Modularization**: CSS/JS split by feature, loaded on demand
4. **Template System**: PHP views with layout inheritance
5. **Routing**: Centralized route definitions
6. **Environment Config**: .env file for configuration
6. **Security**: Middleware for auth, CSRF, rate limiting
7. **Testing**: Unit tests updated for new architecture