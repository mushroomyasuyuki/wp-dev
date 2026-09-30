# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Payroll System Plugin** — WordPress plugin for employee and payroll management. Handles employee master data, attendance import, payroll calculations (gross/deductions/social insurance/income tax), and pay slip PDF generation. Includes both wp-admin dashboard and front-end (fixed page) interfaces for staff and employee self-service.

- **Version:** 0.1.0 (beta, fully functional scaffold)
- **PHP Requirement:** 8.0+
- **WordPress Requirement:** 6.0+
- **Entry Point:** `payroll-system.php`

## Development Setup

### Quick Start

```bash
# Install Composer dependencies (mPDF for PDF generation)
composer install

# Start WordPress development server
npm start

# Access at http://localhost:8881 (WP Playground)
```

### Requirements

- **PHP CLI** (8.0+): `sudo apt install php-cli php-mbstring php-xml php-zip php-gd`
- **Composer**: https://getcomposer.org/download/
- **Node.js** (for WP Playground CLI): Already available in environment
- **mPDF** (for PDF generation): Installed via Composer, ~39MB (trimmed fonts)

### Important Notes

- Composer dependencies go into `/vendor/` → **include in distribution zip**
- Only `Sun-ExtA.ttf` + `DejaVu*.ttf` fonts are included (optimized for Japanese). Running `composer install` will re-download all fonts; delete non-essential ones after.
- Tests not yet implemented

## Code Architecture

### Class Naming & Autoloading

- **Naming Convention:** `PS_Xxx_Yyy` class → `class-ps-xxx-yyy.php`
- **Autoloader:** `payroll-system.php` registers `spl_autoload_register()` 
- **Search Paths:** `/includes/`, `/includes/calculation/`, `/admin/`, `/public/`
- Plugin constant: `PAYROLL_SYSTEM_PLUGIN_DIR`, `PAYROLL_SYSTEM_PLUGIN_URL`

### High-Level Modules

#### 1. **Core & Initialization**
- `PS_Core` — main plugin class, hooks registration
- `PS_Activator` — activation hook: table creation, role setup, auto-create 9 fixed pages, `maybe_upgrade()` for schema migrations
- `PS_Deactivator` — deactivation hook

**Key Design:** `maybe_upgrade()` runs on `init` hook (NOT `plugins_loaded`), because `$wp_rewrite` is needed for `wp_insert_post()` → `get_permalink()` calls. Guarded by atomic lock (`payroll_system_upgrade_lock` option) to prevent race conditions when DB version mismatches.

#### 2. **Data Models & Repositories**
- `PS_Employee` — CRUD for employee master (includes password hashing for self-service)
- `PS_Attendance` — CRUD for attendance data
- `PS_Rate_Master` — CRUD + validation for rate tables (5 types: health/pension/UI/income-tax/overtime rates)
- `PS_Payroll_Slip` — CRUD for calculated pay slips + PDF state

**Key:** `PS_Rate_Master::get_type_defs()` is the **single source of truth** for all 5 rate types (field structure, CSV columns, validation). Controllers and importers reference it.

#### 3. **Business Logic**
- `PS_Payroll_Calculator` (+ sub-classes in `/includes/calculation/`) — orchestrates calculation (gross → deductions → net), applies rate lookups at calculation date
- `PS_Rate_Repository` — fetches effective rates for a given month (upsert logic: same `effective_year_month + natural_key` = update existing row)
- `PS_Audit_Logger` — immutable log of all operations (created/recalculated/confirmed/corrected) with before/after JSON snapshots

**Status Workflow:** `reviewing` (after calculation) → `confirmed` (approved) → `corrected` (re-calculated after correction). Only `confirmed`/`corrected` slips generate PDFs or display to employees.

#### 4. **Admin Interface (wp-admin)**
- `PS_Admin_Menu` — menu structure & capability routing
- **Views:** `admin/views/*.php` (dashboard, employees, attendance import, payroll run, slip management, rate settings, audit log)
  - Forms post via `admin-post.php` actions (`admin_post_ps_*`)
  - Controllers validate, process, redirect
- **Assets:** `admin/css/admin.css`, `admin/js/admin.js`

#### 5. **Frontend (Fixed Pages / Shortcodes)**
- **Payroll staff interface** (`PS_Frontend_*` classes): 
  - `[payroll_login]` / `[payroll_logout]` — WordPress user auth (`wp_signon()`)
  - `[payroll_dashboard]` — dashboard (mirrors wp-admin summary for fixed page)
  - `[payroll_employees]` — staff employee management
  - `[payroll_attendance_import]` — 4-step CSV wizard
  - `[payroll_run]` — calculate payroll (execution only; confirm/correct in wp-admin)
  - `[payroll_slips]` — view & download confirmed slips (no confirm/correct here)

- **Employee self-service** (`PS_Employee_Auth` + `PS_Frontend_Employee_*`):
  - `[payroll_employee_login]` / `[payroll_employee_logout]` — own authentication (NOT WordPress users)
  - `[payroll_employee_change_password]` — change password + revoke all sessions
  - `[payroll_employee_slips]` — view own confirmed slips only

**Authentication Split:** 
- Staff: WordPress users + `manage_payroll` capability
- Employees: Independent auth system (password hash + `wp_ps_employee_sessions` table + `ps_employee_auth` cookie)

**Shared Logic:** Controllers use `_ps_redirect_base` hidden field to route success redirects. Admin & front use same `PS_Payroll_Calculator`, `PS_Attendance_Controller`, etc. — no logic duplication.

**Design Decision:** Confirm/Correct operations restricted to wp-admin only (not frontend) to reduce accidental irreversible actions by non-admin staff.

#### 6. **PDF Generation**
- `PS_Payroll_Slip_Pdf` — generates HTML from `gross_amounts_json` / `deduction_amounts_json`, writes to PDF via mPDF
- `PS_Payroll_Pdf_Controller` — handles downloads (individual or ZIP batch), guards: confirmed status only + employee ID match for self-service
- **Font Fix:** Explicitly set `font-family: sun-exta;` in body to ensure Japanese glyphs render (not DejaVu placeholder). Verified with `pdffonts` + `pdftoppm`.

### Database Schema

**Core Tables (auto-created on activation):**
- `wp_ps_employees` — master (includes `password_hash`, `must_change_password` for self-service)
- `wp_ps_attendance` — time & leave data
- `wp_ps_rate_masters` — 5 rate types, upsert by (`effective_year_month`, natural_key)
- `wp_ps_payroll_slips` — calculated slips, status = `reviewing`/`confirmed`/`corrected`
- `wp_ps_audit_log` — immutable log (before/after JSON, timestamp, user_id, operation)
- `wp_ps_employee_sessions` — self-service auth tokens (stores SHA-256 hash of cookie, not plain token)

**Roles:**
- `administrator` — full access
- `payroll_manager` (auto-created) — only `manage_payroll` capability

**DB Version:** `PAYROLL_SYSTEM_DB_VERSION` (currently 1.4.0). Checked on init hook; `dbDelta()` runs if mismatch. Locked atomically to prevent concurrent overwrites.

### Fixed Pages Auto-Creation

9 fixed pages auto-created on activation (via `PS_Activator::create_default_pages()`):
1. `[payroll_login]` — staff login
2. `[payroll_dashboard]` — staff dashboard
3. `[payroll_employees]` — staff employee mgmt
4. `[payroll_attendance_import]` — staff attendance import
5. `[payroll_run]` — staff payroll execution
6. `[payroll_slips]` — staff slip view/download/confirm
7. `[payroll_employee_login]` — employee login
8. `[payroll_employee_change_password]` — employee password mgmt
9. `[payroll_employee_slips]` — employee slip view/download

**Design:** 
- Stored in `payroll_system_page_ids` option (maps key → post_id)
- Existing pages never overwritten (admin preserves custom edits)
- New page types added later only create new page (old ones untouched)
- All inter-page links auto-set (via shortcode attributes: `login_page`, `redirect`, etc.)
- Cocoon theme: Automatically LP-styled (`content_only_full_wide`, hide title/TOC/read-time)

**Recovery:** `PS_Activator::resync_shortcode_content()` regenerates all 9 page bodies if URLs drift after fixing duplication bugs (rare, but documented in readme.txt).

## Workflow Reference

### Payroll Calculation & Confirmation Flow

1. **Execute** → `[payroll_run]` or `PS_Payroll_Run_Controller::handle_execute()` → `PS_Payroll_Calculator::calculate()` → saves to slip table with status `reviewing`
2. **Review** → `admin/views/slips.php` → see calculation results
3. **Confirm** → click "Confirm" or batch-select → `PS_Payroll_Run_Controller::handle_confirm_selected()` → status → `confirmed`
4. **PDF** → Only `confirmed`/`corrected` slips → `PS_Payroll_Slip_Pdf::generate()` (mPDF) → cache in `uploads/payroll/slips/`
5. **Correct** → If error found → `PS_Payroll_Slip_Pdf_Controller::handle_correct()` → re-run calculator → status → `corrected` + audit log with `correction_reason`

**Constraints:**
- Confirmed status locks against re-calculation (skipped by `PS_Payroll_Slip::save_calculation()`)
- PDF only for confirmed slips
- Employees only see confirmed/corrected slips (no intermediate states)
- Employees cannot confirm or correct (operations wp-admin only)

### Attendance Import Flow

4-step wizard (both wp-admin and frontend):
1. **Upload** — CSV file
2. **Map Columns** — select which CSV column → which field
3. **Preview** — review parsed rows (validation errors shown inline)
4. **Confirm** — persist to database

Mapping saved per import profile (option `payroll_system_import_profile`). `PS_Attendance_Importer` validates, parses, matches to existing records, returns errors if any duplicate rows or mismatches found.

### Audit Trail

Every operation logged to `wp_ps_audit_log`:
- `operation` = `created` / `recalculated` / `confirmed` / `corrected`
- `before_json` / `after_json` = full slip data snapshots (for corrections: `after_json.correction_reason` stored)
- `user_id`, `created_at`

No UI deletion of logs (immutable by design). Admins view in `admin/views/audit-log.php`.

## Common Development Tasks

### Add a New Rate Type

1. Edit `PS_Rate_Master::get_type_defs()` in `includes/class-ps-rate-master.php` → add 1 entry to `$defs` array
   - Define: `name`, `label`, `csv_columns`, `validation_rules`
   - Example: health insurance, pension, UI, income tax, overtime rates already exist
2. CSV import, manual form, calculation, PDF report automatically adapt (all reference `get_type_defs()`)
3. Update `PAYROLL_SYSTEM_DB_VERSION` if new DB column needed; `maybe_upgrade()` will run `dbDelta()` on next request

### Add a Pay Slip Line Item

1. `PS_Payroll_Slip::$properties` defines schema (example: `gross_salary`, `health_insurance`, etc.)
2. `PS_Payroll_Calculator` calculates it (references rate tables, applies logic)
3. JSON stored in `wp_ps_payroll_slips.gross_amounts_json` / `deduction_amounts_json`
4. `PS_Payroll_Slip_Pdf` renders in PDF template (templates in that class, not separate files)
5. Test: Admin → Payroll Run → select month/employees → Execute → confirm → PDF download

### Modify Rate Import/Export CSV Format

1. Edit `PS_Rate_Master::get_type_defs()` → update `csv_columns`
2. Update templates in `PS_Rate_Importer::generate_template_csv()`
3. Update DB schema (`PAYROLL_SYSTEM_DB_VERSION` + `dbDelta()` in `PS_Activator`) if adding columns to `wp_ps_rate_masters`

### Debug an Attendance Import Issue

1. `PS_Attendance_Importer::parse_and_validate()` — check validation logic
2. Enable WP debug: `define('WP_DEBUG', true);` in wp-config.php
3. CSV parsing errors logged in UI; check input file format against `import_profile` (saved settings)
4. Test with small sample CSV to isolate problem rows

### Extend Front-End UI Without Duplicating Logic

1. Create new `PS_Frontend_*` class in `includes/class-ps-frontend-*.php`
2. Reuse `PS_Employee`, `PS_Payroll_Calculator`, etc. from shared layer
3. Create shortcode in `PS_Core::register_shortcodes()`
4. Use `_ps_redirect_base` hidden field for post-submit redirect (routes back to front-end, not wp-admin)
5. Pass same validation errors/notices to template (no separate validation)

## Git & Deployment

- **Branch:** `master-ke5udp` (main development)
- **GitHub:** https://github.com/mushroomyasuyuki/wp-dev
- **Deployment:** Distribute as `.zip` with `/vendor/` included (Composer already run)

## Known Limitations & Future Work

1. **Corrections:** Re-calculation only (no direct amount override). If full amount editing needed, extend `PS_Payroll_Slip::correct()` + DB schema.
2. **Multi-slip adjustments:** Corrections currently per-slip. Batch corrections/adjustments would need transactional wrapper.
3. **Payroll tests:** Unit tests not yet written. Start with `PS_Payroll_Calculator` (most complex, highest ROI).
4. **Audit log pruning:** No automatic retention policy. Consider adding option to archive/delete logs older than N months.
5. **Multi-currency:** Structure exists; actual conversion rates/logic not yet implemented.
6. **Invoice/reporting:** Only pay slips; no GL export, tax filing templates, or HR reports yet.

## Configuration & Customization

- **Company name, payment day:** Stored in options (`payroll_system_company_name`, `payroll_system_payment_day`), editable in Rate Master → Company Settings
- **Employee auth:** Toggle self-service on/off by hiding `[payroll_employee_*]` shortcodes (logic remains, but not exposed)
- **Theme & CSS:** All front-end styles in `public/css/frontend.css` (`.ps-frontend` namespace, safe from theme conflicts)
- **PDF font:** Set in `PS_Payroll_Slip_Pdf` (currently `sun-exta` for Japanese). Change `font-family` in HTML generation if using different locale fonts.

## Dependencies

- **Runtime:** mPDF (^8.3) via Composer → `/vendor/`
- **Dev/Optional:** WP Playground CLI (npm) for local server
- **WordPress:** Core auth, database API, hook system, options/meta storage

## Resources in Codebase

- `readme.txt` — full feature walkthrough, operations manual, troubleshooting notes
- `LICENSE.txt` — GPLv2+ (mPDF and other vendor libs have own licenses)
- `composer.json` — dependency lock (use `composer install` to rebuild)
