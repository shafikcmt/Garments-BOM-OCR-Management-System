# UI / UX Rules

## Style Direction

Use a professional enterprise dashboard style suitable for garments factory management.

The UI should feel:

- Clean
- Organized
- Fast to understand
- Easy for office users
- Management-friendly
- Not overly technical

## Layout Rules

- Use available width properly.
- Avoid excessive padding/margin.
- Keep important data visible without too much scrolling.
- Use cards only where they add clarity.
- Tables should be readable, compact, and structured.
- Filters should be useful but not overwhelming.
- Put high-priority columns first for the logged-in role when requested, without breaking original data sequence.

## Text Rules

Use short professional English text in UI.

Good examples:

- `Create PO`
- `Preview PO`
- `Pending Review`
- `Ready for Approval`
- `Download PDF`
- `Update Status`
- `No records found`

Avoid:

- Long explanations in buttons
- Casual messages
- Mixed Bangla-English UI text
- Technical implementation terms
- Duplicate welcome messages

## Button Rules

- Primary action should be visually clear.
- Secondary actions should not compete with the main action.
- Every action button must show a visible text label. An icon may sit alongside
  the label, but never replace it. A tooltip or `title` is not sufficient — the
  user must be able to read what a button does without hovering it.
- The only exception is a plain close "X" in the top-right corner of a modal or
  offcanvas, which is a universal convention. It still needs an `aria-label`.
- Dangerous actions need confirmation.

## Table Rules

- Keep headers short.
- Avoid too many columns visible at once if grouping is possible.
- Use horizontal scroll only when necessary.
- Important status should use clear badge.
- Search/filter should be easy.
- Empty state should explain what to do next.

## Dashboard Rules

Dashboard should show:

- Summary cards
- Pending work
- Department-wise progress
- Recent activity
- Alerts/notifications
- Management view of bottlenecks

Avoid dashboard clutter.
