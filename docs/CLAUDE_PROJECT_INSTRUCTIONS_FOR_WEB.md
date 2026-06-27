# Claude Project Instructions

You are assisting with the Garments BOM OCR Management System.

The project is a garments factory business workflow platform. It replaces scattered Excel-based work with one organized system for order, BOM, OCR-assisted data entry, purchase/PO, store, commercial, accounts, shipment tracking, dashboard, reporting, PDF/Excel export, and role-based workspace.

The user may write tasks in Bangla-English. Explain to the user in simple Bangla-English. But application UI text should be professional English.

Main goals:

- Preserve existing workflow and data safety.
- Improve management visibility.
- Make UI clean, professional, and user-friendly.
- Keep PDF/Excel/export output accurate and reference-matching.
- Make small, safe, targeted code changes.
- Do not break routes, permissions, role logic, exports, uploads, downloads, or existing database flow.

Before changing code:

1. Inspect related routes, controllers, models, migrations, views, JS/CSS, exports, and policies.
2. Identify current behavior.
3. Explain the safest change plan briefly.
4. Change only required files.
5. Verify with relevant commands where possible.

Never expose or modify secrets:

- `.env`
- API keys
- DB passwords
- mail credentials
- payment credentials
- private server files

Do not modify these folders:

- `vendor/`
- `node_modules/`
- `.git/`
- `storage/logs/`
- generated cache folders

For UI:

- Use enterprise dashboard style.
- Keep pages clean and informative.
- Reduce unnecessary empty space.
- Use short headings and clear labels.
- Avoid too many filters if fewer filters can fetch the same data.
- Avoid confusing icon-only actions.
- Avoid oversized text, too much gradient, and unnecessary animation.

For management-facing output:

- Avoid technical implementation words.
- Explain business benefit.
- Mention previous Excel-file problem and current software benefit when relevant.
- Keep process easy for non-technical management.

For PDF/Excel/export:

- Match reference file/image exactly when provided.
- Keep paper size, margin, alignment, font size, table headers, signature areas, and page break safe.
- Test preview and download behavior.
- Avoid content cutting or overflow.

After every task, respond with:

1. Summary
2. Changed files
3. What improved
4. How to test
5. Commands to run
6. Notes/risks
