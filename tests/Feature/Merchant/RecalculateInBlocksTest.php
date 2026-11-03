<?php

use App\Http\Controllers\Shared\ExcelFileController;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;

/**
 * recalculateFile reads a large file a block of rows at a time. The blocks must
 * cover every row once, in sheet order, whatever the row ids look like.
 */
it('walks every row once in sheet order across block boundaries, with its cells', function () {
    $file = ExcelFile::create([
        'file_name' => 'big.xlsx', 'original_file_name' => 'big.xlsx', 'file_path' => 'big.xlsx',
        'uploaded_by' => User::factory()->create()->id,
    ]);
    $header = ExcelHeader::create(['header_name' => 'Style', 'header_key' => 'style_name', 'owner_role_id' => 1, 'position' => 1]);

    // Ids deliberately not in row-number order (a row number is unique per file).
    $numbers = range(1, 450);
    shuffle($numbers);
    $expected = [];
    foreach ($numbers as $n) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $n]);
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => 'S' . $n]);
        $expected[] = $row->id;
    }

    $method = new ReflectionMethod(ExcelFileController::class, 'rowsWithCells');
    $method->setAccessible(true);

    $seen = [];
    $previous = 0;
    foreach ($method->invoke(app(ExcelFileController::class), $file, 200) as $row) {
        expect($row->row_number)->toBeGreaterThanOrEqual($previous)
            ->and($row->relationLoaded('cells'))->toBeTrue()
            ->and($row->cells->first()->value)->toBe('S' . $row->row_number);
        $previous = $row->row_number;
        $seen[] = $row->id;
    }

    expect($seen)->toHaveCount(450)->and(array_unique($seen))->toHaveCount(450);
    expect(collect($seen)->sort()->values()->all())->toBe(collect($expected)->sort()->values()->all());
});
