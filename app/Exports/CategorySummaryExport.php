<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Excel download of the category-wise requisition summary (same figures as the screen). */
class CategorySummaryExport implements FromView, ShouldAutoSize, WithTitle
{
    use Exportable;

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function view(): View
    {
        return view('store.stock.requisitions.category-summary-pdf', $this->data + ['forExcel' => true]);
    }

    public function title(): string
    {
        return 'Category Summary';
    }
}
