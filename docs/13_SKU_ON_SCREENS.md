# SKU on screens

The SKU (e.g. `STCUT0001`) is given once per Item Master item (`StockItemSku`).
It is shown in a column of its own and is searchable on the General Stock screens.

## Done (screen only)

- **Own column:** Item Master list, Stock Report (before Item Name), Issue and
  Receiving lists/reports, Requisition show page, Monthly Requisition Report,
  Requirement vs Issue, Stock Summary (issue and purchase views).
- **Read-only SKU field** on the Issue, Receiving and Requisition line forms,
  filled when the item is chosen. The item dropdowns keep "SKU — Item name" so a
  line can still be found by typing the SKU.
- **Search by SKU:** Stock Report, Issue, Receiving, Requisition lists, Monthly
  Requisition Report, Requirement vs Issue, Stock Summary, item pickers.

## Not done — needs a decision per export

PDF and Excel exports are unchanged on purpose. The Stock Report Excel is filled
into a reference template by column letter, so adding a column moves the
template. Before adding SKU to an export: get the reference file, decide the
column position, and keep preview and download consistent
(CLAUDE.md > PDF / Excel / Export Rules). Candidates: Stock Report, Issue
Report, Receiving Report, Requisition document, Requirement vs Issue, Monthly
Requisition Report.
