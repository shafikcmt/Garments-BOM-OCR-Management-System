<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for the Consumable Stock Report. Renders the same blade the PDF
 * uses, so the download can never disagree with the preview on screen.
 *
 * FromView carries the data but none of the presentation, so the sheet is
 * styled afterwards by FormatsStoreSheet — the same styling the monthly
 * Purchase Requisition workbook gets, so the two downloads match.
 *
 * ShouldAutoSize is deliberately NOT used: it measures merged heading cells
 * badly, which is what left these columns misaligned. FormatsStoreSheet sets
 * widths from the content instead.
 */
class GeneralStockReportExport implements FromView, WithEvents, WithTitle
{
    use FormatsStoreSheet;

    /** @param array<string, mixed> $data payload from GeneralStockLedgerController */
    public function __construct(private readonly array $data)
    {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.ledger-pdf', $this->data + ['forExcel' => true]);
    }

    /** Sheet name mirrors the reference file's "Stock Jul-26" convention. */
    public function title(): string
    {
        return 'Stock '.\Illuminate\Support\Carbon::createFromFormat('Y-m', $this->data['month'])->format('M-y');
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->formatStoreSheet($event->sheet->getDelegate()),
        ];
    }
}
