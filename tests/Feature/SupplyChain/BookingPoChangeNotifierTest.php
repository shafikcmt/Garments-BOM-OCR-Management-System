<?php

use App\Models\AppNotification;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\BookingPoChangeNotifier;
use Spatie\Permission\Models\Role;

/**
 * A Merchant's workspace edit must reach Supply Chain as a flagged PO plus one
 * notification that names the PO and the field — and must not repeat for a PO
 * that is already waiting to be regenerated.
 */
function poFromWorkspaceRow(string $style = 'S-100'): array
{
    $merchant = User::factory()->create();

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'uploads/bom.xlsx',
        'uploaded_by' => $merchant->id,
    ]);

    $header = ExcelHeader::create([
        'header_name' => 'Style Name',
        'header_key' => 'style_name',
        'owner_role_id' => 1,
        'position' => 1,
    ]);

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);
    $cell = ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => $style]);

    $po = BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'po_no' => 'HB26FA0007',
        'generated_at' => now(),
    ]);

    return [$merchant, $file, $row, $cell, $po];
}

it('flags the PO and tells supply chain once when the merchant changes a source field', function () {
    Role::findOrCreate('supply_chain', 'web');
    $supply = User::factory()->create()->assignRole('supply_chain');
    $outsider = User::factory()->create();

    [$merchant, $file, $row, $cell, $po] = poFromWorkspaceRow();
    $notifier = app(BookingPoChangeNotifier::class);

    // First look only records the baseline: nothing has changed yet.
    expect($notifier->notify($file, [$row->id], $merchant))->toBe(0);

    $cell->update(['value' => 'S-200']);

    expect($notifier->notify($file, [$row->id], $merchant))->toBe(1);
    expect($po->fresh()->needs_regenerate)->toBeTrue();

    $note = AppNotification::where('type', 'po_source_changed')->sole();
    expect($note->user_id)->toBe($supply->id)
        ->and($note->message)->toContain('HB26FA0007')->toContain('Style');
    expect(AppNotification::where('user_id', $outsider->id)->count())->toBe(0);

    // Same unresolved change, saved again: no second alert.
    expect($notifier->notify($file, [$row->id], $merchant))->toBe(0);
    expect(AppNotification::where('type', 'po_source_changed')->count())->toBe(1);
});

it('does nothing for a row that has no PO', function () {
    [$merchant, $file, $row] = poFromWorkspaceRow();
    BookingPo::query()->delete();

    expect(app(BookingPoChangeNotifier::class)->notify($file, [$row->id], $merchant))->toBe(0);
    expect(AppNotification::count())->toBe(0);
});
