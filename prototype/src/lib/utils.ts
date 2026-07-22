import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/** Drop trailing zeros: 104.0000 -> "104", 12.5000 -> "12.5". */
export function trimNum(n: number | null | undefined): string {
  if (n === null || n === undefined || !isFinite(n)) return '—'
  return String(parseFloat(Number(n).toFixed(4)))
}

/** Dash-fallback for empty display values. */
export function dash(v: string | number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  const s = String(v).trim()
  return s === '' ? '—' : s
}

/** BI-YYYYMMDD-XXXX auto issue number. */
export function generateIssueNo(date: Date, seq: number): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `BI-${y}${m}${d}-${String(seq).padStart(4, '0')}`
}

export function formatDate(date: Date): string {
  return date.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

export function todayISO(): string {
  return new Date().toISOString().slice(0, 10)
}
