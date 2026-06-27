# Claude Working Instructions — Garments BOM OCR Management System

You are working inside the Garments BOM OCR Management System project.

The owner wants safe, practical, business-focused improvements for a garments factory workflow system. The system is used to reduce dependency on many separate Excel files and bring order, BOM, purchase, store, commercial, accounts, shipment, and management follow-up into one controlled workspace.

## Primary Goal

Help improve and maintain the project without breaking existing workflows.

Always prioritize:

1. Existing business workflow
2. Data safety
3. Role and permission safety
4. Clean user-friendly UI
5. Accurate PDF/Excel/export formats
6. Small controlled changes
7. Clear explanation of what changed

## Project Type

This is a Laravel-based business management application with Blade views, PHP controllers, database migrations, PDF/export features, role-based access, dashboards, and workflow screens.

Common work types:

- UI polish and dashboard improvement
- Management-friendly workflow presentation
- BOM, PO, purchase, booking, store, commercial, accounts, and shipment process updates
- PDF/Excel/export layout fix
- Role-wise dashboard/workspace improvement
- Form validation and user-friendly message cleanup
- Git/deployment support
- Bug fixing after pull/merge/deploy

## Business Context

Before the software, company teams managed BOM and related operations using many separate Excel files. That caused duplicate entry, missing file versions, slow follow-up, and poor visibility for management.

This software should provide one organized workflow where each department can work from the same source of information.

Main supported departments:

- Merchandising
- Commercial
- Store
- Accounts
- Management/Admin

Main workflow:

```text
Order Entry
↓
BOM Preparation
↓
Review / Approval
↓
Purchase / PO Process
↓
Material Receiving / Store Update
↓
Commercial & Accounts Coordination
↓
Shipment Preparation
↓
Shipment Completion
↓
Management Review & Reporting
```

## Safety Rules

Never do these unless the user clearly asks and the change is necessary:

- Do not read, print, expose, or modify `.env` or secret files.
- Do not expose database credentials, API keys, mail credentials, or payment credentials.
- Do not modify `vendor/`, `node_modules/`, `.git/`, generated cache folders, or log files.
- Do not delete migrations, seeders, controllers, models, routes, views, or public assets without approval.
- Do not rename routes, controllers, models, migration tables, database columns, form field names, or permission names unless requested.
- Do not change existing business logic while doing UI-only tasks.
- Do not add new packages unless truly required.
- Do not rewrite large files when a small targeted patch can solve the issue.
- Do not make destructive terminal commands without asking first.

## How to Work

For every task:

1. Understand the user request and business purpose.
2. Inspect related routes, controllers, models, migrations, requests, policies, views, components, JS/CSS, and export/PDF files.
3. Find the smallest safe change.
4. Keep existing workflow intact.
5. Update only related files.
6. Test or explain how to test.
7. Summarize changed files and expected result.

## Communication Style

The user often explains tasks in Bangla-English. Reply in simple Bangla-English when explaining to the user.

For application UI text:

- Use professional English.
- Keep messages short and clear.
- Remove casual mixed Bangla-English messages from UI.
- Avoid unnecessary helper text.
- Use management-friendly labels where the screen is for management.

## UI/UX Rules

Target style:

- Professional enterprise dashboard
- Clean spacing
- Consistent card, table, button, badge, alert, filter, and form style
- Better use of available screen width
- Less empty space
- Clear data priority
- Modern but not flashy
- Easy for non-technical garments users

Avoid:

- Oversized headings
- Too much gradient
- Childish animation
- Repeated welcome text
- Too many filter boxes if fewer filters can do the same job
- Confusing icon-only action buttons without labels/tooltips
- Technical words in management-facing pages

## PDF / Excel / Export Rules

PDF/Excel/export formats are business-critical.

When changing them:

- Match reference image/file exactly when provided.
- Preserve paper size, margin, font size, table alignment, signature area, and page breaks.
- Do not change the design style unless requested.
- Use short table headers when space is limited.
- Avoid content cutting, overflow, hidden rows, and broken columns.
- Keep preview and download output consistent.
- Test with real-like sample data, including long buyer/style/vendor/item names.

## Role and Permission Rules

Before changing any screen or workflow, check role logic.

Do not break access for:

- Admin / Management
- Merchandising users
- Commercial users
- Store users
- Accounts users
- Other existing roles

If a task asks for role-wise view, keep the original data sequence safe. Only adjust display priority or visibility based on user role.

## Database Rules

Before adding or changing database fields:

- Check existing migrations and model fillable/casts/relations.
- Check controllers, form requests, validation, views, exports, and seeders.
- Prefer additive migrations instead of editing old migrations if project is already in use.
- Do not assume table/column names. Inspect first.
- If production data may exist, use safe nullable/default fields.

## Build / Check Commands

Run only relevant commands when possible:

```bash
php artisan route:list
php artisan view:clear
php artisan cache:clear
php artisan config:clear
php artisan migrate:status
php artisan test
npm run build
```

For UI-only changes, at least check views and build if frontend assets are changed.

## Git Rules

Before making changes:

```bash
git status
```

After changes, explain:

- What files changed
- Why they changed
- How to test
- Any risk or migration needed

Do not commit or push unless the user asks.

## Best Output Format

When finishing a task, provide:

1. Summary
2. Changed files
3. What improved
4. How to test
5. Any command to run
6. Notes/risks if any
