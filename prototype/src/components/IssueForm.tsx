import * as React from 'react'
import { useForm, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, ClipboardCheck, Scale, Lightbulb } from 'lucide-react'
import type { PoItem } from '@/types'
import { issueFormSchema, num, type IssueFormValues } from '@/lib/schema'
import { INDENT_PERSONS, INDENT_SECTIONS } from '@/data/mock'
import { cn, generateIssueNo, todayISO, trimNum } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Combobox } from '@/components/Combobox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

interface Props {
  item: PoItem
  nextSeq: number
  onSubmit: (values: IssueFormValues) => void
  onDraftTotalChange: (total: number) => void
  formId?: string
}

const QTY_FIELDS = [
  { name: 'bulkQty', label: 'Bulk Qty', tone: 'emerald', dot: '🟢' },
  { name: 'sampleQty', label: 'Sample Qty', tone: 'blue', dot: '🔵' },
  { name: 'liabilityQty', label: 'Liability Qty', tone: 'amber', dot: '🟠' },
  { name: 'deadQty', label: 'Dead Qty', tone: 'rose', dot: '🔴' },
] as const

const TONE: Record<string, { text: string; ring: string; border: string }> = {
  emerald: {
    text: 'text-emerald-600',
    ring: 'focus-visible:ring-emerald-400',
    border: 'border-emerald-200',
  },
  blue: {
    text: 'text-blue-600',
    ring: 'focus-visible:ring-blue-400',
    border: 'border-blue-200',
  },
  amber: {
    text: 'text-amber-600',
    ring: 'focus-visible:ring-amber-400',
    border: 'border-amber-200',
  },
  rose: {
    text: 'text-rose-600',
    ring: 'focus-visible:ring-rose-400',
    border: 'border-rose-200',
  },
}

export function IssueForm({
  item,
  nextSeq,
  onSubmit,
  onDraftTotalChange,
  formId = 'issue-form',
}: Props) {
  const issueNo = React.useMemo(
    () => generateIssueNo(new Date(), nextSeq),
    [nextSeq],
  )

  const {
    register,
    handleSubmit,
    control,
    watch,
    reset,
    formState: { errors },
  } = useForm<IssueFormValues>({
    resolver: zodResolver(issueFormSchema),
    defaultValues: {
      poItemId: item.id,
      indentSection: '',
      indentPerson: '',
      requisitionNumber: '',
      issueDate: todayISO(),
      issueNo,
      bulkQty: '',
      sampleQty: '',
      liabilityQty: '',
      deadQty: '',
      remarks: '',
    },
  })

  // Reset when the selected item changes.
  React.useEffect(() => {
    reset({
      poItemId: item.id,
      indentSection: '',
      indentPerson: '',
      requisitionNumber: '',
      issueDate: todayISO(),
      issueNo,
      bulkQty: '',
      sampleQty: '',
      liabilityQty: '',
      deadQty: '',
      remarks: '',
    })
  }, [item.id, issueNo, reset])

  // Live total -> parent (drives the free-stock indicator).
  const b = watch('bulkQty')
  const s = watch('sampleQty')
  const l = watch('liabilityQty')
  const d = watch('deadQty')
  React.useEffect(() => {
    onDraftTotalChange(num(b) + num(s) + num(l) + num(d))
  }, [b, s, l, d, onDraftTotalChange])

  const submit = handleSubmit((values) => {
    onSubmit(values)
  })

  return (
    <form id={formId} onSubmit={submit} className="space-y-6">
      {/* --- Indent Info --- */}
      <section>
        <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          <ClipboardCheck className="h-4 w-4" /> Indent Info
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <Label className="mb-1.5 block">
              Indent Section <span className="text-rose-500">*</span>
            </Label>
            <Controller
              control={control}
              name="indentSection"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger
                    className={cn(
                      errors.indentSection &&
                        'border-rose-400 focus:ring-rose-400',
                    )}
                  >
                    <SelectValue placeholder="Select…" />
                  </SelectTrigger>
                  <SelectContent>
                    {INDENT_SECTIONS.map((sec) => (
                      <SelectItem key={sec} value={sec}>
                        {sec}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError msg={errors.indentSection?.message} />
          </div>

          <div>
            <Label className="mb-1.5 block">
              Indent Person <span className="text-rose-500">*</span>
            </Label>
            <Controller
              control={control}
              name="indentPerson"
              render={({ field }) => (
                <Combobox
                  options={INDENT_PERSONS}
                  value={field.value}
                  onChange={field.onChange}
                  allowCustom
                  placeholder="Search person…"
                  invalid={!!errors.indentPerson}
                />
              )}
            />
            <FieldError msg={errors.indentPerson?.message} />
          </div>

          <div>
            <Label className="mb-1.5 block">
              Requisition No <span className="text-rose-500">*</span>
            </Label>
            <Input
              {...register('requisitionNumber')}
              placeholder="e.g. REQ-8891"
              className={cn(
                errors.requisitionNumber && 'border-rose-400 focus-visible:ring-rose-400',
              )}
            />
            <FieldError msg={errors.requisitionNumber?.message} />
          </div>

          <div>
            <Label className="mb-1.5 block">
              Issue Date <span className="text-rose-500">*</span>
            </Label>
            <Input
              type="date"
              max={todayISO()}
              {...register('issueDate')}
              className={cn(
                errors.issueDate && 'border-rose-400 focus-visible:ring-rose-400',
              )}
            />
            <FieldError msg={errors.issueDate?.message} />
          </div>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label className="mb-1.5 block">Issue No</Label>
            <Input
              {...register('issueNo')}
              readOnly
              tabIndex={-1}
              className="bg-muted text-muted-foreground"
            />
            <p className="mt-1 text-xs text-muted-foreground">
              Auto-generated (BI-YYYYMMDD-XXXX)
            </p>
          </div>
        </div>
      </section>

      {/* --- Quantities --- */}
      <section>
        <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          <Scale className="h-4 w-4" /> Issue Quantities
        </div>
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {QTY_FIELDS.map((f) => {
            const tone = TONE[f.tone]
            return (
              <div
                key={f.name}
                className={cn('rounded-lg border bg-card p-4', tone.border)}
              >
                <Label
                  className={cn('mb-2 flex items-center gap-1.5', tone.text)}
                >
                  <span aria-hidden>{f.dot}</span> {f.label}
                </Label>
                <Input
                  type="number"
                  step="0.0001"
                  min="0"
                  placeholder="0"
                  className={cn('tnum', tone.ring)}
                  {...register(f.name)}
                />
                {f.name === 'bulkQty' && (
                  <p className="mt-2 text-xs text-muted-foreground">
                    Running: <span className="tnum font-medium">{trimNum(item.availableStock)}</span>
                  </p>
                )}
              </div>
            )
          })}
        </div>
        {errors.bulkQty?.message && (
          <div className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
            {errors.bulkQty.message}
          </div>
        )}
      </section>

      {/* --- Remarks --- */}
      <section>
        <Label className="mb-1.5 block">Remarks</Label>
        <Textarea rows={2} maxLength={1000} {...register('remarks')} />
      </section>

      <div className="flex flex-wrap items-center gap-4">
        <Button type="submit" size="lg">
          <Plus className="h-4 w-4" /> Add Issue
        </Button>
        <p className="flex items-start gap-1.5 text-sm text-muted-foreground">
          <Lightbulb className="mt-0.5 h-4 w-4 shrink-0" />
          Enter at least one of the four quantities. Liability &amp; Dead can
          later be reused (transfer to bulk) on the Closing Stock page.
        </p>
      </div>
    </form>
  )
}

function FieldError({ msg }: { msg?: string }) {
  if (!msg) return null
  return <p className="mt-1 text-xs font-medium text-rose-600">{msg}</p>
}
