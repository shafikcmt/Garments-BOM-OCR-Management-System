# Merchant User Guide (Bangla + English)

Merchandiser-der jonno step-by-step guide. Left menu-r protita item-er kaj ekhane ache. **Boro 7-ta (Workspace, Style Library, Follow-ups, Vendors, Team Management, Purchase Requisition, PRA Approvals)** details-e deya hoyeche, baki gulo choto kore.

Full manual: `17_USER_MANUAL.md`.

## 0. System-ta kibhabe kaj kore (1 minute)

Tumi system-e **order ar BOM** dao. Tarpor onno department nijer part fill kore, ar tumi **dekho**.

```
Tumi: buyer-er Excel import koro (ba screen-e BOM banao)
  ↓
Tumi: BOM approval-e pathao  →  Merchandising Head approve kore
  ↓
Supply Chain: PO kore, PI ane, payment kore
  ↓
Store: material receive ar issue kore
  ↓
Tumi: Dashboard ar Style Status-e progress dekho
```

Vendor, PO, PI, payment, shipment, store column tumi type korbe na. Oigulo onno department-er.

## 1. Left menu ek nojore

| Menu | Kaj | Importance |
|---|---|---|
| **Dashboard** | Aj ki dekhte hobe | Roj |
| **My Approvals** | Tomar approval-er jonno ja wait kore (requisition) | Roj |
| **Follow-ups** | Tomar to-do list | **Boro** |
| **BOM Approvals** | BOM approve / return | Roj |
| **Style Library** | Buyer → Season → Mother → Child style | **Boro** |
| **Workspace** | Excel upload ar BOM sheet edit | **Boro** |
| **Vendors** | Vendor list, add, edit, Excel | **Boro** |
| **Style Status** | Style / season-er progress | Roj |
| **OCR Summary** | Contract / Material / Payment / Clearance summary | Maajhe maajhe |
| **Samples** | Sample track | Maajhe maajhe |
| **Reports** | BOM Line Report | Maajhe maajhe |
| **Store Reports** | Style / Buyer / Material-wise stock report | Maajhe maajhe |
| **Purchase Requisition** | Office / section-er jonno store item chaoa | **Boro** |
| **Team Management** | Tomar team-er user manage (shudhu department admin) | **Boro** |
| **PRA Approvals** | Payment request check / approve | **Boro** |

Tumi shudhu tomar buyer-gulo dekhbe. Kono buyer na dekhle Admin-ke bolo buyer assign korte. Kono menu na thakle bujhbe tomar oi permission nai, Admin-ke bolo.

---

## 2. Dashboard

1. **Dashboard** open koro.
2. Card-er niche **chips** dekho. Lal chip age: **Late bookings**, **BOM approvals pending**, **My follow-ups due**, **Shipping in 30 days**.
3. Chip ba card-e click korle list khule.
4. **Needs Attention**-e style-er **View** chap, ki late dekho.
5. **Upcoming Shipments**-e dekho shipment-er age material store-e eshese kina.

| Card | Mane |
|---|---|
| Total Orders | Tomar order line |
| Order Quantity | Total piece |
| Booking Pending | Je material-er PO hoyni |
| PI Pending | PO hoyeche, PI ashe ni |
| Payment Pending | PI eshese, payment hoyni |
| PRA Pending | Payment request approval-er opekkhay |

Notun order-e Booking Pending beshi thakbe, eta normal. Supply Chain PO korle komte thakbe.

---

## 3. Workspace (sobcheye important)

**Workspace** e duita tab:
- **New Excel File Upload**: notun buyer file import.
- **Existing Files**: ager file open kore edit.

### 3.1 Notun Excel import (Import Buyer Excel)

1. **Workspace → New Excel File Upload → Import Buyer Excel**.
2. **Step 1 Choose file:** **Buyer** choose koro, **Season** type koro (jemon `SP27`), file choose koro, **Continue**.
3. **Step 2 Match columns:**
   - Buyer-er column system-er column-er sathe mile jay. **Holud (yellow) row** = mile ni. Thik system column choose koro, ba **Skip this column** (vendor / payment / onno department-er column skip korai bhalo).
   - **Mother and child styles** option: bujhte na parle **Automatic** rakho.
   - **Save this matching for [buyer]** tick koro. Porer bar ei buyer-er same layout auto match hobe.
   - Column-er nam bhul/notun hole Admin **Excel Headers → Other Names**-e oi nam add kore dibe.
4. **Step 3 Preview:** mother style, child style, colour koyta, buyer-er file-er sathe milao. **Rows to check** dekho. Thik hole **Import**.
5. Page sesh howa porjonto wait koro (boro file-e 10-20 second). File **Existing Files**-e ashbe.

Excel na thakle **Download Sample** chap, fill kore upload koro.

Import-er kichu niyom:
- Style `ALL` hole (poly bag, carton) seta **common item**, Style Library-te jabe na.
- **Order Qty** column tumi fill koro; **Division** style-er product group-e jay.
- Vendor, PO, PI, payment, shipment, receipt, issue column empty thake; oi department fill kore.
- Upload option na dekhle ba "Upload is not enabled for your account" dekhale Admin-ke bolo.
- Majhpothe thamle **Cancel Import** chap.

### 3.2 Existing File edit kora

1. **Existing Files** theke file open koro. **Excel Work Sheet** dekhabe.
2. Cell-e click kore value change koro. Tumi shudhu tomar department-er column edit korte parbe. Formula (calculated) column nijei calculate hoy, edit hoy na.
3. Kaj sesh hole **Save Changes** chap. Save na korle change hobe na.
4. **Search:** upore "Search entire document, then press Enter" box-e type kore Enter. Column-er niche chhoto box-e "Search + Enter" diye column-wise filter kora jay.
5. Column onek hole **Show All Columns** ba column search diye khujo.
6. **Delete:** shudhu je row ekhono edit hoyni ar kono kaj-e lage ni oi row-er **Delete** button thake. Ekadhik row bhule dite chaile tick kore **Delete Selected**.
7. **Duplicate:** row select kore copy kora jay. Copy **row-er niche** ba **file-er sheshe** rakhte parbe.
8. **History (n)** button-e dekhbe ke kobe ki change korechhe.
9. **Download** (original file) chaile oi button.
10. Browser band hole draft local-e save thake. Abar open korle **Restore & Save** diye ferot ano.

Lock message:
- **File Locked**: Admin file lock korechhe. Admin-ke unlock korte bolo.
- **PO Locked**: PO kora row; Admin lock rekhechhe.

### 3.3 PO thaka order change korle

Kono order column (style, colour, quantity, item) change kore **Save** dile Supply Chain auto message pay: "PO source changed". Phone korar dorkar nai.

### 3.4 Excel chara BOM banano

**Style Status** → style open → **Create BOM**. Order line (colour, customer PO, ship date, garment qty) ar material (type, description, unit, consumption, wastage %) add kore **Create BOM**. **Start from an earlier BOM** diye ager BOM copy kora jay.

---

## 4. Style Library

Buyer → Season → Mother style → Child style.

- **Mother style** = main style number.
- **Child style** = linked style ba fit (PETITE, CURVY).

**Season add:** buyer choose → **Add Season** → code (`SP27`) → **Save Season**.

**Style add:** season choose, box-e comma diye style likho: `882635, 720193(PETITE), 748268 (866317)`. Bracket-er bhitorer ta child. **Add Styles** chap.

Excel import korle style auto add hoy, tai hate type kora dorkar hoy na.

Style-er kache **View Status** chaple oi style-er order, material, sample, follow-up dekha jay.

Mother style kibhabe khuje: (1) file-er Mother Style column, (2) style code-er bracket-er age-er number, (3) na hole style nijei mother.

---

## 5. Follow-ups

Follow-up = ekta chhoto task: **ekjon manush, ekta style, ekta due date**.

**Onno kauke dewa:**
1. **Style Status** ba **Style Library** theke style-er **View Status**.
2. **Follow-up** box-e **Who should follow up** choose koro, **Due date** (default 3 din pore), dorkar hole **Note**.
3. **Create Follow-up** chap. Se bell icon-e message pabe.

Style-e late material thakle task-er nam auto hoy, jemon "Order 4 late material(s) for style 748268".

**Nijer list:** left menu **Follow-ups**.
- **My follow-ups**: tomake deya.
- **Given by me**: tumi onno-ke diyecho.
- Rong: **Overdue** (lal), **Due today** (holud), **Coming up** (sobuj).
- Kaj sesh hole **Mark Done**. Je diyeche se jante pare. Bhul hole **Reopen**.

Roj shokale ~8:30-e overdue / due today thakle ekta reminder message ashe. Menu-r lal number = ajker bakhi kaj.

---

## 6. Vendors

1. **Vendors** open koro. List-e nam, contact, item type, location, bank, status. Search box-e nam, code, contact, city bole khujo.
2. **Bank** column: ki bank ache ar account-er sesh 4 digit, ba **No bank details**.

**Add Vendor:** **Add Vendor** → nam (dorkar) → contact → **Bank Details** (bank, branch, account name/number, routing, SWIFT; sob optional) → **Save Vendor**.

**Edit:** row-er **Edit** → change → **Save Changes**. Vendor code onno vendor-er sathe same hobe na. Inactive vendor list-e thake, **Inactive** likha thake.

**Export Excel:** list-er upore. Account number shudhu sesh 4 digit dekhay (surokkhar jonno).

**Import Excel:**
1. **Import Excel → Download Template**. Ek row = ek vendor. Shudhu **Vendor Name** dorkar. Dhusor example row muche dao.
2. File choose kore **Import**.
3. File ekbare check hoy: kono row-te bhul thakle **kichui import hoy na**, bhul row-er list dekhay. Thik kore abar upload koro.
4. Same nam-er vendor (boro/chhoto hater jai hok) **skip** hoy, overwrite hoy na. Change korte **Edit** use koro.

Vendor Admin-er list-e-o dekhay. Delete shudhu Admin korte pare. Store-er supplier alada (Store nijer ta manage kore).

---

## 7. Team Management

Eta **shudhu department admin** (department-er head) dekhe. Tumi na dekhle tomar ei access nai.

- Tomar department-er user-der list dekhbe: nam, role, status, last sign in.
- **View / Edit** diye user-er detail dekha ba badla jay. **Reset password** diye notun password deya jay (minimum 6 character, user-ke nijer hate dao).
- Notun user: **Create User** → details → role → **Additional Permissions**.
- **Permission:** tumi shudhu oi permission dite parbe, ja tomar nijer ache. Ja tomar nai, ta "not yours to grant" likha lock thake.
- Permission matrix-e protita section ek line. **Select all** diye line / module-er sob tick kora jay (**Clear** diye tule). Role theke asha permission lock (change hoy na).

User-ke notun kaj dite chaile (jemon Final Requisition) oi section-er box tick koro, Save koro, user-ke abar login korte bolo.

---

## 8. Purchase Requisition (office / section-er item chaoa)

**Purchase Requisition** menu theke tomar section-er jonno store-er item chao.

Duita dhoron:

| | Monthly | Individual |
|---|---|---|
| Kokhon | Mashik regular dorkar | Ekbarer, urgent ba special |
| Store ki kore | Onno sheet-er sathe **merge** kore | Merge kore na |
| Approval | Final Requisition-e Store Head, Finance, Operation Head | Finance & Commercial Head, tarpor Operation Head |

**Step-by-step:**
1. **Purchase Requisition** open koro.
2. **New Monthly Requisition** ba **New Individual Requisition** chap.
3. **Section**, **Requested By**, date check koro. "Section" fakka thakle Admin-ke bolo tomar Requisition Section set korte.
4. **Item Name** box-e click kore item khujo, **Qty Req.** dao, dorkar hole **Remarks**.
5. **Add Another Item** diye aro item. Bhul line **Remove**.
6. **Save as Draft** (pore sesh korbe) ba **Submit Requisition**.
7. Tomar **Section Head** (na thakle Department Head) **My Approvals**-e peye **Approve** ba note diye **Return** kore.
8. Return hole thik kore abar **Submit**.
9. Approve hole Store **Received** kore.

Tumi nijer sheet-er status list-e dekhbe. Stock figure (hand stock, safety stock) shudhu Store, Admin, Management dekhe; tumi item ar quantity dekhbe.

**Head hole:** tomar kache section-er sheet asle **My Approvals** (menu-te lal number) theke **Approve** ba **Return**. Return-e reason dite hoy. Nijer sheet nije approve kora jay na.

---

## 9. PRA Approvals (payment request)

PRA = Payment Request. Menu-te lal number = tomar action-er opekkhay koyta.

1. **PRA Approvals** open koro. **Pending PRA Approvals** list-e PRA Number, Requested By, Buyer / Supplier, Total PI Amount, Progress.
2. Row-e click kore detail kholo: **Request Details**, item-er PI amount, **Reviewers**.
3. **Your Decision** dekho. Tumi **Checker** hole check kore **approve** dile PRA **approver**-er kache jay. Tumi **Approver** hole tomar approve-i sesh dhap.
4. **Reject** korte hole reason likho (**Confirm Reject**). Reject korle process thame, ar je banieche se reason shoho jante pare.
5. Comment optional.

Payment hoye gele Accounts payment reference dey. Merchant-er kono column type korte hoy na.

---

## 10. Baki menu (short)

**My Approvals:** requisition-er jonno tomar Approve / Return-er list.

**BOM Approvals:** lal number = tomar approve-er jonno koyta wait kore. **Waiting for me** → **Open Style** → BOM check → **Approve**, ba reason diye **Return to Merchant** (reason dite-i hoy). Nijer submit kora BOM nije approve kora jay na. BOM pathate: style page-er **BOM approval** box → **Submit for Approval**. Return hole fix kore **Submit Again**. Approve-er por BOM change korle **Changed after approval** dekhay, abar approve nite hoy.

**Style Status:** buyer choose → **View Season** → style-er **View Status**. Stage: Not ordered → Ordered → PI received → Paid → Received → Issued. Lal **Late**, holud **Due soon** shudhu je material-er order hoyni tar jonno.

**OCR Summary:** buyer (ar season) choose. Tab: **Contract**, **Material**, **Payment**, **Clearance** Summary. **Download OCR Excel** diye workbook.

**Samples:** style page-e **Samples** box-e type choose kore **Add Sample**. **Update** diye status (Requested, In progress, Sent to buyer, Approved…). Late hole lal **Late**. **Samples** menu-te sob sample ek jaygay.

**Reports (BOM Line Report):** filter dao (Material / SAP Code, From date), **Apply**; abar shuru korte **Reset**. PDF / Excel download kora jay.

**Store Reports:** **Report Type** choose koro: **Style-wise**, **Buyer-wise**, **Material-wise**. Filter: Buyer, Style, Season, PO Number, GMTS Color, Material / SAP Code, From / To date. **Apply**, **Reset**. Export kora jay.

---

## 11. Common somossa

| Ki dekho | Ki korbe |
|---|---|
| Dashboard-e "No orders are linked yet" | Buyer Excel import ba BOM create koro |
| Booking Pending onek beshi | Notun order-e normal; Supply Chain PO korle komo |
| Step 2-te column holud | System column choose koro ba skip koro |
| Style Library-te style nai | Import Step 3-e "Rows to check" dekho |
| Import majhpothe thame gese | **Cancel Import** chap, abar try koro; bar bar hole IT-ke bolo |
| Buyer dekha jay na | Admin-ke buyer assign korte bolo |
| Upload / menu-r option nai | Admin-ke permission dite bolo |
| File locked | Admin-ke unlock korte bolo |
| Requisition submit-e error | Screenshot niye Admin / IT-ke dao |

## 12. Roj-er checklist

- [ ] **Dashboard**-er lal chip dekho.
- [ ] **Follow-ups**: ajker kaj sesh / reschedule.
- [ ] **BOM Approvals** ar **My Approvals**: kono return / wait ache kina.
- [ ] **PRA Approvals**: tomar action baki kina (jodi tumi checker / approver).
- [ ] **Needs Attention**-er style khule Supply Chain / vendor-ke bolo.
- [ ] **Upcoming Shipments**: material ashe ni emon order.
