import * as React from 'react'
import { Camera, Search, X } from 'lucide-react'
import type { FilterType, PoItem } from '@/types'
import { cn, trimNum } from '@/lib/utils'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Button } from '@/components/ui/button'

const FILTER_LABELS: Record<FilterType, string> = {
  po_no: 'PO Number',
  oi_no: 'OI Number',
  sap_code: 'SAP Code',
  art_no: 'Art. No',
  style_no: 'Style Number',
}

function handleValue(item: PoItem, type: FilterType): string {
  switch (type) {
    case 'po_no':
      return item.poNumber
    case 'oi_no':
      return item.oiNumber ?? ''
    case 'sap_code':
      return item.sapCode ?? ''
    case 'art_no':
      return item.artNo ?? ''
    case 'style_no':
      return item.styleNumber
  }
}

interface Props {
  items: PoItem[]
  selected: PoItem | null
  onSelect: (item: PoItem) => void
  onClear: () => void
  onScan: () => void
  searchRef?: React.RefObject<HTMLInputElement>
}

export function PoFilterSelect({
  items,
  selected,
  onSelect,
  onClear,
  onScan,
  searchRef,
}: Props) {
  const [filterType, setFilterType] = React.useState<FilterType>('po_no')
  const [open, setOpen] = React.useState(false)

  if (selected) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2">
          <span className="text-sm text-muted-foreground">Selected:</span>
          <span className="font-semibold">{selected.poNumber}</span>
          <span className="text-muted-foreground">·</span>
          <span className="text-sm">{selected.materialName}</span>
        </div>
        <Button variant="outline" size="sm" onClick={onClear}>
          <X className="h-4 w-4" /> Change Selection
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
      <div className="w-full sm:w-56">
        <label className="mb-1.5 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
          Filter by
        </label>
        <Select
          value={filterType}
          onValueChange={(v) => setFilterType(v as FilterType)}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {(Object.keys(FILTER_LABELS) as FilterType[]).map((t) => (
              <SelectItem key={t} value={t}>
                {FILTER_LABELS[t]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex flex-1 items-end gap-2">
        <div className="flex-1">
          <label className="mb-1.5 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {FILTER_LABELS[filterType]}
          </label>
          <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
              <button
                type="button"
                className={cn(
                  'flex h-9 w-full items-center gap-2 rounded-md border border-input bg-card px-3 text-sm text-muted-foreground shadow-sm transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                )}
              >
                <Search className="h-4 w-4 shrink-0 opacity-50" />
                Click or type to search {FILTER_LABELS[filterType]}…
              </button>
            </PopoverTrigger>
            <PopoverContent
              className="w-[--radix-popover-trigger-width] p-0"
              align="start"
            >
              <Command
                filter={(value, search) =>
                  value.toLowerCase().includes(search.toLowerCase()) ? 1 : 0
                }
              >
                <CommandInput
                  ref={searchRef}
                  placeholder={`Search ${FILTER_LABELS[filterType]}…`}
                />
                <CommandList>
                  <CommandEmpty>No matching records.</CommandEmpty>
                  <CommandGroup heading={`${FILTER_LABELS[filterType]}s`}>
                    {items.map((item) => {
                      const handle = handleValue(item, filterType)
                      const searchable = [
                        handle,
                        item.poNumber,
                        item.styleNumber,
                        item.buyerName,
                        item.materialName,
                      ]
                        .filter(Boolean)
                        .join(' ')
                      return (
                        <CommandItem
                          key={item.id}
                          value={searchable}
                          onSelect={() => {
                            onSelect(item)
                            setOpen(false)
                          }}
                        >
                          <div className="flex w-full flex-col gap-0.5">
                            <div className="flex items-center justify-between gap-2">
                              <span className="font-medium">
                                {handle || item.poNumber}
                              </span>
                              <span className="tnum text-xs text-emerald-600">
                                {trimNum(item.availableStock)} avail
                              </span>
                            </div>
                            <span className="text-xs text-muted-foreground">
                              {[
                                item.styleNumber,
                                item.buyerName,
                                item.materialName,
                              ]
                                .filter(Boolean)
                                .join(' · ')}
                            </span>
                          </div>
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </div>

        <Button
          type="button"
          variant="outline"
          size="icon"
          onClick={onScan}
          title="Scan document (OCR)"
          aria-label="Scan document with camera"
        >
          <Camera className="h-4 w-4" />
        </Button>
      </div>
    </div>
  )
}
