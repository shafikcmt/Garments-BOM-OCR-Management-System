import { z } from 'zod'

// Indent Info + at-least-one-quantity validation. Quantities are strings from
// the inputs; the refine below parses them so an empty field reads as 0.
const qty = z
  .string()
  .trim()
  .refine((v) => v === '' || (!isNaN(Number(v)) && Number(v) >= 0), {
    message: 'Enter a number ≥ 0',
  })

export const issueFormSchema = z
  .object({
    poItemId: z.string().min(1, 'Select a PO / material first'),
    indentSection: z.string().min(1, 'Indent Section is required'),
    indentPerson: z.string().min(1, 'Indent Person is required'),
    requisitionNumber: z.string().trim().min(1, 'Requisition No is required'),
    issueDate: z
      .string()
      .min(1, 'Issue Date is required')
      .refine((v) => new Date(v) <= new Date(new Date().toDateString()), {
        message: 'Issue Date cannot be in the future',
      }),
    issueNo: z.string(),
    bulkQty: qty,
    sampleQty: qty,
    liabilityQty: qty,
    deadQty: qty,
    remarks: z.string().max(1000).optional(),
  })
  .refine(
    (d) =>
      num(d.bulkQty) + num(d.sampleQty) + num(d.liabilityQty) + num(d.deadQty) >
      0,
    {
      message: 'Enter at least one of Bulk / Sample / Liability / Dead.',
      path: ['bulkQty'],
    },
  )

export type IssueFormValues = z.infer<typeof issueFormSchema>

export function num(v: string | undefined): number {
  const n = parseFloat(v ?? '')
  return isFinite(n) ? n : 0
}
