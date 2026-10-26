<?php

namespace App\Http\Controllers\Merchant;

use App\Exports\OcrExport;
use App\Exports\OcrSheet;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Services\OcrSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * OCR summaries (Contract, Material, Payment, Clearance) on screen, and the
 * OCR workbook download (Database sheet + the four summaries). Read-only;
 * Merchandising, Admin and Management. A buyer-confined user sees only their
 * buyers.
 */
class OcrSummaryController extends Controller
{
    public function index(Request $request, OcrSummary $summary)
    {
        [$buyers, $buyer, $seasons, $season] = $this->scope($request);
        $tab = array_key_exists($request->query('tab'), OcrSummary::TABS) ? $request->query('tab') : 'contract';

        $data = $summary->build($buyer ? [$buyer->id] : $buyers->pluck('id')->all(), $season?->id);

        return view('merchant.ocr-summary', $data + compact('buyers', 'buyer', 'seasons', 'season', 'tab') + ['tabs' => OcrSummary::TABS]);
    }

    public function excel(Request $request, OcrSummary $summary)
    {
        [$buyers, $buyer, , $season] = $this->scope($request);
        $data = $summary->build($buyer ? [$buyer->id] : $buyers->pluck('id')->all(), $season?->id);
        $db = $summary->databaseSheet($data['materials'], $buyer?->id, $request->user());

        $fmtDate = fn ($d) => $d?->format('d-M-Y');

        $sheets = [
            new OcrSheet('Database', $db['headings'], $db['rows']),
            new OcrSheet('Contract Summary',
                ['Buyer', 'Season', 'Style', 'Child', 'GMTS Color', 'Contract / PO', 'Ship Date', 'Order Qty', 'Materials', 'Ordered %', 'PI Received %', 'Paid %', 'Received %', 'Late'],
                $data['contract']->map(fn ($r) => [$r['buyer'], $r['season'], $r['style'], $r['child'], $r['color'], $r['contract'], $fmtDate($r['ship_date']), $r['order_qty'], $r['materials'], $r['ordered_pct'], $r['pi_pct'], $r['paid_pct'], $r['received_pct'], $r['late']])->all()),
            new OcrSheet('Material Summary',
                ['Type', 'Material', 'SAP Code', 'Material Color', 'Unit', 'Lines', 'BOM Qty', 'Ordered Qty', '(Short) / Excess', 'Received Qty', 'Received %', 'Not Ordered Lines', 'Vendors'],
                $data['material']->map(fn ($r) => [$r['type'], $r['description'], $r['sap_code'], $r['material_color'], $r['uom'], $r['lines'], $r['bom_qty'], $r['ordered_qty'], $r['short_excess'], $r['received_qty'], $r['received_pct'], $r['not_ordered'], $r['vendors']])->all()),
            new OcrSheet('Payment Summary',
                ['Supplier', 'POs', 'PIs', 'Lines Without PI', 'PI Amount', 'Paid', 'Pending', 'Unpaid Lines', 'Next Payment Due'],
                $data['payment']->map(fn ($r) => [$r['vendor'], $r['pos'], $r['pis'], $r['pi_pending'], $r['pi_amount'], $r['paid_amount'], $r['pending_amount'], $r['unpaid_lines'], $fmtDate($r['next_due'])])->all()),
            new OcrSheet('Clearance Summary',
                ['BL / AWB', 'Ship Mode', 'Forwarder', 'Suppliers', 'POs', 'Lines', 'ETA', 'Committed In-house', 'Arrival Status'],
                $data['clearance']->map(fn ($r) => [$r['bl'], $r['ship_mode'], $r['forwarder'], $r['vendors'], $r['pos'], $r['lines'], $fmtDate($r['eta']), $fmtDate($r['committed_inhouse']), $r['arrival_status']])->all()),
        ];

        $name = 'OCR-' . Str::slug(trim(($buyer?->buyer_name ?? 'all-buyers') . ' ' . ($season?->code ?? ''))) . '-' . now()->format('Ymd') . '.xlsx';

        return Excel::download(new OcrExport($sheets), $name);
    }

    /** Buyers the user may see, the chosen buyer and season (validated against them). */
    private function scope(Request $request): array
    {
        $user = $request->user();

        $buyers = Buyer::active()
            ->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))
            ->orderBy('buyer_name')
            ->get();

        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));

        $seasons = $buyer
            ? BuyerSeason::where('buyer_id', $buyer->id)->orderByDesc('id')->get()
            : collect();
        $season = $seasons->firstWhere('id', (int) $request->query('season'));

        return [$buyers, $buyer, $seasons, $season];
    }
}
