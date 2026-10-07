# Future ERP Roadmap — Full Garments ERP Module Specification

> Reference blueprint for where this system is heading. Converted from
> `Full_Garments_ERP_Module_Spec.docx` (received 2026-09-22). Read it at the
> start of a session the way CLAUDE.md asks: not to build these modules now,
> but so that naming, structure and reusable services in today's work do not
> paint us into a corner when they arrive.
>
> **Route and current status:** see `12_ERP_IMPLEMENTATION_GUIDE.md` (gap analysis,
> agreed phase order, open questions).
>
> **How to apply while building:** before adding a new feature, ask whether it
> is a reusable primitive rather than a one-off. The Department / Section /
> Head hierarchy and the approval-chain pattern built for Purchase Requisition
> (`RequisitionReviewChain`, `Department`, `IndentSection.head_user_id`) are the
> model case: generic enough to later serve TnA task escalation, IE line-issue
> reporting or Maintenance breakdown-ticket routing. The thin `employees` table
> (Employee ID login) is the seed of Module 14's PIMS Employee Master — HRM
> extends that same table, it does not create a second one.

সম্পূর্ণ গার্মেন্টস ইন্ডাস্ট্রি ERP — মডিউলভিত্তিক স্ট্রাকচার ও ফিচার লিস্ট

Reference specification — for extending an existing ERP system

# **Table of Contents**

# **1. Overview**

এই ডকুমেন্টে একটি সম্পূর্ণ Garments Industry ERP-এর প্রতিটি মডিউল, তার উদ্দেশ্য এবং মূল ফিচারসমূহ তালিকাভুক্ত করা হয়েছে। Buyer inquiry থেকে শুরু করে Sample, Merchandising, Procurement, Production, Commercial (L/C), Finance, HR ও Reporting — পুরো end-to-end বিজনেস সাইকেল একটি একক, ইন্টিগ্রেটেড প্ল্যাটফর্মে কভার করা হয়েছে, যাতে একবার এন্ট্রি করা ডেটা প্রতিটি সংশ্লিষ্ট ডিপার্টমেন্টে স্বয়ংক্রিয়ভাবে প্রতিফলিত হয় — কোনো দ্বৈত ডেটা-এন্ট্রি ছাড়াই।

এই স্পেসিফিকেশনটি একটি বিদ্যমান সফটওয়্যারে নতুন মডিউল যুক্ত করার (development/extension) রেফারেন্স হিসেবে ব্যবহার করা যাবে।

## **Core Design Principle**

- Single source of truth — কোনো ডেটা দুইবার এন্ট্রি করতে হবে না
- প্রতিটি মডিউল একে অপরের সাথে রিয়েল-টাইম ইন্টিগ্রেটেড
- Transaction-driven data capture — ম্যানুয়াল ডেটা এন্ট্রির বদলে প্রকৃত বিজনেস প্রসেস থেকে ডেটা সংগ্রহ
- প্রতিটি ধাপে অথোরাইজেশন / এপ্রুভাল লেয়ার ও অডিট ট্রেইল
# **2. Module Summary**

| # | Module | Purpose |
| --- | --- | --- |
| 1 | Merchandising | Manages buyer inquiry to order confirmation, pre-costing and BOM. |
| 2 | Sample Development & Management | Tracks proto, fit, PP, size-set and shipment samples end-to-end. |
| 3 | Costing (Pre & Post) | Three-tier costing: pre-costing, BOM-based costing, post-shipment analysis. |
| 4 | TnA (Time & Action) | Task calendar with lead-time templates and escalation. |
| 5 | Procurement / Purchase | BOM-driven, budget-controlled procurement of fabric, trims & services. |
| 6 | Store & Inventory Management | Batch/shade/lot level control for yarn, fabric, trims and accessories. |
| 7 | Production Planning | Line/machine-level capacity, loading and scheduling across processes. |
| 8 | Industrial Engineering (IE) | Operation break-down, SAM calculation, line balancing & efficiency. |
| 9 | Quality Assurance / Quality Control (QA/QC) | Inline, end-line, final inspection and buyer-audit workflows. |
| 10 | Subcontract Management | Tracks in/out subcontract jobs for knitting, dyeing, printing, embroidery, washing. |
| 11 | Commercial (L/C & Trade Finance) | Manages Master/Back-to-Back L/C, sales contracts and export realization. |
| 12 | Shipping & Logistics | Shipment booking, documentation and carrier coordination. |
| 13 | Financial Accounting | General Ledger, Payables, Receivables and Post-Costing, auto-fed from operations. |
| 14 | Human Resource & Payroll | PIMS, attendance, payroll (fixed & piece-rate) and compliance records. |
| 15 | Fixed Assets Management | Acquisition-to-disposal lifecycle with automated depreciation. |
| 16 | Maintenance Management | Preventive & breakdown maintenance for machines and utilities. |
| 17 | CRM / Buyer Management | Central hub for buyer master data, communication and history. |
| 18 | Reporting & Business Intelligence | Cross-module dashboards for management decision-making. |
| 19 | System Administration & User Management | Role-based access control and master configuration. |

# **3. Detailed Module Specification**

## **1. Merchandising**

বায়ার ইনকোয়ারি থেকে অর্ডার কনফার্মেশন পর্যন্ত পুরো প্রসেস

- Buyer inquiry & style creation
- Pre-costing (buyer mode & in-house mode), revision-wise costing
- Order booking and confirmation against buyer PO
- Auto BOM (Bill of Material) generation — color/size/destination-wise
- Size-wise & color-wise costing breakdown
- Multi-stage approval workflow (costing, order, BOM)
- Category / department / buyer-wise merchandising group
- Order progress meeting tracker & document bank
- Auto email/SMS notifications to concerned departments
- Revenue, profit-margin and capacity analytics by buyer/style
## **2. Sample Development & Management**

প্রোটো থেকে শিপমেন্ট স্যাম্পল পর্যন্ত ট্র্যাকিং

- Sample requisition (buyer/style/color/size-wise)
- Sample types: Proto, Fit, PP, Size-Set, Salesman, Shipment sample
- Material requirement & ETA visibility for sample room
- Individual sample Tag ID for full traceability
- Sample costing & SAM (Standard Allowed Minute) feedback
- Buyer feedback and rework recording
- Sample-to-bulk lesson-learned knowledge bank
## **3. Costing (Pre & Post)**

কোটেশন থেকে শিপমেন্ট-পরবর্তী প্রফিটাবিলিটি বিশ্লেষণ

- Cost-head wise break-up: Fabric, Trims, CM, Overhead, Commission
- Dynamic costing groups & templates by product category
- Comparative costing: quoted vs. actual vs. post-shipment
- Fixed-cost allocation by predefined criteria (monthly)
- Profitability report by buyer, style, and season
## **4. TnA (Time & Action)**

অর্ডার-টু-শিপমেন্ট টাস্ক ও ডেডলাইন কন্ট্রোল

- Lead-time based task templates per order/style
- Cost-center based tasks (style, PO, PR level)
- Traffic-light status (on-time / at-risk / overdue)
- User-wise task assignment & supervisor escalation
- Mobile app for field/floor task updates
- Auto email alerts for due & overdue tasks
- Consolidated to-do list per user/department
## **5. Procurement / Purchase**

BOM ও বাজেট ভিত্তিক কেনাকাটা

- BOM-based purchase requisition & PO generation
- Budget control at purchase-order level
- MOQ (Minimum Order Quantity) handling
- Supplier PO revision & document bank (PI, contracts)
- Preferred / designated / buyer-nominated supplier mapping
- Service work order & in-house fabric work order
- Department-wise requisition and item allocation to purchaser
- IOU (advance/loan to supplier) tracking
- Landed-cost calculation (freight, duty, handling)
- Procurement team defined by company/buyer/department/item group
## **6. Store & Inventory Management**

ইয়ার্ন, ফেব্রিক, ট্রিমস — ব্যাচ ও রোল লেভেল কন্ট্রোল

- Rack/bin location management, multiple & virtual warehouses
- Batch / Shade / Lot / Count-wise tracking (critical for yarn & fabric)
- Item-nature wise categorization: Yarn, Fabric, Trims, Dyes & Chemicals (shelf-life sensitive), Consumables
- QC interface — only QC-approved stock enters usable inventory
- Costing method options (e.g., Weighted Average, FIFO)
- BOM-based issue to production; location & cost-center transfer
- Real-time stock status: item ledger, GRN, stock report, aging
- Serialized / capital item tracking
- Auto financial posting to accounting on every stock movement
## **7. Production Planning**

কাটিং, সিউইং, নিটিং/উইভিং, ফিনিশিং — লাইন ও মেশিন লেভেল প্ল্যানিং

- Master (control-tower) planning across factories — total demand vs. capacity
- "What-if" scenario planning for capacity & reallocation
- Line/unit-wise calendar and drag-and-drop scheduling board
- Machine-group level planning for knitting (vs. fixed-line for woven/sewing)
- Standard-minute, efficiency-profile & start-up-curve based loading
- Upstream/downstream auto-sync (cutting, printing/embroidery, finishing, packing)
- Real-time material availability shown on the planning board
- Order-booking wise line-capacity occupation & projected-qty handling
- Pre-production critical-path with color-coded delay alerts
- KPI dashboard (On-Time Delivery %, efficiency, WIP) — BI integration
## **8. Industrial Engineering (IE)**

SAM, লাইন ব্যালেন্সিং, প্রোডাক্টিভিটি ট্র্যাকিং

- Operation bulletin / operation break-down (OB) per style
- SAM (Standard Allowed Minute) calculation and revision history
- Line balancing & bottleneck identification
- Hourly production & efficiency tracking per line/operator
- Learning-curve impact analysis (product-wise)
- Target vs. actual productivity reporting
## **9. Quality Assurance / Quality Control (QA/QC)**

ইনলাইন, এন্ডলাইন, ফাইনাল ও অডিট QC

- Incoming material QC (fabric, trims, accessories)
- Inline / end-of-line inspection with defect codes (AQL-based)
- Final random inspection & pre-shipment inspection reports
- Defect trend analysis and rework tracking
- Buyer QC/audit checklist and compliance scorecard
- Dynamic QC parameter setup per item/process
## **10. Subcontract Management**

নিটিং/ডাইং/প্রিন্টিং/এমব্রয়ডারি — ইনবাউন্ড ও আউটবাউন্ড জব

- Work order issue for specific outsourced process
- Inbound/outbound material movement tracking
- Sub-contractor performance & delivery tracking
- Integration with production, store and accounting modules
## **11. Commercial (L/C & Trade Finance)**

আমদানি-রপ্তানি ডকুমেন্টেশন ও ব্যাংকিং

- Sales Contract & Master L/C creation, lien management
- Back-to-Back L/C with import-limit monitoring
- Import L/C without L/C cases, invoice acceptance
- L/C amendment (export & import side)
- Export document preparation, negotiation & discounting (FDBP)
- TT/DD receive & payment, advance payment (ERQ) handling
- Margin account management: encashment, transfer, cross-payment
- Export-proceeds realization & adjustment (PC, DL, FDBP)
- Force loan & Own Acceptance Purchase (OAP) handling
- Cash-assistance (incentive) management
- Integration with Financial Accounting (auto journal posting)
- MIS reports on trade-finance exposure
## **12. Shipping & Logistics**

বুকিং থেকে শিপমেন্ট ডকুমেন্টেশন

- Shipment booking (sea/air) & forwarder coordination
- Packing list, commercial invoice, bill of lading generation
- Color/size-wise export invoice
- Container/consolidation planning
- Shipment status tracking vs. TnA plan
## **13. Financial Accounting**

GL, AP, AR, পোস্ট-কস্টিং — সব মডিউল থেকে অটো-ইন্টিগ্রেটেড

- General Ledger, Accounts Payable, Accounts Receivable, Post-Costing
- Flexible Chart of Accounts with multi-tier cost centers
- Auto journal generation from every functional transaction (pending approval)
- Configurable mapping of functional-to-financial impact
- AP/AR aging reports for cash-flow visibility
- Multi-currency bank-position tracking
- Balance Sheet & Profit-and-Loss, real-time closing
- Budget vs. actual variance reporting
## **14. Human Resource & Payroll**

অ্যাটেনডেন্স, পে-রোল, বায়ার কমপ্লায়েন্স

- PIMS (Personal Information Management System)
- Attendance, leave, shift & overtime management
- Payroll: fixed salary and piece-rate salary (incl. sweater piece-rate)
- Loan & advance management
- Income-tax management & bonus management
- Service book & final settlement
- Buyer compliance documentation (social/labor compliance)
- Training records & e-file management
## **15. Fixed Assets Management**

অ্যাসেট লাইফসাইকেল — ক্রয় থেকে ডিসপোজাল

- Composite asset management (assemble components into one asset)
- Integration with inventory for maintenance consumables/spares
- Automated depreciation (method configurable per asset)
- Inter-department transfer & maintenance schedule tracking
- Full audit trail: movement, maintenance, disposal
- Utilization & ROI reporting, capex forecasting
## **16. Maintenance Management**

মেশিন প্রিভেন্টিভ ও ব্রেকডাউন মেইনটেন্যান্স

- Machine master & preventive-maintenance schedule
- Breakdown ticket logging and downtime tracking
- Spare-parts consumption linked to Inventory
- Maintenance cost tracking per machine/line
## **17. CRM / Buyer Management**

বায়ার রিলেশনশিপ ও কমিউনিকেশন হাব

- Buyer master data & contact management
- Communication/document log per buyer
- Order history and performance-by-buyer analytics
- Complaint/feedback tracking
## **18. Reporting & Business Intelligence**

রিয়েল-টাইম ড্যাশবোর্ড ও কেপিআই

- Role-based dashboards (Merchandiser, Production, Finance, Management)
- KPI tracking: OTDP, efficiency, profitability, aging
- Drill-down reports: item stock, item ledger, GRN, SR, etc.
- Power BI / embedded analytics integration
## **19. System Administration & User Management**

রোল, পারমিশন ও সিস্টেম কনফিগারেশন

- User, role & permission management (module/menu level)
- Company / branch / factory-wise setup
- Audit log of all critical transactions
- Notification & alert configuration (email/SMS)

# **4. Cross-Module Integration Flow**

নিচের ফ্লো-টি দেখায় একটি টিপিক্যাল অর্ডার কীভাবে সব মডিউল অতিক্রম করে:

- Buyer Inquiry → Merchandising (pre-costing, order booking)
- Order Confirmation → Sample Management (PP/Fit sample approval)
- Confirmed Order → BOM generation → Procurement (material sourcing)
- Material Receipt → Store/Inventory (QC-approved stock in)
- Production Planning → IE (SAM & line balancing) → Shop floor execution
- In-process → QA/QC (inline, end-line inspection)
- Finished Goods → Shipping & Logistics → Commercial (export documentation, L/C)
- Every transaction above → Financial Accounting (auto journal posting)
- Throughout → TnA tracks deadlines; HR & Fixed Assets run in parallel; Reporting/BI consolidates all data
# **5. Suggested Implementation Priority**

- Phase 1 (Foundation): Merchandising, Procurement, Store/Inventory, Financial Accounting
- Phase 2 (Production Core): Production Planning, IE, QA/QC, Subcontract Management
- Phase 3 (Trade & Compliance): Commercial (L/C), Shipping & Logistics, Sample Management, TnA
- Phase 4 (Support Functions): HR & Payroll, Fixed Assets, Maintenance, CRM
- Phase 5 (Intelligence Layer): Reporting/BI dashboards, System Admin hardening