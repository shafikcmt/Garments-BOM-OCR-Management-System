/** A material line under a Booking PO — the pickable, issuable unit. */
export interface PoItem {
  id: string
  poNumber: string
  oiNumber?: string
  buyerName: string
  season?: string
  styleNumber: string
  materialName: string
  materialDescription?: string
  artNo?: string
  sapCode?: string
  gmtsColorName: string
  materialColor: string
  size: string
  unit: string
  /** Running (available) stock for this line. */
  availableStock: number
  /** Already committed to other issues. */
  bookedStock: number
}

/** Which handle the user searches POs by (Receiving-style filter). */
export type FilterType =
  | 'po_no'
  | 'oi_no'
  | 'sap_code'
  | 'art_no'
  | 'style_no'

/** A recorded bulk issue (history row). Mirrors the Laravel BulkIssue model. */
export interface BulkIssue {
  id: string
  issueNo: string
  issueDate: Date
  indentSection: string
  indentPerson: string
  requisitionNumber: string
  season?: string
  buyerName: string
  styleNumber: string
  poNumber: string
  oiNumber?: string
  gmtsColorName: string
  materialName: string
  materialDescription?: string
  artNo?: string
  sapCode?: string
  materialColor: string
  size: string
  unit: string
  bulkQty: number
  sampleQty: number
  liabilityQty: number
  deadQty: number
  remarks?: string
  availableStock: number
  createdAt: Date
}

/** Draft quantities the user is entering for one item, before submit. */
export interface IssueLineDraft {
  itemId: string
  bulkQty: string
  sampleQty: string
  liabilityQty: string
  deadQty: string
}

export interface OcrResult {
  poNumber?: string
  oiNumber?: string
  materialCode?: string
  description?: string
  quantity?: number
  confidence: number // 0..1
}
