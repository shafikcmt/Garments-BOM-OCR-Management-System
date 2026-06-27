# Database and Safety Rules

## Secret Safety

Never read, print, expose, or modify:

- `.env`
- database password
- mail password
- API keys
- payment credentials
- server private keys
- private backups

## Database Change Rules

Before any DB change:

1. Inspect existing migrations.
2. Inspect related models.
3. Inspect validation rules.
4. Inspect forms/views.
5. Inspect exports/PDF usage.
6. Inspect seeders if required.

If project is already used or deployed, prefer new safe migration instead of editing old migration.

## Field Change Rules

Do not rename existing columns unless required. Renaming can break:

- Controllers
- Views
- Reports
- Exports
- PDF templates
- Old data
- Filters
- Validation

## Safer Add Field Pattern

When adding new optional field:

- Use nullable column where possible.
- Add model fillable/casts if needed.
- Add validation rule.
- Add form field.
- Add display in view.
- Add export/PDF field only if requested.

## Permission Safety

Always check role/permission before changing:

- Menus
- Buttons
- Dashboard widgets
- Approval actions
- Export/download actions
- Delete/update actions

Do not make data visible to unauthorized users.

## Terminal Safety

Safe commands:

```bash
git status
php artisan route:list
php artisan migrate:status
php artisan view:clear
php artisan cache:clear
php artisan config:clear
npm run build
```

Ask before destructive commands like:

```bash
rm -rf
php artisan migrate:fresh
php artisan db:wipe
git reset --hard
git clean -fd
git push --force
```
