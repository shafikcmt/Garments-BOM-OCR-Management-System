# Setting up users for the Purchase Requisition workflow

A requisition is checked by the nearest head, then goes to the Store:

    staff / section head  →  Section Head (if the section has one)  →  Department Head  →  Store

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

A head's own requisition skips their own step and goes to the next one.
HARC and Maintenance have no sections: their staff go straight to the Department Head.
