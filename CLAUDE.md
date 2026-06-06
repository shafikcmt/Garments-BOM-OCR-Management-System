# Project Instructions for Claude

You are working on the currently opened Laravel project.

## Important Safety Rules
- Do not read, print, expose, or modify `.env` or any secret/credential files.
- Do not modify files inside `vendor/`, `node_modules/`, `.git/`, `storage/logs/`, or generated cache folders.
- Do not change database credentials, API keys, mail credentials, or payment credentials.
- Do not rename routes, controllers, models, migrations, table names, column names, or form field names unless explicitly requested.
- Preserve all existing backend logic and workflows.

## Current Task Type
Most tasks in this project are UI polish, Blade cleanup, Laravel workflow updates, PDF/Excel format updates, and role-based dashboard/workspace improvements.

## Coding Rules
- Prefer small, safe, targeted changes.
- Keep existing routes and permissions unchanged.
- Keep existing role-based logic unchanged.
- Keep export, PDF, Excel, upload, and download functions working.
- Use professional English UI text.
- Remove casual Bangla-English mixed UI messages.
- Avoid unnecessary long helper text.
- Do not add random new packages unless needed.
- After changes, run relevant checks when possible:
  - php artisan route:list
  - php artisan view:clear
  - php artisan cache:clear
  - npm run build

## UI Style
- Professional enterprise dashboard style.
- Clean spacing, consistent cards, tables, buttons, alerts, headings.
- Use short headings and short messages.
- Avoid oversized text, too much gradient, childish animation, and repeated welcome text.