<?php

use App\Http\Controllers\Shared\ExcelFileController;
use App\Models\BookingPo;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use App\Services\SupplyChainWorkspaceSync;

/**
 * A PO or PRA raised in Supply Chain must refresh the workspace cells that are
 * calculated from it, once per file, without waiting for a workspace save.
 */
function syncFile(): ExcelFile
{
    return ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'uploads/bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
    ]);
}

function poFor(ExcelFile $file, array $attrs = []): BookingPo
{
    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => ExcelRow::where('excel_file_id', $file->id)->count() + 1]);

    return BookingPo::create($attrs + [
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'status' => 'applied',
        'generated_by' => $file->uploaded_by,
    ]);
}

function recalcSpy(int $times): void
{
    test()->mock(ExcelFileController::class, function ($mock) use ($times) {
        $mock->shouldReceive('recalculateFile')->times($times);
    });
}

it('recalculates a file once after a PO is created and its status changes', function () {
    $file = syncFile();
    recalcSpy(1);

    $po = poFor($file, ['po_no' => 'HB26FA0001', 'status' => 'applied']);
    $po->update(['status' => 'completed']);

    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(1);
});

it('ignores preview POs and changes that do not affect the workspace', function () {
    $file = syncFile();
    recalcSpy(1);

    poFor($file, ['po_no' => 'X', 'status' => 'preview']);
    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(0);

    $po = poFor($file, ['po_no' => 'HB1'])->fresh();
    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(1);

    // A note on a PO already in the workspace changes nothing the cells show.
    $po->update(['remarks' => 'just a note']);
    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(0);
});

it('queues the file when a real payment request line is added, but not a preview', function () {
    $file = syncFile();
    $user = User::factory()->create();

    recalcSpy(1);

    $preview = PaymentRequest::create(['request_no' => 'PRV-1', 'status' => 'preview', 'created_by' => $user->id]);
    PaymentRequestItem::create(['payment_request_id' => $preview->id, 'excel_file_id' => $file->id, 'po_no' => 'HB1']);
    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(0);

    $real = PaymentRequest::create(['request_no' => 'PRA-1', 'status' => 'pending_check', 'created_by' => $user->id]);
    PaymentRequestItem::create(['payment_request_id' => $real->id, 'excel_file_id' => $file->id, 'po_no' => 'HB1']);
    PaymentRequestItem::create(['payment_request_id' => $real->id, 'excel_file_id' => $file->id, 'po_no' => 'HB2']);

    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(1);
});

it('does nothing when the switch is off', function () {
    config(['stock.sync_supply_chain_cells' => false]);
    $file = syncFile();
    recalcSpy(0);

    poFor($file, ['po_no' => 'HB1', 'status' => 'applied']);

    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(0);
});

it('never breaks the Supply Chain action if the recalculation fails', function () {
    $file = syncFile();
    test()->mock(ExcelFileController::class, fn ($m) => $m->shouldReceive('recalculateFile')->andThrow(new RuntimeException('boom')));

    poFor($file, ['po_no' => 'HB1', 'status' => 'applied']);

    expect(app(SupplyChainWorkspaceSync::class)->flush())->toBe(0);
});
