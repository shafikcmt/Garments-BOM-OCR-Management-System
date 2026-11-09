<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\BomItem;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\MotherStyle;
use App\Models\User;
use App\Support\DepartmentRoles;

/**
 * Tells the Store when a merchant's edit changes the order (quantity, ship
 * date, PO ... the columns Admin marked "Show in Order Changes"), so nobody
 * receives or issues against an old number. One notification per style per
 * save. The Store roles come from the department setup, not from code.
 */
class OrderChangeNotifier
{
    /**
     * @param  list<array{row_id:int, header_id:int, header_name:?string}>  $changedCells
     * @return int  number of styles reported
     */
    public function notify(ExcelFile $file, array $changedCells, User $actor): int
    {
        $tracked = ExcelHeader::where('track_order_changes', true)->pluck('id')->all();
        $cells = collect($changedCells)->filter(fn ($c) => in_array((int) $c['header_id'], $tracked, true));
        if ($cells->isEmpty()) {
            return 0;
        }

        $items = BomItem::whereIn('excel_row_id', $cells->pluck('row_id')->unique()->all())
            ->with('orderLine:id,mother_style_id')->get(['id', 'excel_row_id', 'order_line_id'])->keyBy('excel_row_id');

        $byStyle = $cells->groupBy(fn ($c) => $items->get($c['row_id'])?->orderLine?->mother_style_id);
        $byStyle = $byStyle->filter(fn ($g, $id) => $id !== null && $id !== '');
        if ($byStyle->isEmpty()) {
            return 0;
        }

        $styles = MotherStyle::whereIn('id', $byStyle->keys())->get()->keyBy('id');
        $recipients = User::where('id', '!=', $actor->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', DepartmentRoles::rolesFor('store')))
            ->get()
            ->reject(fn (User $u) => $file->isBuyerLockedForUser($u));

        foreach ($byStyle as $styleId => $group) {
            $style = $styles->get($styleId);
            if (! $style) {
                continue;
            }
            $fields = $group->pluck('header_name')->filter()->unique()->values();
            $message = sprintf('%s changed %s on style %s (%d line(s)). Check receiving and issue against the new figures.',
                $actor->name, $fields->take(4)->implode(', ') ?: 'order details', $style->style_no, $group->pluck('row_id')->unique()->count());

            foreach ($recipients as $user) {
                AppNotification::create([
                    'user_id' => $user->id,
                    'actor_id' => $actor->id,
                    'excel_file_id' => $file->id,
                    'type' => 'order_changed',
                    'title' => 'Order changed',
                    'message' => $message,
                    'url' => route('uploaded-files.show', $file->id),
                    'data' => ['mother_style_id' => $style->id, 'style_no' => $style->style_no, 'fields' => $fields->all()],
                ]);
            }
        }

        return $byStyle->count();
    }
}
