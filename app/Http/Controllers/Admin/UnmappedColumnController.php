<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExcelHeader;
use App\Models\ImportUnmappedColumn;
use App\Services\UnmappedImportColumns;
use Illuminate\Http\Request;

/** Admin > Unmapped Columns: what buyers' files carry that the workspace has no header for. */
class UnmappedColumnController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), [ImportUnmappedColumn::MAPPED, ImportUnmappedColumn::IGNORED], true) ? $request->query('status') : ImportUnmappedColumn::OPEN;

        // One line per title, with the buyers that sent it.
        $groups = ImportUnmappedColumn::with(['buyer:id,buyer_name', 'header:id,header_name'])->where('status', $status)
            ->orderByDesc('last_seen_at')->limit(500)->get()
            ->groupBy('title_key')->map(fn ($rows) => [
                'key' => $rows->first()->title_key,
                'title' => $rows->first()->title,
                'buyers' => $rows->map(fn ($r) => $r->buyer?->buyer_name)->filter()->unique()->values()->all(),
                'times' => $rows->sum('times_seen'),
                'last' => $rows->max('last_seen_at'),
                'header' => $rows->first()->header?->header_name,
            ])->values();

        return view('admin.unmapped-columns', [
            'groups' => $groups,
            'status' => $status,
            'counts' => ImportUnmappedColumn::selectRaw('status, count(distinct title_key) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'headers' => ExcelHeader::where('is_active', true)->orderBy('header_name')->get(['id', 'header_name']),
        ]);
    }

    public function teach(Request $request, UnmappedImportColumns $service)
    {
        $data = $request->validate([
            'title_key' => ['required', 'string', 'max:191'],
            'header_id' => ['required', 'integer', 'exists:excel_headers,id'],
        ], ['header_id.required' => 'Choose the header this column belongs to.']);
        $row = ImportUnmappedColumn::where('title_key', $data['title_key'])->firstOrFail();

        $service->teach($data['title_key'], $row->title, ExcelHeader::findOrFail($data['header_id']), $request->user());

        return back()->with('success', '"' . $row->title . '" is now an Other Name of that header. The next file with this title matches it.');
    }

    public function ignore(Request $request, UnmappedImportColumns $service)
    {
        $key = $request->validate(['title_key' => ['required', 'string', 'max:191']])['title_key'];
        $service->ignore($key, $request->user());

        return back()->with('success', 'Column ignored. It stays in the Ignored list.');
    }

    public function reopen(Request $request, UnmappedImportColumns $service)
    {
        $key = $request->validate(['title_key' => ['required', 'string', 'max:191']])['title_key'];
        $service->reopen($key);

        return back()->with('success', 'Column moved back to the open list.');
    }
}
