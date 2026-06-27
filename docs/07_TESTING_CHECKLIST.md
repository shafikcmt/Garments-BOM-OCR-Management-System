# Testing Checklist

Use this checklist after changes.

## General Check

```bash
git status
php artisan route:list
php artisan view:clear
php artisan cache:clear
php artisan config:clear
```

## If Database Changed

```bash
php artisan migrate:status
php artisan migrate
```

Then test create/edit/view/list/export for affected feature.

## If Blade/View Changed

Check:

- Page loads without error
- Desktop layout
- Mobile/responsive layout
- Table overflow
- Buttons/actions
- Empty state
- Long text
- Modal/dropdown behavior

## If CSS/JS/Vite Changed

```bash
npm run build
```

Check browser console for errors.

## If PDF Changed

Check:

- Single record preview
- Multiple record preview
- Download PDF
- Long text data
- Signature area
- Page break
- Print view

## If Excel Changed

Check:

- Download file opens
- Header names
- Column order
- Totals
- Date/number format
- Multiple records

## If Role-Based Change

Test with each relevant role:

- Admin/Management
- Merchandising
- Commercial
- Store
- Accounts

Check menu visibility, buttons, data visibility, and actions.

## Final Response Format for Claude

After completing a task, Claude should reply:

```text
Summary:
- ...

Changed files:
- ...

What improved:
- ...

How to test:
- ...

Commands:
- ...

Notes/Risks:
- ...
```
