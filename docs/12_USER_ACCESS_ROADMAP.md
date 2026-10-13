# Users form — access UI roadmap

Goal: the Admin > Users form shows only what is relevant to the person being
edited, and everyday abilities are one-click switches instead of a 17-module
permission matrix. UI-only unless a phase says otherwise; role and permission
rules on the server are never loosened by these phases.

## Done

- **Portal Access card** (`App\Support\PortalAccess`, `_requisition-access.blade.php`).
  Each switch is a named bundle of existing permissions written as direct grants.
  Add a switch = add one entry. Today: General Stock Requisition
  (`store.requisition.view` + `store.requisition.create`).
- **Phase 1 — show blocks by department.** Buyer Access is offered only for
  Merchandising and Supply Chain (`BuyerScope::BUYER_ACCESS_FORM_DEPARTMENTS`).
  Accounts, Commercial and Store work across every buyer. A user who already
  holds buyers keeps the control. BOM Upload shows for Merchandising only.
  `BuyerScope::SCOPED_DEPARTMENTS` (who is actually confined on the server) is
  unchanged on purpose.
- **Phase 2 — smaller permission matrix.** Matrix sits under a collapsed
  "Advanced permissions" section with a live "From role / Extra granted" line.
  Default view is "Relevant" (`App\Support\DepartmentModules`); "All" shows
  every module. Collapsing or filtering never drops a tick from the save.
- "Not in Item Master" removed from the requisition form; every line is an Item
  Master item (older sheets keep their typed lines).

## Next

- **Phase 3 — one toggle list.** Give each `PortalAccess` entry a `departments`
  field. Move Department Admin and BOM Upload into the card for one consistent
  look. They keep their own save paths (`is_department_admin`, `can_upload`) and
  policies — do not merge those into permission grants.
- **Phase 4 — Buyer / Style Stock requisition toggle.** One new `PortalAccess`
  entry for `material.requisitions.view/create`, when the Buyer/Style Stock
  requisition work is ready. Not before.
- **Decision (2026-10-13): server buyer scoping stays as is.** Business rule
  confirmed by the owner: only Merchandising and Supply Chain work buyer-wise;
  Accounts, Commercial, Store and the rest work across every buyer. A live
  check found 9 confined users in total, none outside Merchandising / Supply
  Chain. So `BuyerScope::SCOPED_DEPARTMENTS` is left unchanged: narrowing it
  breaks ~43 tests of the buyer-confinement feature for no live benefit, and the
  form no longer offers Buyer Access outside the two departments
  (`BUYER_ACCESS_FORM_DEPARTMENTS`), so no new confinement can be created there.
  Revisit only if that confinement feature is retired on purpose.
