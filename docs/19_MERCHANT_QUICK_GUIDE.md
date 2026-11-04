# Merchant Quick Guide

For merchandisers. Read it once, then keep it open for your first week. The full manual is `17_USER_MANUAL.md` (section 2 is for you).

## 1. The idea in one minute

You give the system **the order and the BOM**. After that the other departments update their own part, and you only **watch** the progress.

```
You: import the buyer's Excel (or create the BOM on screen)
  ↓
You: submit the BOM for approval  →  Merchandising Head approves
  ↓
Supply Chain: books the material (PO), gets the PI, pays
  ↓
Store: receives and issues the material
  ↓
You: watch everything on your Dashboard and Style Status
```

You never type vendor, PO, PI, payment, shipment or store columns. Those belong to other departments and they fill them.

## 2. Every morning: 2 minutes on the Dashboard

Open **Dashboard** (first item of the left menu).

1. Look at the **small chips** under the cards. The red ones need you first:
   - **Late bookings**: material that should have been ordered by now.
   - **BOM approvals pending**: BOMs waiting for approval.
   - **My follow-ups due**: tasks given to you.
   - **Shipping in 30 days**: orders leaving soon.
2. Press a chip or a card. It opens the list behind it.
3. Look at **Needs Attention** (lower on the page). Press **View** on a style to see what is late.
4. Look at **Upcoming Shipments** to see how much material has reached the store for each order.

What the big cards mean:

| Card | In simple words |
|---|---|
| Total Orders | Order lines you look after |
| Order Quantity | Total pieces |
| Booking Pending | Materials with no PO yet |
| PI Pending | PO placed, supplier's PI not received |
| Payment Pending | PI received, not paid |
| PRA Pending | Payment requests waiting for approval |

**Material Pipeline** chart: how many materials are at each step, from **Not ordered** to **Issued**. If almost everything is in "Not ordered", booking has not started.

You see only the buyers given to you.

## 3. A new order arrives from the buyer

### Option A: the buyer sent an Excel

1. Left menu **Workspace**, then **Import Buyer Excel**.
2. **Step 1.** Choose the **buyer**, type the **season** (for example `SP27`), choose the file, press **Continue**.
3. **Step 2.** The system matches the buyer's columns to its own.
   - Yellow row = not matched. Choose the right system column, or **Skip this column** for vendor / payment / other departments' columns.
   - Choose **Mother and child styles** (leave **Automatic** if unsure).
   - Tick **Save this matching for [buyer]**. Next time the same layout matches itself.
4. **Step 3.** Check the preview: number of mother styles, child styles and colours against the buyer's file. Press **Import**.
5. Wait for the page to finish (10 to 20 seconds for a big file). The file appears in **Workspace, Existing Files**.

No Excel from the buyer? Press **Download Sample**, fill one row per material line, upload it the same way.

### Option B: no Excel, make the BOM on screen

1. Left menu **Style Status**, open the style, press **Create BOM**.
2. Add the **order lines** (colour, customer PO, ship date, garment qty).
3. Add the **materials** (type, description, unit, consumption, wastage %). Tip: type two letters of a description and pick from the suggestions, or use **Start from an earlier BOM**.
4. Press **Create BOM**.

## 4. Get the BOM approved

1. Open the style: **Style Status**, then **View Status**.
2. In the **BOM approval** box press **Submit for Approval**.
3. The Merchandising Head approves or returns it with a reason.
4. If it is **returned**: fix it in the Workspace, then press **Submit Again**.
5. When it is **approved**, you and Supply Chain are told. If you change the BOM after that, it says **Changed after approval** and must be approved again.

## 5. Follow the order until the goods arrive

1. Left menu **Style Status**, choose the buyer, then **View Season**.
2. Styles that need attention are at the top. Press **View Status** on one.
3. On the style page read:
   - the red / yellow bar (late or due soon),
   - **Orders** (one line per colour and PO),
   - **Materials** (PO, vendor, PI, payment, received quantity and **Stage**).
4. **Stage** means the furthest step reached: Not ordered → Ordered → PI received → Paid → Received → Issued.

If a number looks wrong, check that row in the **Workspace**. The figures come from what the other departments filled in.

## 6. Ask someone to chase

1. On the style page find the **Follow-up** box.
2. Choose **Who should follow up**, the **Due date**, and a **Note** if you want.
3. Press **Create Follow-up**. The person gets a message.

Your own tasks are under **Follow-ups** in the left menu. Press **Mark Done** when finished.

## 7. If you change an order that already has a PO

Change it in the Workspace and press **Save**. Supply Chain is told automatically that the PO source changed. You do not need to phone them.

## 8. Other things on your menu

| Menu | Use it for |
|---|---|
| **Style Library** | Buyer → season → mother → child styles |
| **Vendors** | See, add or edit vendors (with bank details); Excel import and export |
| **OCR Summary** | Contract, Material, Payment and Clearance summaries, and **Download OCR Excel** |
| **Samples** | Add a sample on the style page; see all samples here, late ones first |
| **My Approvals / BOM Approvals** | Things waiting for your approval |
| **Purchase Requisition** | Store items your section needs |

## 9. Common problems

| What you see | What to do |
|---|---|
| Dashboard says "No orders are linked yet" | Import the buyer's Excel or create a BOM first |
| Booking Pending is very high | Normal on a new order. It falls when Supply Chain generates POs |
| A buyer's column is yellow in Step 2 | Choose the system column, or skip it |
| A style is missing from the Style Library | Check Step 3 of the import: rows with no style are imported but not linked |
| Import stops half way | Press **Cancel Import** and try again; if it repeats, tell IT |
| You cannot see a buyer | Ask Admin to assign the buyer to you |

## 10. Your daily checklist

- [ ] Open the **Dashboard**, check the red chips.
- [ ] Open **Follow-ups**, finish or reschedule today's tasks.
- [ ] Check **BOM Approvals** for anything returned to you.
- [ ] Open the styles under **Needs Attention** and ask Supply Chain or the vendor to act.
- [ ] Look at **Upcoming Shipments** for material not yet received.
