# Merchandising Module — Full Implementation Guide

*এই ডকুমেন্টটা আপনার প্রজেক্টের `docs/` ফোল্ডারে রাখুন (যেমন `08_MERCHANDISING_MODULE_IMPLEMENTATION.md`)। এটা `Full_Garments_ERP_Module_Spec.docx`-এর Merchandising অংশকে ছোট ছোট, নিরাপদ VS Code task-এ ভেঙে দিয়েছে, যাতে আপনার existing workflow না ভেঙে ধাপে ধাপে implement করা যায়।*

## Reference

- Source spec: `Full_Garments_ERP_Module_Spec.docx`, Section 3 → **"1. Merchandising"**
- Follow with: `docs/02_VSCODE_TASK_TEMPLATE.md` (one phase = one task, not all at once)
- Safety rules: `CLAUDE.md`, `06_DATABASE_AND_SAFETY_RULES.md` — always apply

## Full Feature Scope (from spec)

1. Buyer inquiry & style creation
2. Pre-costing (buyer mode & in-house mode), revision-wise costing
3. Order booking and confirmation against buyer PO
4. Auto BOM generation — color/size/destination-wise
5. Size-wise & color-wise costing breakdown
6. Multi-stage approval workflow (costing, order, BOM)
7. Category / department / buyer-wise merchandising group
8. Order progress meeting tracker & document bank
9. Auto email/SMS notifications to concerned departments
10. Revenue, profit-margin and capacity analytics by buyer/style

## Rule Before Any Phase

**Never start coding a phase directly.** First message to Claude for every phase must be an inspection request:

```
Inspect current Merchandising-related routes, controllers, models, migrations,
views, and requests related to: [feature name].
Do not change any code yet.
Tell me:
- What already exists (even partially)
- What is missing
- What tables/fields would need to be added
- What risk exists to current Order/BOM/Purchase flow
```

Only after Claude reports back, move to the actual task using the VS Code template. This prevents duplicate work and accidental breakage of the existing Order → BOM → Purchase chain.

## Recommended Implementation Order (small, safe phases)

Each phase below = one separate task. Do not combine phases in a single request.

### Phase 1 — Buyer & Style Master Data
- Confirm/extend buyer master (name, address, contact, group)
- Confirm/extend style master (style no, buyer, season, category)
- Add buyer inquiry entry if not already present
- **Risk area:** existing Order Entry likely already references buyer/style — check foreign keys before altering.

### Phase 2 — Pre-Costing Module
- New costing entity: cost-head wise (Fabric, Trims, CM, Overhead, Commission)
- Buyer-mode vs in-house-mode costing toggle
- Revision history (versioned costing, not overwrite)
- **Risk area:** none yet if this is a new module — keep it additive, do not touch existing Order/BOM tables.

### Phase 3 — Order Booking & Confirmation Against Buyer PO
- Confirm current Order Entry already covers this, or extend it
- Link order confirmation to a buyer PO reference field
- **Risk area:** this likely touches your existing Order Entry screen — inspect first, extend, do not rebuild.

### Phase 4 — Auto BOM Generation (color/size/destination-wise)
- Check current BOM Preparation logic
- Add auto-generation trigger from confirmed order + style + color/size matrix
- **Risk area:** high — BOM already flows into Purchase/PO. Any structural change here must keep the existing BOM → Purchase link intact. This phase needs the most careful review.

### Phase 5 — Size-wise & Color-wise Costing Breakdown
- Extend Phase 2 costing to break down by size/color grid
- **Risk area:** depends on Phase 2 being in place first.

### Phase 6 — Multi-Stage Approval Workflow (costing, order, BOM)
- Approval status field + approval log table (who approved, when, level)
- Apply to: costing, order confirmation, BOM
- **Risk area:** must respect existing role/permission system — do not create a parallel permission mechanism.

### Phase 7 — Merchandising Group (category/department/buyer-wise)
- Grouping/tagging for buyers, categories, departments
- Used for filtering dashboards and reports
- **Risk area:** low, mostly additive lookup tables.

### Phase 8 — Order Progress Tracker & Document Bank
- Meeting/progress note log per order
- File upload area per order (documents, references)
- **Risk area:** check storage/upload rules — do not touch `storage/logs` or existing upload folders structure without checking `06_DATABASE_AND_SAFETY_RULES.md`.

### Phase 9 — Auto Email/SMS Notifications
- Notify Store/Purchase/Commercial/Accounts on key merchandising events (order confirmed, BOM approved, etc.)
- **Risk area:** check existing mail/SMS config exists; never expose or hardcode credentials — must use existing `.env`-based config, not read/print it.

### Phase 10 — Analytics: Revenue, Margin, Capacity by Buyer/Style
- Reporting views/dashboard widgets pulling from costing + order + production data
- **Risk area:** read-only reporting, lowest risk — do last, once source data (costing, orders) is reliable.

## How to Feed This to Claude (VS Code)

For each phase, copy `docs/02_VSCODE_TASK_TEMPLATE.md` and fill it in like this example:

```
Task Title:
Merchandising Phase 2 — Pre-Costing Module

Current Problem:
No structured pre-costing exists; costing currently [describe current state
after Claude's inspection report].

Expected Result:
Add pre-costing with cost-head wise breakup (Fabric, Trims, CM, Overhead,
Commission), buyer-mode/in-house-mode toggle, and revision history.

Page / URL / Screen:
[merchandising costing screen path]

Reference:
[attach screenshot/example if you have one]

Important Rules:
- First inspect related routes, controllers, views, models, migrations,
  permissions, and exports if needed.
- Do not change unrelated files.
- Do not break existing Order/BOM/Purchase workflow.
- Keep UI professional and user-friendly.
- Use nullable/additive migrations only.

Before Coding:
Tell me your short plan and which files you will edit.

After Coding:
Give changed file list, what improved, test steps, and commands to run.
```

## Sequencing Reminder

- Do **not** jump to Phase 4 (Auto BOM) or Phase 6 (Approval) before Phases 1–3 are confirmed stable — they depend on stable order/style data and costing data.
- After each phase, run the relevant checks from `07_TESTING_CHECKLIST.md` before starting the next phase.
- Keep a `git status` / commit checkpoint after each phase so any phase can be rolled back independently.
