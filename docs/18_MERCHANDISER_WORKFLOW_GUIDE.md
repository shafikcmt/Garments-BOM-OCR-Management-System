# What a merchandiser does, and how the system should follow it

Written for the owner (a developer, not a garments person) and for future work on this project. Plain language first, system mapping second. Where something is a guess about THIS factory, it is marked **ASK** and listed in section 6 as an interview question.

## 1. The merchandiser in one sentence

The merchandiser is the factory's **owner of an order from the buyer's first enquiry to the shipment**. They are the single person the buyer talks to, and the person every internal department (supply chain, store, production, commercial) comes to for answers.

## 2. The life of an order, and what the merchandiser does at each step

| # | Step | What the merchandiser does | Typical document / data |
|---|---|---|---|
| 1 | **Enquiry** | Receives the buyer's design / tech pack for a style; asks for a quotation | Tech pack, sketches, target price |
| 2 | **Pre-costing and quotation** | Works out the cost by head (fabric, trims, embroidery, wash, CM, overhead, commission); decides the price to quote; revises after negotiation | Cost sheet, revisions |
| 3 | **Sampling** | Gets samples made and approved: proto, fit, size-set, pre-production (PP), salesman, shipment samples. Sends them, records buyer comments, repeats until approved | Sample requests, tracking, buyer feedback |
| 4 | **Order confirmation** | Receives the buyer's PO: style, colours, size breakdown, quantities, delivery dates, destinations. Checks capacity with planning; matches the PO to the quotation; arranges the contract / L/C with Commercial | Buyer PO, contract |
| 5 | **BOM / material requirement** | Lists every material per style, colour and size: consumption, wastage, specification, nominated supplier; turns it into required quantities | BOM, consumption sheet, approved lab dips / swatches |
| 6 | **Material booking** | Asks Supply Chain / Purchase to order: fabric, trims, labels, packing. Follows up PI, delivery dates, in-house dates, shortages. Handles buyer-nominated suppliers | Booking sheet, PO, PI |
| 7 | **Time and Action (TnA)** | Keeps the critical path on time: booking, lab dip, PP approval, fabric in-house, cutting start, production, inspection, shipment. Chases the late ones | TnA calendar, follow-up list |
| 8 | **Production follow-up** | With planning and quality: line loading, issues, buyer audits, inspections | Production reports, inspection reports |
| 9 | **Shipment** | Packing list, final inspection, shipping documents with Commercial; ex-factory date; shipment; claims after shipment | Invoice, packing list, B/L |
| 10 | **Reporting** | Order status, shipment forecast, margin, delays, for management | Weekly status, order sheet |

Most of a merchandiser's day is **following up**: asking people for dates, quantities and status, and telling the buyer. A system helps them mainly by making that information visible without asking.

## 3. How the well-known systems model the same thing

| Product | Strong at | Idea worth copying |
|---|---|---|
| **Kandaree Apparel** (your reference) | Merchandising, BOM, costing, TnA, procurement, commercial, inventory, accounts, one integrated flow | Enter data once; style-wise material status; costing by head, buyer and in-house modes, revisions; five-stage approval layer; TnA with traffic lights and a to-do list; auto BOM from order + consumption; knowledge bank of problems from past orders |
| **FastReactPlus** and similar planning / TnA tools | Time and action calendars, capacity | Task templates by lead time; a critical path per order; colour-coded alerts |
| **PLM tools** (Centric, Gerber YuniquePLM and similar) | Tech pack, BOM, samples, buyer collaboration | Style as the centre: a style has samples, a BOM, costings and documents; BOM copied from a previous style or season; material library |
| **SAP (Fashion Management, FICO)** | Finance, material master, controlling | Material master with codes; auto journals from operations; cost centres. Used here only as the future Accounts reference |

Common to all of them: **the style is the centre**; costing, samples, BOM, orders, materials, shipments hang off it; and every department sees the same record.

## 4. Where this project stands against that flow

| Step | Status in the system |
|---|---|
| 1 Enquiry | Not built |
| 2 Pre-costing | **Built** (cost heads, Buyer / In-house, revisions, margin). Not built: costing approval, size/colour costing, comparison with the BOM |
| 3 Sampling | Not built |
| 4 Order confirmation | Partly: orders arrive through the BOM file (order lines: colour, customer PO, ship date, quantity). No separate buyer-PO screen, no size breakdown |
| 5 BOM | **Built**: Excel import for any buyer layout, **Create BOM** on screen, calculated columns, **BOM approval**. **Copy from another style/season and a learned material library are built.** Not built: size-wise, automatic BOM |
| 6 Material booking | **Built** on the Supply Chain side (booking / PO, PI, payment request, payment); changes by the merchant reach Supply Chain; PO / PI / payment status flows back |
| 7 TnA | **Partly**: follow-up tasks, to-do list, reminders, late-material lights. Not built: task templates by lead time, automatic tasks, critical path, e-mail |
| 8 Production | Not built |
| 9 Shipment | Partly in Commercial; not tied to the style |
| 10 Reporting | **Built**: Style Status by buyer, season and style; Management view |

## 5. Excel upload or create on screen: both

Real merchandisers get a BOM from **three places**, so the system should accept all three and end in the SAME place (the workspace rows):

1. **The buyer sends a BOM / sheet** -> **Import Buyer Excel** (built; any layout, remembered per buyer).
2. **Carry-over style**: "this style is like last season's, change a few materials" -> **copy a BOM** from another style or season (built, same buyer).
3. **Build it by hand** from the tech pack -> **Create BOM** (built); faster with the **material library** (built; learned from earlier BOMs).

Fully automatic BOM generation is the Kandaree way, but it needs a **consumption master** and the order's size and colour breakdown first. It is a later step, not a replacement for the three above.

## 6. Questions to ask a merchandiser (an hour with one is worth a week of guessing)

Ask them to show you a real order, not describe it.

1. **Where does an order start for you?** Buyer tech pack, PO, e-mail, a sheet? Show me the last one you got.
2. **How do you make a BOM today?** From what, in which file, how long does one style take? How much is copied from an older style?
3. **Do you cost every style before quoting?** Who gives you the consumption and the CM figure? How often does the price get revised?
4. **Which samples do you manage, and how do you track them?** (This decides whether a sample module is needed next.)
5. **Does the buyer's PO come with size and colour breakdown?** Is the BOM quantity per size, per colour, or per whole order?
6. **Who orders the material: you or Supply Chain?** What exactly do you send them, and when?
7. **What do you chase every day?** Write the top five questions you ask other departments. (Those become the screens.)
8. **What dates do you track for an order?** (PCD, PP approval, fabric in-house, cut start, ship date...) Who sets each?
9. **What do you report to management, how often, in which format?**
10. **What goes wrong most?** Late fabric, wrong consumption, buyer changes, shortages? (Priorities come from here.)
11. **ASK (this factory):** buyers here send a "sourcing agent" style sheet (the Ann Taylor / Macy's files carry PCD, "order to be placed by", PI, payment columns). Is the merchant or the buyer's agent filling those?

## 7. Suggested build order from here

1. ~~Copy BOM + material library~~ (done).
2. **TnA depth**: task templates by lead time, tasks created from rules (for example a late material), e-mail reminders.
3. **Sampling** (if question 4 says it matters): sample requests, status, buyer comments.
4. **Order / buyer PO screen** with colour and size breakdown (needed before size-wise BOM and costing).
5. **Comparative costing**: pre-costing against the actual BOM cost.
6. **Automatic BOM** from consumption master + order.
7. Shipment, then production follow-up.

Re-order this list after the merchandiser interview.

The plan for all departments together (Supply Chain, Commercial, Store, Accounts, Management) and the release order across them is in `docs/12_ERP_IMPLEMENTATION_GUIDE.md` §11.
