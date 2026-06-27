# Claude Project Setup Guide

This guide explains how to add the Garments BOM OCR Management System to Claude Project and continue work using the VS Code Claude extension.

## 1. Create Claude Project

Open Claude in browser and create a new Project.

Recommended project name:

```text
Garments BOM OCR Management System
```

Recommended description:

```text
Centralized garments business workflow system for order, BOM, OCR-assisted data entry, purchase/PO, store, commercial, accounts, shipment tracking, role-based workspace, dashboard, PDF and Excel reporting.
```

## 2. Paste Project Instructions

Open the project instruction/settings area and paste the content from:

```text
docs/CLAUDE_PROJECT_INSTRUCTIONS_FOR_WEB.md
```

This keeps Claude focused on business workflow, safe coding, UI polish, PDF/Excel accuracy, and role-based logic.

## 3. Upload Knowledge Files

Upload these files to Claude Project Knowledge:

```text
README.md
CLAUDE.md
docs/01_PROJECT_BRIEF.md
docs/02_BUSINESS_WORKFLOW.md
docs/03_MODULE_MAP.md
docs/04_UI_UX_RULES.md
docs/05_PDF_EXCEL_FORMAT_RULES.md
docs/06_DATABASE_AND_SAFETY_RULES.md
docs/07_TESTING_CHECKLIST.md
prompts/01_FIRST_MESSAGE_TO_CLAUDE.md
prompts/02_VSCODE_TASK_TEMPLATE.md
```

Do not upload:

```text
.env
vendor/
node_modules/
storage/logs/
large upload/export files
database backups
server credentials
```

## 4. Add Files to Local Project

Copy this pack into your project root. Then run:

```bash
git status
git add CLAUDE.md .claudeignore docs prompts
git commit -m "Add Claude project working instructions"
git push origin master
```

## 5. Use With VS Code Claude Extension

1. Open the project folder in VS Code.
2. Open Claude extension panel.
3. Start a new chat from the project folder.
4. First message should be from `prompts/01_FIRST_MESSAGE_TO_CLAUDE.md`.
5. For every future update, use `prompts/02_VSCODE_TASK_TEMPLATE.md`.

## 6. Best Workflow for Future Updates

For each task:

1. Give screenshot/reference file if design-related.
2. Give exact page/URL if possible.
3. Say what is wrong now.
4. Say what final result should look like.
5. Ask Claude to inspect first, then change only required files.
6. Review diff before accepting.
7. Run check commands.
8. Commit and push.

## 7. Recommended Folder for Reference Images

Create this folder for future screenshots, PDF references, Excel samples, and design examples:

```text
docs/references/
```

Suggested naming:

```text
bom-preview-current.png
bom-preview-target.png
po-format-reference.pdf
payment-request-reference.xlsx
management-dashboard-reference.png
```

Use clear names so Claude can understand the files easily.
