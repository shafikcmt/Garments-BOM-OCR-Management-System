<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use App\Models\PurchaseRequisition;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for ONE Purchase Requisition — the same document the single
 * requisition PDF prints, on a single sheet.
 *
 * Renders the same blade as that PDF, and is styled by the same
 * FormatsStoreSheet the monthly workbook uses, so a downloaded requisition
 * matches both the printed page and the monthly report it will appear in.
 *
 * ShouldAutoSize is deliberately NOT used, for the reason given in
 * MonthlyRequisitionReportSheet: it measures the merged group headings badly.
 */
class RequisitionDocumentExport implements FromView, WithEvents, WithTitle
{
    use Exportable;
    use FormatsStoreSheet;

    /** @param Collection<int, array<string, mixed>> $rows the requisition's item lines */
    public function __construct(
        private readonly PurchaseRequisition $requisition,
        private readonly Collection $rows,
        private readonly string $title,
    ) {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.requisitions.document-pdf', [
            'requisition' => $this->requisition,
            'rows' => $this->rows,
            'title' => $this->title,
            'forExcel' => true,
        ]);
    }

    /**
     * Excel sheet names cannot exceed 31 characters or contain : \ / ? * [ ].
     * A Requisition No carries slashes ("HAPL/ALL/2026/August/05"), so it is
     * cleaned rather than used as typed.
     */
    public function title(): string
    {
        $clean = str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $this->requisition->requisition_no ?: 'Requisition');

        return Str::limit(trim($clean) ?: 'Requisition', 31, '');
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->formatStoreSheet($event->sheet->getDelegate()),
        ];
    }
}
