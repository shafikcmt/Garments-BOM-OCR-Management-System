import * as React from 'react'
import { Pencil, Trash2, History as HistoryIcon, Search } from 'lucide-react'
import type { BulkIssue } from '@/types'
import { formatDate, trimNum } from '@/lib/utils'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface Props {
  rows: BulkIssue[]
  onDelete: (id: string) => void
}

const PAGE_SIZES = ['5', '10', '25', '50']

export function HistoryTable({ rows, onDelete }: Props) {
  const [query, setQuery] = React.useState('')
  const [pageSize, setPageSize] = React.useState(10)
  const [page, setPage] = React.useState(1)

  const filtered = React.useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return rows
    return rows.filter((r) =>
      [
        r.poNumber,
        r.materialName,
        r.buyerName,
        r.styleNumber,
        r.indentSection,
        r.issueNo,
      ]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q)),
    )
  }, [rows, query])

  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize))
  const current = Math.min(page, pageCount)
  const pageRows = filtered.slice((current - 1) * pageSize, current * pageSize)

  React.useEffect(() => setPage(1), [query, pageSize])

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <HistoryIcon className="h-4 w-4 text-muted-foreground" />
          <h2 className="font-semibold">Bulk Issue History</h2>
          <Badge>{rows.length}</Badge>
        </div>
        <div className="relative">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Filter history…"
            className="w-full pl-8 sm:w-64"
          />
        </div>
      </div>

      <div className="rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow className="bg-slate-50/80 hover:bg-slate-50/80">
              <TableHead>Date</TableHead>
              <TableHead>PO / Material</TableHead>
              <TableHead className="text-right">Bulk</TableHead>
              <TableHead className="text-right">Sample</TableHead>
              <TableHead className="text-right">Liab.</TableHead>
              <TableHead className="text-right">Dead</TableHead>
              <TableHead className="text-right">Action</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {pageRows.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className="py-12 text-center text-muted-foreground"
                >
                  {query
                    ? 'No issues match your filter.'
                    : 'No bulk issues recorded yet.'}
                </TableCell>
              </TableRow>
            ) : (
              pageRows.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(r.issueDate)}
                  </TableCell>
                  <TableCell>
                    <div className="font-medium">
                      {r.poNumber} · {r.materialName}
                    </div>
                    <div className="text-xs text-muted-foreground">
                      {[r.buyerName, r.styleNumber, r.materialColor, r.size]
                        .filter(Boolean)
                        .join(' · ')}
                    </div>
                    {r.indentSection && (
                      <Badge variant="secondary" className="mt-1">
                        {r.indentSection}
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell className="tnum text-right font-medium text-emerald-600">
                    {trimNum(r.bulkQty)}
                  </TableCell>
                  <TableCell className="tnum text-right font-medium text-blue-600">
                    {trimNum(r.sampleQty)}
                  </TableCell>
                  <TableCell className="tnum text-right font-medium text-amber-600">
                    {trimNum(r.liabilityQty)}
                  </TableCell>
                  <TableCell className="tnum text-right font-medium text-rose-600">
                    {trimNum(r.deadQty)}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <Button variant="ghost" size="icon" aria-label="Edit">
                            <Pencil className="h-4 w-4" />
                          </Button>
                        </TooltipTrigger>
                        <TooltipContent>Edit</TooltipContent>
                      </Tooltip>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Delete"
                            className="text-muted-foreground hover:bg-rose-50 hover:text-rose-600"
                            onClick={() => onDelete(r.id)}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </TooltipTrigger>
                        <TooltipContent>Delete</TooltipContent>
                      </Tooltip>
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
        <div className="flex items-center gap-2 text-muted-foreground">
          <span>Show</span>
          <Select
            value={String(pageSize)}
            onValueChange={(v) => setPageSize(Number(v))}
          >
            <SelectTrigger className="h-8 w-[72px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {PAGE_SIZES.map((s) => (
                <SelectItem key={s} value={s}>
                  {s}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <span>per page</span>
        </div>

        <div className="flex items-center gap-2">
          <span className="text-muted-foreground">
            Page {current} of {pageCount}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={current <= 1}
            onClick={() => setPage((p) => p - 1)}
          >
            Previous
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={current >= pageCount}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </Button>
        </div>
      </div>
    </div>
  )
}
