import { cn } from '@/lib/utils'
import { trimNum } from '@/lib/utils'

interface Props {
  available: number
  booked: number
  /** Quantity currently being drafted (subtracted from free in real time). */
  drafting?: number
}

/** 🟢 Available · 🔴 Booked · ⚪ Free — recomputed live as the user types. */
export function StockIndicator({ available, booked, drafting = 0 }: Props) {
  const free = Math.max(available - booked - drafting, 0)
  const over = drafting > available - booked

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Pill color="emerald" label="Available (Running)" value={available} />
      <Pill color="rose" label="Booked" value={booked} />
      <Pill
        color={over ? 'rose' : 'slate'}
        label="Free"
        value={free}
        emphasize={over}
      />
      {over && (
        <span className="text-xs font-medium text-rose-600">
          Exceeds available stock
        </span>
      )}
    </div>
  )
}

function Pill({
  color,
  label,
  value,
  emphasize,
}: {
  color: 'emerald' | 'rose' | 'slate'
  label: string
  value: number
  emphasize?: boolean
}) {
  const dot = {
    emerald: 'bg-emerald-500',
    rose: 'bg-rose-500',
    slate: 'bg-slate-300',
  }[color]

  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-sm',
        emphasize && 'border-rose-200 bg-rose-50',
      )}
    >
      <span className={cn('h-2.5 w-2.5 rounded-full', dot)} />
      <span className="text-muted-foreground">{label}:</span>
      <span className="tnum font-semibold">{trimNum(value)}</span>
    </span>
  )
}
