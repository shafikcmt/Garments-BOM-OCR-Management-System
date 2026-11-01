<?php

namespace App\Exports;

use App\Models\PurchaseRequisition;
use App\Support\CategoryGroups;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The Final Requisition's By Category workbook, shaped like the Monthly
 * Report's: an ALL sheet (every category, each closed by its Category Sum),
 * then one sheet per category in the same order, each with its own header,
 * Total and signature boxes.
 */
class RequisitionCategoryExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $signatures
     */
    public function __construct(
        private readonly PurchaseRequisition $requisition,
        private readonly Collection $rows,
        private readonly string $title,
        private readonly array $signatures,
        private readonly ?string $departmentHeadName = null,
    ) {
    }

    /** @return list<RequisitionDocumentExport> */
    public function sheets(): array
    {
        $make = fn (Collection $rows, bool $withCategory, string $name) => new RequisitionDocumentExport(
            $this->requisition, $rows, $this->title, $this->signatures, $this->departmentHeadName, $withCategory, $name,
        );

        $sheets = [$make($this->rows, true, 'ALL')];
        $used = ['ALL'];

        foreach (CategoryGroups::ofRows($this->rows) as $category => $rows) {
            // Excel sheet names: at most 31 characters, no : \ / ? * [ ], and unique.
            $name = Str::limit(trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $category)) ?: 'Category', 31, '');
            for ($n = 2; in_array(mb_strtolower($name), array_map('mb_strtolower', $used), true); $n++) {
                $name = Str::limit($name, 28, '').' '.$n;
            }
            $used[] = $name;
            $sheets[] = $make($rows->values(), false, $name);
        }

        return $sheets;
    }
}
