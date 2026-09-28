# Deploy Notes — Purchase Requisition Workflow

Notes for whoever deploys the requisition work to the live server
(PostgreSQL). Read the whole page before deploying.

## 1. Hold: do not deploy the stage engine (3b) without Return (3c)

Commit `aa87891` (Step 3b, the stage engine) routes every newly submitted
requisition through approval stages. It has **no Return action yet** — an
approver cannot send a sheet back to the previous stage. That arrives in
Step 3c.

**Do not deploy `aa87891` until 3c is committed and deployed with it.**
Everything before it (Steps 0–3a, Requirement vs Issue, Stock Summary,
charts) can go out on its own.

## 2. Deploy-day order (3b + 3c)

Migrations in this batch: `2026_10_01_000001` to `000003` (3b) and
`2026_10_02_000001` (3c, adds the "cancelled" status).

Staff must not submit a requisition between the migration and step 2 below:
until the approvers are set, every submit is refused with
"… approver is not set. Ask Admin."

1. **Deploy and migrate**

   ```bash
   php artisan migrate
   php artisan view:clear
   ```

2. **Immediately set the approvers** (Admin):
   - **Admin > Departments > Store** — set the **Store head**. This also gives
     them the Requisition Summary & Requirement vs Issue permission and
     `store.requisition.view`.
   - **Admin > Settings > Requisition Signatures** — set **HR Audit**,
     **Finance & Commercial Head** and **Operation Head** to specific users,
     then **Save Settings**. Saving gives each of them
     `store.requisition.view`.

3. **Check nothing is left unset** (read-only; run on live):

   ```bash
   php artisan tinker --execute='$h = App\Models\Department::where("key", "store")->first()?->head; echo "Store head: ".($h ? $h->name." <".$h->email.">" : "NOT SET")."\n"; $n = 0; foreach (App\Services\RequisitionWorkflow::settingsWarnings() as $level => $rows) { foreach ($rows as $i => $w) { $b = App\Models\RequisitionSignatureBlock::query()->forLevel($level)->get()->values()[$i]; echo ucfirst($level)." sheet, box ".($i + 1)." (".$b->label.($b->title ? " / ".$b->title : "")."): ".$w."\n"; $n++; } } echo $n === 0 && $h ? "All approval stages are set.\n" : "$n stage(s) not set.\n";'
   ```

   Expected output when ready:

   ```text
   Store head: <name> <email>
   All approval stages are set.
   ```

   Anything else lists the box that still needs a person, for example
   `Final sheet, box 3 (Audit by / HR Head): Not set. Choose a user.`
   Admin > Requisition Signatures shows the same warnings.

4. **Let staff submit.**

## 3. What happens to existing requisitions

Nothing changes for them. Every requisition submitted before the deploy has
`workflow_version = NULL` and finishes on the old flow: the old Received /
Approve buttons, the Store correction, the same PDF/Excel. Only sheets
submitted after the deploy (including old drafts submitted after it) use the
stages.

## 4. Rolling back the migrations

| Migration | `down()` |
|---|---|
| `2026_10_01_000001_add_workflow_to_purchase_requisitions_table` | drops `workflow_version`, `current_stage` |
| `2026_10_01_000002_create_purchase_requisition_stages_table` | drops the stage plan table |
| `2026_10_01_000003_store_head_signature_blocks` | puts the Store Head boxes back to "Anyone with a role: store" |
| `2026_10_02_000001_add_cancelled_to_purchase_requisition_status` (3c) | removes "cancelled" from the allowed statuses — **refuses** while any requisition is cancelled |

`2026_10_01_000003` was checked on real data: `up()` turns the two Store Head
boxes (department box 3, final box 2) from `role / store` into `store_head`,
and `down()` restores exactly `role / store`. One thing to know: `down()`
converts **every** `store_head` box, including any Admin creates after the
deploy.

`2026_10_02_000001` widens the status column per database: MySQL `MODIFY … ENUM`, PostgreSQL drops the CHECK constraint(s) on `status` — found by what they check, not by name — and adds `purchase_requisitions_status_check` back with "cancelled". Run the status check in section 5 first to see the current constraint.

Roll back only together with the code: the stage engine needs all four.

## 5. Other checks for the same deploy

- **Who can approve / delete** (read-only), from the Step 1 review:

  ```bash
  php artisan tinker --execute='foreach (["store.requisition.approve","store.requisition.delete","store.delete"] as $p) { $perm = Spatie\Permission\Models\Permission::where("name",$p)->where("guard_name","web")->first(); if (! $perm) { echo "$p: permission does not exist\n"; continue; } $rows = collect(); foreach ($perm->users as $u) { $rows->push([$u, "direct"]); } foreach ($perm->roles as $r) { foreach ($r->users as $u) { $rows->push([$u, "via role ".$r->name]); } } echo "\n== $p (".$rows->count()." grants)\n"; foreach ($rows->sortBy(fn($x) => $x[0]->name) as [$u, $how]) { printf("%-28s | %-32s | %-22s | %-24s | %s\n", $u->name, $u->email, $u->departmentLabel() ?: "-", $u->getRoleNames()->implode(","), $how); } }'
  ```

- **Requisition status column** (read-only; needed before the 3c migration
  that adds "cancelled"): confirms the column type and the name of its check
  constraint.

  ```bash
  php artisan tinker --execute='print_r(DB::select("select column_name, data_type, character_maximum_length from information_schema.columns where table_name = ? and column_name = ?", ["purchase_requisitions","status"])); print_r(DB::select("select c.conname, pg_get_constraintdef(c.oid) as def from pg_constraint c join pg_class t on t.oid = c.conrelid where t.relname = ? and c.contype = ?", ["purchase_requisitions","c"]));'
  ```
