# Setting up users for the Purchase Requisition workflow

A requisition is checked by ONE head, the nearest, then goes to the Store:

    staff  →  Section Head (if their section has one, else the Department Head)  →  Store

Who is a head is NOT a role. It is set on **Admin > Settings > Departments**.

## What each field on Admin > Users does

| Field | What it decides |
|---|---|
| **Department** | Narrows the Role list. Not stored itself: the Role carries the department. |
| **Role** | Access, and the person's department (Production, HARC, Maintenance …). Pick the department's own role. Do not pick "Requisition Raiser" for department staff: it belongs to no department, so their requisitions skip every head. |
| **Requisition Section** | Which Section Head checks their requisitions first, and the section on their form and requisition number. Leave "Not set" for a department with no sections (HARC, Maintenance). |
| **Portal Access** | Extra abilities on top of the role (e.g. General Stock Requisition). Already included for Production, Cutting, HARC, Maintenance. |
| **Requisition approval route** | Live preview of who will check their requisitions. Read it before saving. |

## Order of work

1. Create each person: Admin > Users > Add User (name, email, password, department, role, Requisition Section).
2. Admin > Settings > Departments: pick each Department Head and each Section Head (a person must exist before they can be picked).
3. Open one staff account's Edit screen and check the Requisition approval route.

A head's own requisition has no head step: a Section Head's or Department Head's own
requisition goes straight to the Store (a Section Head's is NOT sent up to the Department Head).
HARC and Maintenance have no sections: their staff go straight to the Department Head.

## Adding staff under a head

Same form, one account per person: the head's **Department**, that department's
**Role**, and the head's **Requisition Section** (e.g. Cutting). Nothing else is
needed. Their requisitions then go to that section's Section Head. HARC and
Maintenance staff: Department and Role only, no section; they go to the
Department Head.

## A Section Head raising a requisition

- It goes straight to the Store, not to the Department Head.
- Requested By on their form is their own name and email ("Technical section head").
  If their account has no Requisition Section and they head exactly one section, the
  form is filed under that section automatically. Setting the Requisition Section on
  their account still takes precedence.
- Their staff see the Section Head as Requested By, as before.
