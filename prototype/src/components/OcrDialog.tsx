import * as React from 'react'
import { Camera, ScanLine, Loader2, CheckCircle2 } from 'lucide-react'
import type { OcrResult } from '@/types'
import { cn } from '@/lib/utils'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'

interface Props {
  open: boolean
  onOpenChange: (o: boolean) => void
  onApply: (result: OcrResult) => void
}

type Phase = 'idle' | 'scanning' | 'review'

// Mocked OCR. A real build would POST the frame to the OCR service and map the
// returned fields; here we simulate a capture + extraction with a confidence.
const MOCK_EXTRACT: OcrResult = {
  poNumber: 'HB26FA0005',
  oiNumber: 'OI-4471',
  materialCode: '1000772',
  description: 'Accessories',
  quantity: 100,
  confidence: 0.92,
}

export function OcrDialog({ open, onOpenChange, onApply }: Props) {
  const [phase, setPhase] = React.useState<Phase>('idle')
  const [draft, setDraft] = React.useState<OcrResult>(MOCK_EXTRACT)

  React.useEffect(() => {
    if (open) {
      setPhase('idle')
      setDraft(MOCK_EXTRACT)
    }
  }, [open])

  const capture = () => {
    setPhase('scanning')
    window.setTimeout(() => {
      setDraft(MOCK_EXTRACT)
      setPhase('review')
    }, 1400)
  }

  const confidencePct = Math.round(draft.confidence * 100)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <ScanLine className="h-5 w-5 text-primary" /> Scan Document
          </DialogTitle>
          <DialogDescription>
            Capture a requisition or invoice — extracted fields fill the filter
            and search for a matching PO.
          </DialogDescription>
        </DialogHeader>

        {phase !== 'review' && (
          <div className="flex flex-col items-center gap-4 py-4">
            <div className="relative flex h-44 w-full items-center justify-center overflow-hidden rounded-lg border-2 border-dashed bg-slate-50">
              {phase === 'scanning' ? (
                <div className="flex flex-col items-center gap-2 text-muted-foreground">
                  <Loader2 className="h-8 w-8 animate-spin" />
                  <span className="text-sm">Extracting fields…</span>
                </div>
              ) : (
                <div className="flex flex-col items-center gap-2 text-muted-foreground">
                  <Camera className="h-8 w-8" />
                  <span className="text-sm">Camera preview</span>
                </div>
              )}
              <div className="pointer-events-none absolute inset-4 rounded border border-primary/40" />
            </div>
            <Button
              onClick={capture}
              disabled={phase === 'scanning'}
              className="w-full"
            >
              {phase === 'scanning' ? 'Scanning…' : 'Capture'}
            </Button>
          </div>
        )}

        {phase === 'review' && (
          <div className="space-y-4 py-2">
            <div className="flex items-center gap-2">
              <Badge
                variant={
                  confidencePct >= 80
                    ? 'success'
                    : confidencePct >= 50
                      ? 'warning'
                      : 'destructive'
                }
              >
                <CheckCircle2 className="mr-1 h-3 w-3" />
                {confidencePct}% confidence
              </Badge>
              <span className="text-xs text-muted-foreground">
                Review and correct before applying.
              </span>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field
                label="PO Number"
                value={draft.poNumber ?? ''}
                onChange={(v) => setDraft((d) => ({ ...d, poNumber: v }))}
                highlight={confidencePct >= 80}
              />
              <Field
                label="OI Number"
                value={draft.oiNumber ?? ''}
                onChange={(v) => setDraft((d) => ({ ...d, oiNumber: v }))}
              />
              <Field
                label="Material Code"
                value={draft.materialCode ?? ''}
                onChange={(v) => setDraft((d) => ({ ...d, materialCode: v }))}
              />
              <Field
                label="Quantity"
                value={String(draft.quantity ?? '')}
                onChange={(v) =>
                  setDraft((d) => ({ ...d, quantity: Number(v) || 0 }))
                }
              />
              <div className="col-span-2">
                <Field
                  label="Description"
                  value={draft.description ?? ''}
                  onChange={(v) => setDraft((d) => ({ ...d, description: v }))}
                />
              </div>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          {phase === 'review' && (
            <Button
              onClick={() => {
                onApply(draft)
                onOpenChange(false)
              }}
            >
              Apply & Search
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function Field({
  label,
  value,
  onChange,
  highlight,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  highlight?: boolean
}) {
  return (
    <div>
      <Label className="mb-1 block text-xs text-muted-foreground">{label}</Label>
      <Input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={cn(highlight && 'border-emerald-300 bg-emerald-50/40')}
      />
    </div>
  )
}
