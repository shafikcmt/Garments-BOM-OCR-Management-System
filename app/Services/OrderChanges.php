<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\ExcelFileChangeLog;
use App\Models\ExcelHeader;
use App\Models\MotherStyle;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A style's order change history: edits to the columns Admin marked "Show in
 * Order Changes" (order qty, ship date, PO ...), read from the workspace's own
 * change log. One save that changed the same value on several material lines
 * of an order line shows once, with the number of lines.
 */
class OrderChanges
{
    /**
     * @return Collection<int, array{at: \Illuminate\Support\Carbon, by: ?string, field: ?string, old: ?string, new: ?string, color: ?string, po: ?string, lines: int}>
     */
    public function forMother(MotherStyle $mother, int $limit = 100): Collection
    {
        $headerIds = ExcelHeader::where('track_order_changes', true)->pluck('id')->all();
        if ($headerIds === []) {
            return collect();
        }

        $items = BomItem::query()
            ->whereHas('orderLine', fn ($q) => $q->where('mother_style_id', $mother->id))
            ->with('orderLine:id,gmts_color,customer_po_no')
            ->get(['id', 'order_line_id', 'excel_row_id'])
            ->keyBy('excel_row_id');

        if ($items->isEmpty()) {
            return collect();
        }

        $logs = collect();
        foreach (array_chunk($items->keys()->all(), 900) as $chunk) {
            $logs = $logs->merge(ExcelFileChangeLog::whereIn('excel_row_id', $chunk)->whereIn('excel_header_id', $headerIds)
                ->orderByDesc('id')->limit($limit * 10)->get());
        }

        $users = User::whereIn('id', $logs->pluck('changed_by')->filter()->unique())->pluck('name', 'id');

        return $logs
            ->groupBy(function ($log) use ($items) {
                $line = $items->get($log->excel_row_id)?->order_line_id;

                return implode('|', [$log->batch_id ?: $log->id, $log->excel_header_id, $line, $log->old_value, $log->new_value]);
            })
            ->map(function (Collection $group) use ($items, $users) {
                $log = $group->first();
                $line = $items->get($log->excel_row_id)?->orderLine;

                return [
                    'id' => $log->id,
                    'at' => $log->created_at,
                    'by' => $users[$log->changed_by] ?? null,
                    'field' => $log->header_name,
                    'old' => $log->old_value,
                    'new' => $log->new_value,
                    'color' => $line?->gmts_color,
                    'po' => $line?->customer_po_no,
                    'lines' => $group->count(),
                ];
            })
            ->sortByDesc('id')
            ->take($limit)
            ->values();
    }
}
