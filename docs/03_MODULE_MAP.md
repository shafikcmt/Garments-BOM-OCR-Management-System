# Module Map

Use this as a project understanding map. Before implementing a task, Claude should inspect actual code and confirm file names.

## Expected Laravel Areas

### Routes

Common files to inspect:

```text
routes/web.php
routes/auth.php
routes/console.php
```

### Controllers

Common folder:

```text
app/Http/Controllers/
```

Look for controllers related to:

- Dashboard
- Order
- BOM
- OCR
- PO / Purchase
- Booking
- Store
- Commercial
- Accounts
- Shipment
- Export / PDF / Excel
- User / Role / Permission

### Models

Common folder:

```text
app/Models/
```

Check models before changing database-related screens.

### Views

Common folder:

```text
resources/views/
```

Check layouts, partials, components, pages, tables, modals, and export/PDF templates.

### Assets

Common files/folders:

```text
resources/css/
resources/js/
public/
vite.config.js
tailwind.config.js
```

### Database

Common folders:

```text
database/migrations/
database/seeders/
database/factories/
```

## Feature Safety Checklist

Before changing any feature, inspect:

- Route name
- Controller method
- Request validation
- Model fields
- Migration columns
- Blade view
- JS behavior
- Permissions/roles
- Export/PDF impact
- Test/sample data impact

## Common Risk Areas

- PDF preview and download mismatch
- Excel export column mismatch
- Role-wise dashboard data mismatch
- Permission access broken after UI change
- Form field renamed but controller still expects old name
- Migration added but model fillable not updated
- Query filter changed but report/export not updated
- Responsive table overflow
