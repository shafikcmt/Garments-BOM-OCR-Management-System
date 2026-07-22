import { ClipboardList } from 'lucide-react'
import type { PoItem } from '@/types'
import { dash } from '@/lib/utils'
import { StockIndicator } from '@/components/StockIndicator'

interface Props {
  item: PoItem
  drafting?: number
}

/** Read-only identity for the selected PO / material — never editable. */
export function SummaryCard({ item, drafting }: Props) {
  const fields: { label: string; value: string | number | undefined }[] = [
    { label: 'Buyer', value: item.buyerName },
    { label: 'Season', value: item.season },
    { label: 'Style No', value: item.styleNumber },
    { label: 'PO Number', value: item.poNumber },
    { label: 'Material Name', value: item.materialName },
    { label: 'Description', value: item.materialDescription },
    { label: 'Art. No', value: item.artNo },
    { label: 'SAP Code', value: item.sapCode },
    { label: 'GMTS Color', value: item.gmtsColorName },
    { label: 'Material Color', value: item.materialColor },
    { label: 'Size', value: item.size },
    { label: 'Unit', value: item.unit },
  ]

  return (
    <div>
      <div className="mb-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        <ClipboardList className="h-4 w-4" />
        PO / Material Summary
        <span className="font-normal normal-case tracking-normal text-muted-foreground/70">
          — read-only
        </span>
      </div>

      <div className="grid grid-cols-2 gap-x-4 gap-y-4 rounded-lg border bg-slate-50/60 p-4 sm:grid-cols-4">
        {fields.map((f) => (
          <div key={f.label} className="min-w-0">
            <div className="mb-0.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {f.label}
            </div>
            <div
              className="break-words text-sm font-semibold"
              title={f.value ? String(f.value) : undefined}
            >
              {dash(f.value)}
            </div>
          </div>
        ))}
      </div>

      <div className="mt-4">
        <StockIndicator
          available={item.availableStock}
          booked={item.bookedStock}
          drafting={drafting}
        />
      </div>
    </div>
  )
}
