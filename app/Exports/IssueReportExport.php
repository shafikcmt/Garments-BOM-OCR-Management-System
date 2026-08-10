<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for the Issues (Consumption) report.
 *
 * Renders the same blade the PDF uses, so a downloaded sheet can never disagree
 * with the printed page or the screen, and is styled by the same
 * FormatsStoreSheet every other Store download uses.
 *
 * ShouldAutoSize is deliberately NOT used, for the reason given in
 * MonthlyRequisitionReportSheet: it measures merged group headings badly.
 */
class IssueReportExport implements FromView, WithEvents, WithTitle
{
    use Exportable;
    use FormatsStoreSheet;

    /** @param array<string, mixed> $data payload from IssueReportController */
    public function __construct(private readonly array $data)
    {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.issue-report-pdf', array_merge($this->data, [
            'forExcel' => true,
        ]));
    }

    public function title(): string
    {
        return 'Issues';
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->formatStoreSheet($event->sheet->getDelegate()),
        ];
    }
}
