<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for the Receiving report.
 *
 * Renders the same blade the PDF uses, so a downloaded sheet can never disagree
 * with the printed page or the screen.
 *
 * FromView carries the data but none of the presentation, so the sheet is
 * styled afterwards by FormatsStoreSheet — the same styling the monthly
 * requisition workbook and the Consumable Stock Report get, so every Store
 * download looks like it came from one system.
 *
 * ShouldAutoSize is deliberately NOT used, for the reason given in
 * MonthlyRequisitionReportSheet: it measures merged group headings badly.
 */
class ReceivingReportExport implements FromView, WithEvents, WithTitle
{
    use Exportable;
    use FormatsStoreSheet;

    /** @param array<string, mixed> $data payload from ReceivingReportController */
    public function __construct(private readonly array $data)
    {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.receiving-report-pdf', array_merge($this->data, [
            'forExcel' => true,
        ]));
    }

    public function title(): string
    {
        return 'Receiving';
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->formatStoreSheet($event->sheet->getDelegate()),
        ];
    }
}
