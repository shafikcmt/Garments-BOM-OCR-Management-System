import * as React from 'react'
import { TooltipProvider } from '@/components/ui/tooltip'
import { ToastProvider, useToast } from '@/components/ui/toast'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { Breadcrumb, PageHeader } from '@/components/PageChrome'
import { Card, CardContent } from '@/components/ui/card'
import { PoFilterSelect } from '@/components/PoFilterSelect'
import { SummaryCard } from '@/components/SummaryCard'
import { IssueForm } from '@/components/IssueForm'
import { HistoryTable } from '@/components/HistoryTable'
import { OcrDialog } from '@/components/OcrDialog'
import { SummarySkeleton } from '@/components/Skeletons'
import { MOCK_HISTORY, MOCK_PO_ITEMS } from '@/data/mock'
import type { BulkIssue, OcrResult, PoItem } from '@/types'
import type { IssueFormValues } from '@/lib/schema'
import { num } from '@/lib/schema'
import { Boxes, Search } from 'lucide-react'

function BulkIssuePage() {
  const { toast } = useToast()
  const [items] = React.useState<PoItem[]>(MOCK_PO_ITEMS)
  const [selected, setSelected] = React.useState<PoItem | null>(null)
  const [loadingSummary, setLoadingSummary] = React.useState(false)
  const [history, setHistory] = React.useState<BulkIssue[]>(MOCK_HISTORY)
  const [draftTotal, setDraftTotal] = React.useState(0)
  const [ocrOpen, setOcrOpen] = React.useState(false)
  const [seq, setSeq] = React.useState(MOCK_HISTORY.length + 1)
  // Remount the form after a successful submit to clear it.
  const [formKey, setFormKey] = React.useState(0)

  const searchRef = React.useRef<HTMLInputElement>(null)

  const selectItem = React.useCallback((item: PoItem) => {
    // Simulate the async summary lookup after picking a PO.
    setLoadingSummary(true)
    setDraftTotal(0)
    window.setTimeout(() => {
      setSelected(item)
      setLoadingSummary(false)
    }, 550)
  }, [])

  const clearSelection = React.useCallback(() => {
    setSelected(null)
    setDraftTotal(0)
  }, [])

  const applyOcr = React.useCallback(
    (r: OcrResult) => {
      const match = items.find(
        (i) =>
          i.poNumber === r.poNumber ||
          i.sapCode === r.materialCode ||
          i.artNo === r.materialCode,
      )
      if (match) {
        selectItem(match)
        toast({
          variant: 'success',
          title: 'PO matched from scan',
          description: `${match.poNumber} · ${match.materialName} (${Math.round(
            r.confidence * 100,
          )}% confidence)`,
        })
      } else {
        toast({
          variant: 'error',
          title: 'No matching PO',
          description: 'Adjust the extracted fields or search manually.',
        })
      }
    },
    [items, selectItem, toast],
  )

  const submitIssue = React.useCallback(
    (values: IssueFormValues) => {
      if (!selected) return
      const issue: BulkIssue = {
        id: `bi-${Date.now()}`,
        issueNo: values.issueNo,
        issueDate: new Date(values.issueDate),
        indentSection: values.indentSection,
        indentPerson: values.indentPerson,
        requisitionNumber: values.requisitionNumber,
        season: selected.season,
        buyerName: selected.buyerName,
        styleNumber: selected.styleNumber,
        poNumber: selected.poNumber,
        oiNumber: selected.oiNumber,
        gmtsColorName: selected.gmtsColorName,
        materialName: selected.materialName,
        materialDescription: selected.materialDescription,
        artNo: selected.artNo,
        sapCode: selected.sapCode,
        materialColor: selected.materialColor,
        size: selected.size,
        unit: selected.unit,
        bulkQty: num(values.bulkQty),
        sampleQty: num(values.sampleQty),
        liabilityQty: num(values.liabilityQty),
        deadQty: num(values.deadQty),
        remarks: values.remarks,
        availableStock: selected.availableStock,
        createdAt: new Date(),
      }
      setHistory((prev) => [issue, ...prev])
      setSeq((s) => s + 1)
      setDraftTotal(0)
      setFormKey((k) => k + 1)
      toast({
        variant: 'success',
        title: 'Bulk issue recorded',
        description: `${issue.issueNo} · closing stock updated.`,
      })
    },
    [selected, toast],
  )

  const deleteIssue = React.useCallback(
    (id: string) => {
      setHistory((prev) => prev.filter((r) => r.id !== id))
      toast({ variant: 'info', title: 'Issue removed' })
    },
    [toast],
  )

  const newIssue = React.useCallback(() => {
    clearSelection()
    setFormKey((k) => k + 1)
    searchRef.current?.focus()
  }, [clearSelection])

  // Keyboard shortcuts: Ctrl+K focus search, Ctrl+Enter submit, Ctrl+N new.
  React.useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (!(e.ctrlKey || e.metaKey)) return
      const key = e.key.toLowerCase()
      if (key === 'k') {
        e.preventDefault()
        searchRef.current?.focus()
      } else if (key === 'enter') {
        e.preventDefault()
        const form = document.getElementById(
          'issue-form',
        ) as HTMLFormElement | null
        form?.requestSubmit()
      } else if (key === 'n') {
        e.preventDefault()
        newIssue()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [newIssue])

  return (
    <div className="min-h-screen w-full bg-background">
      <div className="mx-auto w-full max-w-none px-4 py-6 sm:px-6 lg:px-8">
        <Breadcrumb />
        <PageHeader />

        <div className="flex flex-col gap-6">
          {/* Step 1 — Filter & Select */}
          <Card>
            <CardContent className="p-6">
              <div className="mb-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <Search className="h-4 w-4" /> Filter &amp; Select
              </div>
              <PoFilterSelect
                items={items}
                selected={selected}
                onSelect={selectItem}
                onClear={clearSelection}
                onScan={() => setOcrOpen(true)}
                searchRef={searchRef}
              />
            </CardContent>
          </Card>

          {/* Step 2 — Summary (loading skeleton, then read-only card) */}
          {loadingSummary && <SummarySkeleton />}
          {!loadingSummary && selected && (
            <Card>
              <CardContent className="p-6">
                <SummaryCard item={selected} drafting={draftTotal} />
              </CardContent>
            </Card>
          )}

          {/* Steps 3 & 4 — Indent Info + Quantities (RHF form) */}
          {!loadingSummary && selected && (
            <Card>
              <CardContent className="p-6">
                <IssueForm
                  key={formKey}
                  item={selected}
                  nextSeq={seq}
                  onSubmit={submitIssue}
                  onDraftTotalChange={setDraftTotal}
                />
              </CardContent>
            </Card>
          )}

          {/* Empty state before a PO is chosen */}
          {!loadingSummary && !selected && (
            <Card>
              <CardContent className="flex flex-col items-center justify-center gap-2 py-16 text-center">
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                  <Boxes className="h-6 w-6 text-muted-foreground" />
                </div>
                <p className="font-medium">No PO selected</p>
                <p className="max-w-sm text-sm text-muted-foreground">
                  Search and select a PO / material above to load its summary and
                  record a bulk issue.{' '}
                  <span className="whitespace-nowrap">Press Ctrl+K</span> to focus
                  search.
                </p>
              </CardContent>
            </Card>
          )}

          {/* Step 5 — History */}
          <Card>
            <CardContent className="p-6">
              <HistoryTable rows={history} onDelete={deleteIssue} />
            </CardContent>
          </Card>
        </div>
      </div>

      <OcrDialog open={ocrOpen} onOpenChange={setOcrOpen} onApply={applyOcr} />
    </div>
  )
}

export default function App() {
  return (
    <ErrorBoundary>
      <ToastProvider>
        <TooltipProvider delayDuration={200}>
          <BulkIssuePage />
        </TooltipProvider>
      </ToastProvider>
    </ErrorBoundary>
  )
}
