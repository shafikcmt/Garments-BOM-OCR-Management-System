# Garments BOM OCR Management System — Claude Project Pack

Use this pack to make Claude understand the project before future development work.

## Where to place these files

Copy these files into the root of your project folder:

- `CLAUDE.md`
- `.claudeignore`
- `docs/`
- `prompts/`

Your project root should look like this:

```text
Garments-BOM-OCR-Management-System/
├── app/
├── database/
├── resources/
├── routes/
├── CLAUDE.md
├── .claudeignore
├── docs/
└── prompts/
```

## Best use

1. First add these files to the project.
2. Open the project in VS Code.
3. Start Claude from the VS Code extension.
4. First ask Claude to read `CLAUDE.md`, `docs/`, and scan the project without changing code.
5. For every new change, use `prompts/02_VSCODE_TASK_TEMPLATE.md`.

## Important

Do not upload `.env`, database backup files, server credentials, vendor folder, node_modules folder, or storage logs to Claude Project Knowledge.
