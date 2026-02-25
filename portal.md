# Solterra Logistics Portal

Solar module logistics management platform — tracks modules from manufacturer through warehousing, customs, and delivery to project sites.

## Tech Stack
- **Backend**: PHP 8.x (no framework, plain PHP with includes)
- **Database**: MySQL via `mysqli`
- **Frontend**: Vanilla JS, Chart.js, Font Awesome 6, custom CSS (no build step)
- **Server**: Apache/XAMPP, served from `htdocs`
- **Auth**: Session-based with role hierarchy

## Directory Structure
```
├── *.php                  # Page controllers (one per page)
├── components/            # Reusable PHP view components
│   ├── project_overview/  # Project overview tabs, CSS, views
│   ├── breadcrumbs.php
│   ├── milestone_summary_card.php
│   └── ...
├── api/                   # AJAX endpoints (JSON responses)
├── ai-assistant/          # AI chat feature
├── migrations/            # Database migration scripts
├── docs/                  # Documentation
├── pictures/              # Uploaded images
└── vendor/                # Composer dependencies
```

## Key Conventions

### Colors & UI
- Primary: `#488C9A` (teal), Dark: `#293E4C` (navy)
- Cards: 12px border-radius, subtle shadows, gradient backgrounds
- Tab navigation: Hash-based routing (`#tab-pricing`, `#tab-deliveries`)
- Font Awesome 6 icons throughout

### Roles
```php
$isAdmin = in_array($role, ['admin', 'global_admin', 'customer_admin'], true);
$isGlobalAdmin = ($role === 'global_admin');
```
Admin roles see management features; customer roles see read-only views.
user role is for solterra to manage the data in the portal for the customer and they have a read only view of the progress. customer_admin is the licensable role (or what we ultimately want it to be) where customers license the portal and manage their own data. This is the more important to have a clear UI/UX for. admin role is for solterra account managers to manage specific accounts. global_admin can manage everything across all accounts

### Code Patterns
- Close DB connections before HTML rendering
- Pre-fetch all data needed for tabs (avoid mid-render queries)
- `view_mode` param: `'mw'` for megawatts, `'modules'` for count, `'pallets'`, `'truckloads'`
- Use `htmlspecialchars()` for all user-facing output
- Inline `<style>` blocks in components are acceptable but prefer `project_overview.css` for shared styles

### Database
Key tables: `projects`, `modules`, `deliveries`, `pallets`, `warehouses`, `module_batch_milestones`, `delivery_milestone_instances`, `invoices`

## Key Files
| File | Purpose |
|------|---------|
| `project_overview.php` | Main project dashboard with tabs |
| `components/project_overview/views_unified.php` | Tab content (timeline, site, modules, deliveries) |
| `components/project_overview/project_overview.css` | All project overview styles |
| `milestone_helpers.php` | Milestone/payment calculation functions |
| `document_helpers.php` | Document upload/management |
| `bootstrap.php` | App initialization, session, DB connection |
| `docs/portal_page_role_matrix.md` | Page + role capability matrix used for Sunny context |
| `ai-assistant/config/page-context-map.php` | Sunny page-awareness source (page purpose, role behavior, supporting pages) |
| `scripts/sunny/check_page_context_drift.sh` | Drift check between modeled page contexts and actual portal pages |
| `scripts/sunny/generate_portal_page_role_matrix.sh` | Regenerates page-role matrix docs from Sunny page context map |

## Development
1. Requires XAMPP (Apache + MySQL)
2. Configure DB in `config.dev.php`
3. Access at `http://localhost/Solterra-Solutions/Solterra-Logistics-Portal/`
4. No build step — edit PHP/CSS/JS directly
