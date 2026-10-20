<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelFileChangeLog;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use App\Services\PraPaymentReferenceWriter;

/**
 * Completing a PRA records its payment reference once, on every worksheet line
 * it covers, instead of Accounts typing Pmt Doc No line by line.
 */
function completedPraWithLines(int $lines = 3, bool $withHeader = true): array
{
    $user = User::factory()->create();
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx',
        'file_path' => 'uploads/bom.xlsx', 'uploaded_by' => $user->id,
    ]);

    $header = $withHeader ? ExcelHeader::create([
        'header_name' => 'Pmt doc no', 'header_key' => 'pmt_doc_no', 'owner_role_id' => 1, 'position' => 1,
    ]) : null;

    $pra = PaymentRequest::create(['request_no' => 'PRA-9', 'status' => 'completed', 'created_by' => $user->id]);
    $rows = [];

    for ($i = 1; $i <= $lines; $i++) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i]);
        PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id, 'excel_row_id' => $row->id, 'po_no' => 'HB1']);
        $rows[] = $row;
    }

    return [$user, $pra, $header, $rows];
}

it('writes the payment reference to every line of the PRA and logs each one', function () {
    [$user, $pra, $header, $rows] = completedPraWithLines(3);
    $writer = app(PraPaymentReferenceWriter::class);

    expect($writer->write($pra, '  PV-2026-0042 ', $user))->toBe(3);

    foreach ($rows as $row) {
        expect(ExcelCell::where('row_id', $row->id)->where('header_id', $header->id)->value('value'))->toBe('PV-2026-0042');
    }
    expect(ExcelFileChangeLog::count())->toBe(3);
    expect($pra->fresh()->data['payment_reference'])->toBe('PV-2026-0042');

    // Same reference again changes nothing.
    expect($writer->write($pra, 'PV-2026-0042', $user))->toBe(0);
    expect(ExcelFileChangeLog::count())->toBe(3);
});

it('replaces an earlier value on the lines and ignores a blank reference', function () {
    [$user, $pra, $header, $rows] = completedPraWithLines(1);
    ExcelCell::create(['row_id' => $rows[0]->id, 'header_id' => $header->id, 'value' => 'OLD']);
    $writer = app(PraPaymentReferenceWriter::class);

    expect($writer->write($pra, '   ', $user))->toBe(0);
    expect(ExcelCell::first()->value)->toBe('OLD');

    expect($writer->write($pra, 'NEW-1', $user))->toBe(1);
    expect(ExcelCell::first()->value)->toBe('NEW-1');
});

it('still keeps the reference on the PRA when the workspace has no Pmt Doc No column', function () {
    [$user, $pra] = completedPraWithLines(2, false);

    expect(app(PraPaymentReferenceWriter::class)->write($pra, 'PV-7', $user))->toBe(0);
    expect($pra->fresh()->data['payment_reference'])->toBe('PV-7');
});
