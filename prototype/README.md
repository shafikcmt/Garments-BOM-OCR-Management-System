# Bulk Issuing — React Prototype

A standalone **React + TypeScript + Tailwind CSS + shadcn/ui** prototype of the
Store **Bulk Issuing** page.

> ⚠️ **Prototype only.** This folder is a self-contained design/dev reference. It
> runs on **mock data** and is **not wired to the Laravel backend**. Nothing here
> touches the live Blade app in the parent repository.

## Run it

```bash
cd prototype
npm install
npm run dev      # http://localhost:5173
```

Other scripts:

```bash
npm run build    # type-check (tsc) + production build to dist/
npm run preview  # serve the production build
```

Requires Node 18+ (built/verified on Node 24, npm 11).

## What it demonstrates

Full-width single-column workflow, matching the spec:

1. **Filter & Select** — Receiving-style: pick a filter type (PO Number / OI
   Number / SAP Code / Art. No / Style Number), then search a `Command`
   dropdown. Each result shows PO · Style · Buyer · Material · available stock.
   A 📷 **Scan** button opens the OCR dialog.
2. **PO / Material Summary** — read-only card (12 identity fields) that
   auto-fills on selection, with a live 🟢 Available · 🔴 Booked · ⚪ Free stock
   indicator that updates as quantities are typed.
3. **Indent Info** — Indent Section (dropdown), Indent Person (searchable
   combobox), Requisition No, Issue Date, and an auto-generated Issue No
   (`BI-YYYYMMDD-XXXX`).
4. **Issue Quantities** — colour-coded 4-column grid (Bulk / Sample / Liability
   / Dead) + Remarks, validated with **React Hook Form + Zod** (at least one
   quantity required, no future issue date, required indent fields).
5. **Bulk Issue History** — full-width table with client-side search, pagination
   and page-size control.

### Extras

- **OCR dialog** — mock camera capture → field extraction → confidence score →
  manual correction → *Apply & Search* auto-matches a PO.
- **Keyboard shortcuts** — `Ctrl/Cmd+K` focus search · `Ctrl/Cmd+Enter` add
  issue · `Ctrl/Cmd+N` new issue.
- **Loading skeletons**, an **error boundary**, toasts, tooltips on icon
  buttons, and responsive layout down to mobile.

## Structure

```
src/
  App.tsx                  # page composition + state + shortcuts
  types.ts                 # PoItem, BulkIssue, OcrResult, ...
  data/mock.ts             # mock POs, history, sections, persons
  lib/
    schema.ts              # Zod form schema + helpers
    utils.ts               # cn(), number/date formatting
  components/
    ui/                    # shadcn/ui primitives (button, card, select, ...)
    PageChrome.tsx         # breadcrumb + header
    PoFilterSelect.tsx     # step 1 filter + Command search
    SummaryCard.tsx        # step 2 read-only identity
    StockIndicator.tsx     # available / booked / free
    IssueForm.tsx          # steps 3–4 (RHF + Zod)
    Combobox.tsx           # searchable single-select
    HistoryTable.tsx       # step 5 table + pagination
    OcrDialog.tsx          # scan/extract/correct
    ErrorBoundary.tsx
    Skeletons.tsx
```

## Mapping back to the Laravel app

The `BulkIssue` shape in `src/types.ts` mirrors the `material_bulk_issues`
columns (incl. `indentSection`, `indentPerson`, `requisitionNumber`,
`materialName`, `gmtsColorName`, `artNo`). To make this real you would swap
`data/mock.ts` for calls to the existing Store endpoints
(`store.material.bulk-issues.*`, `bulk-issues/po-details/{bookingPo}`).
