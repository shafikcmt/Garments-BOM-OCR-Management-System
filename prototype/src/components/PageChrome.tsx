import { ChevronRight, Boxes } from 'lucide-react'

const CRUMBS = ['Store', 'Buyer / Style Stock', 'Bulk Issuing']

export function Breadcrumb() {
  return (
    <nav aria-label="Breadcrumb" className="mb-4">
      <ol className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
        {CRUMBS.map((c, i) => (
          <li key={c} className="flex items-center gap-1">
            {i > 0 && <ChevronRight className="h-3.5 w-3.5" aria-hidden />}
            <span
              className={
                i === CRUMBS.length - 1 ? 'font-medium text-foreground' : ''
              }
              aria-current={i === CRUMBS.length - 1 ? 'page' : undefined}
            >
              {c}
            </span>
          </li>
        ))}
      </ol>
    </nav>
  )
}

export function PageHeader() {
  return (
    <div className="mb-6 flex items-center gap-4">
      <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
        <Boxes className="h-6 w-6" />
      </div>
      <div>
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
          Buyer / Style Stock
        </p>
        <h1 className="text-2xl font-semibold tracking-tight">Bulk Issuing</h1>
        <p className="text-sm text-muted-foreground">
          Each issue splits into Bulk / Sample / Liability / Dead.
        </p>
      </div>
    </div>
  )
}
