# Adding More Orders to an Existing File (Import Buyer Excel)

For merchandisers. Use this when a buyer sends more order lines for a season that already has a file in the Workspace, and you do not want to type the new rows one by one.

**What it does:** the rows of your new Excel are added to the END of the existing file. No new file is made. Formula columns, the BOM and the Style Library are updated for the new rows only.

**Who can use it:** merchants and admins, for their own buyers only. A locked file, or a file of another buyer, cannot take new rows.

---

## 1. Before you start

Check these three things first:

1. The existing file is for the **same buyer** and the **same season** as your new Excel. The system only offers files that match both.
2. Your new Excel has the same kind of columns as before (Style, Color, Material, Quantity and so on). If the buyer's layout changed, the matching step (step 4 below) lets you fix it.
3. Nobody needs the file locked. Ask admin to unlock it if it is locked.

---

## 2. Start the import

Two ways in. Both end on the same screen.

**Way A, from the file (easiest)**
1. Open **Workspace**, then **Existing Files**.
2. On the file's row press **More**, then **Add rows from Excel**.
3. The buyer, season and file are already chosen for you.

**Way B, from the start**
1. Open **Workspace**, then **New Excel File Upload**, then **Import Buyer Excel**.
2. Choose the **Buyer**, type the **Season** (for example SU27) and choose your Excel file.
3. A box **Where should the rows go?** appears when the buyer already has a file for that season.

---

## 3. Step 1: choose where the rows go

In the box **Where should the rows go?** you have two choices:

| Choice | What happens |
|---|---|
| **Create a new file** | A separate file is made, as before. |
| **Add to the end of an existing file** | The rows go to the end of the file you pick. |

If you choose **Add to the end of an existing file**, pick the file from the list. Each line shows the file name, how many rows it has and when it was last updated.

Press **Continue**.

If the box does not appear, there is no file for that buyer and season yet, so a new file will be made.

---

## 4. Step 2: match the columns

This screen is the same as for a normal import.

- The system finds the data sheet and the title row. Change them if it guessed wrong.
- Each column of your Excel is matched to a system column. Matching you saved before for this buyer is applied automatically.
- A blue note at the top says the rows will be **added to the end of** your file.
- Press **Continue** to see the preview.

---

## 5. Step 3: check the preview and add

The preview tells you:

- how many rows will be added, and after which row ("**N rows will be added after row X** of your file");
- the styles, colors and rows that need a look.

**Rows already in the file (duplicates).** A row counts as a duplicate when every matched column has the same value as a row already in the file. If there are any, a yellow note shows how many.

- The box **Skip the N rows already in the file** is ticked. These rows are not added again. This is what you want when you upload an updated sheet that repeats old lines.
- Untick it only if you really want the repeated lines added.
- A line that really appears twice in your new Excel is not wrongly skipped: each existing row is matched only once.

Press **Add N Rows to File**.

If every row is a duplicate, nothing is added and you are told so.

---

## 6. After the import

- You are taken to the file. The new rows are at the end, numbered after the last old row.
- Formula columns (for example consumption with wastage, materials to order) are calculated for the new rows.
- The BOM and Style Library include the new rows.
- If the BOM of these styles was already approved, it goes back for approval, like any change.
- Other departments get a notification that rows were added.
- A copy of your Excel is kept by the system, and the change is written in the file's activity log ("Added N rows from ...").

---

## 7. If you added the wrong file: Undo

1. Open **Workspace**, then **Existing Files**.
2. On the file's row press **More**, then **Undo last import (N rows)**.
3. Confirm. Those N rows are removed again and the numbering closes up.

Undo works only when:

- it is the **latest** import into that file (undo a later one first if there are two);
- **nobody has edited** those rows since they were added;
- nothing has been built from them (for example a PO, a payment request or a store receiving).

If it cannot be undone, the message tells you why. Then delete or correct the rows by hand, or ask admin.

Rows added by an import cannot be deleted one by one (they count as part of the original order). Use **Undo** for a mistake.

---

## 8. Quick answers

**I do not see Add rows from Excel under More.** The file is locked, it belongs to another buyer, it has no buyer or season, or your account is not allowed to upload BOM files. Ask your department admin or admin.

**The existing file I want is not in the list.** It is for a different buyer or season. Check the Season spelling (SU27 and SP27 are different seasons).

**Can I change rows that are already in the file from the new Excel?** No. This only adds new rows. To change old rows, edit them in the file.

**Can I join two existing files into one?** Not with this feature.

**The columns of the new Excel are different.** Match them in step 2. Tick **Save matching** if the layout will be used again.

---

## 9. In short

1. **Workspace, Existing Files, More, Add rows from Excel**
2. Choose your Excel, keep **Add to the end of an existing file**
3. Match columns, check the preview
4. Keep **Skip rows already in the file** ticked, press **Add N Rows to File**
5. Wrong file? **More, Undo last import**
