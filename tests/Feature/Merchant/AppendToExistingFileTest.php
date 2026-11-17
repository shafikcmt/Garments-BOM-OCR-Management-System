<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelFileChangeLog;
use App\Models\ExcelFileImport;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;

/**
 * "Add to existing file": the rows of a new Excel go to the END of a file of the
 * same buyer and season, instead of making a new file. Mistakes can be undone.
 */
beforeEach(function () {
    Storage::fake('local');

    $role = Role::findOrCreate('merchant', 'web');
    $this->merchant = User::factory()->create()->assignRole('merchant');
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);

    $pos = 1;
    foreach ([['Style', 'style_name'], ['GMTS Color Name', 'gmts_color_name'], ['Material Description', 'material_description'], ['BOM Quantity', 'bom_quantity']] as [$name, $key]) {
        $this->h[$key] = ExcelHeader::create(['header_name' => $name, 'header_key' => $key, 'owner_role_id' => $role->id, 'position' => $pos++]);
    }
    $this->map = [1 => $this->h['style_name']->id, 2 => $this->h['gmts_color_name']->id, 3 => $this->h['material_description']->id, 4 => $this->h['bom_quantity']->id];
});

function apBook(array $rows, string $name = 'more-orders.xlsx'): UploadedFile
{
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet()->setTitle('Data');
    foreach (['Style', 'GMTS Color Name', 'Material Description', 'BOM Quantity'] as $i => $t) {
        $sheet->setCellValue([$i + 1, 1], $t);
    }
    foreach ($rows as $r => $values) {
        foreach ($values as $i => $v) {
            $sheet->setCellValue([$i + 1, 2 + $r], $v);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'ap').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

/** Import the first file as a NEW file, so there is something to add to. */
function apFirstFile($t, array $rows = null): ExcelFile
{
    $rows ??= [['720193', 'RED', 'Zipper', '100'], ['720193', 'RED', 'Button', '50']];
    $t->actingAs($t->merchant)->post(route('merchant.import.upload'), ['buyer_id' => $t->buyer->id, 'season_code' => 'sp27', 'file' => apBook($rows, 'first.xlsx')]);
    $t->actingAs($t->merchant)->post(route('merchant.import.preview'), ['sheet' => 'Data', 'header_row' => 1, 'map' => $t->map]);
    $t->actingAs($t->merchant)->post(route('merchant.import.confirm'))->assertSessionHas('success');

    return ExcelFile::latest('id')->firstOrFail();
}

function apAppend($t, ExcelFile $file, array $rows, array $confirm = [], string $season = 'sp27')
{
    $t->actingAs($t->merchant)->post(route('merchant.import.upload'), [
        'buyer_id' => $t->buyer->id, 'season_code' => $season, 'target_file_id' => $file->id, 'file' => apBook($rows),
    ]);
    $preview = $t->actingAs($t->merchant)->post(route('merchant.import.preview'), ['sheet' => 'Data', 'header_row' => 1, 'map' => $t->map]);

    return [$preview, fn (array $extra = []) => $t->actingAs($t->merchant)->post(route('merchant.import.confirm'), $extra + $confirm)];
}

it('offers the existing files of the same buyer and season on the first screen', function () {
    $file = apFirstFile($this);

    $page = $this->actingAs($this->merchant)->get(route('merchant.import.index', ['file' => $file->id]))->assertOk();

    expect($page->viewData('existingFiles'))->toHaveCount(1)->and($page->viewData('preselect')['id'])->toBe($file->id);
    $page->assertSee('Where should the rows go?');
});

it('adds new rows after the last row of the file, without making a new file', function () {
    $file = apFirstFile($this);

    [$preview, $confirm] = apAppend($this, $file, [['748268', 'BLUE', 'Thread', '70'], ['748268', 'BLUE', 'Label', '20'], ['748268', 'BLUE', 'Tag', '20']]);
    $preview->assertOk()->assertSee('rows will be added after row')->assertSee('Add 3 Rows to File');

    $confirm()->assertRedirect(route('uploaded-files.show', $file->id))->assertSessionHas('success');

    expect(ExcelFile::count())->toBe(1);
    $rows = ExcelRow::where('excel_file_id', $file->id)->orderBy('row_number')->get();
    expect($rows->pluck('row_number')->all())->toBe([1, 2, 3, 4, 5])->and($file->fresh()->total_rows)->toBe(5);

    $value = fn ($row, $key) => ExcelCell::where('row_id', $row->id)->where('header_id', $this->h[$key]->id)->value('value');
    expect($value($rows[2], 'material_description'))->toBe('Thread')->and($value($rows[0], 'material_description'))->toBe('Zipper');

    $import = ExcelFileImport::firstOrFail();
    expect($import->row_count)->toBe(3)->and($import->first_row_number)->toBe(3)->and($import->last_row_number)->toBe(5)
        ->and($rows[3]->excel_file_import_id)->toBe($import->id)->and($rows[0]->excel_file_import_id)->toBeNull();
});

it('skips rows the file already holds, or keeps them when asked', function () {
    $file = apFirstFile($this);
    $incoming = [['720193', 'RED', 'Zipper', '100'], ['720193', 'RED', 'Pocketing', '40']];   // first one is already there ("100" = 100.0)

    [$preview, $confirm] = apAppend($this, $file, $incoming);
    $preview->assertSee('already in that file');
    expect($preview->viewData('duplicateCount'))->toBe(1);
    $confirm(['skip_duplicates' => 1])->assertSessionHas('success');
    expect(ExcelRow::where('excel_file_id', $file->id)->count())->toBe(3)->and(ExcelFileImport::first()->skipped_duplicates)->toBe(1);

    // Everything is a duplicate -> nothing is added.
    [, $confirm2] = apAppend($this, $file, [['720193', 'RED', 'Zipper', '100.0']]);
    $confirm2(['skip_duplicates' => 1])->assertSessionHas('warning');
    expect(ExcelRow::where('excel_file_id', $file->id)->count())->toBe(3);

    // Unticked: kept.
    [, $confirm3] = apAppend($this, $file, [['720193', 'RED', 'Zipper', '100']]);
    $r3 = $confirm3(['skip_duplicates' => 0]);  $r3->assertSessionHas('success');
    expect(ExcelRow::where('excel_file_id', $file->id)->count())->toBe(4);
});

it('refuses a file of another season or buyer, a locked file, and a buyer the merchant lacks', function () {
    $file = apFirstFile($this);

    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), [
        'buyer_id' => $this->buyer->id, 'season_code' => 'FA26', 'target_file_id' => $file->id, 'file' => apBook([['1', 'R', 'Z', '1']]),
    ])->assertSessionHasErrors('target_file_id');

    $file->update(['is_locked' => true, 'lock_scope' => 'all']);
    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), [
        'buyer_id' => $this->buyer->id, 'season_code' => 'SP27', 'target_file_id' => $file->id, 'file' => apBook([['1', 'R', 'Z', '1']]),
    ])->assertSessionHasErrors('target_file_id');
    expect(ExcelRow::where('excel_file_id', $file->id)->count())->toBe(2);
});

it('undoes the latest import, but not when its rows were edited or a later import exists', function () {
    $file = apFirstFile($this);

    [, $c1] = apAppend($this, $file, [['A1', 'RED', 'Thread', '10']]);
    $c1()->assertSessionHas('success');
    $first = ExcelFileImport::latest('id')->first();

    [, $c2] = apAppend($this, $file, [['B1', 'RED', 'Label', '10']]);
    $c2()->assertSessionHas('success');
    $second = ExcelFileImport::latest('id')->first();

    // Only the latest one can be undone.
    $this->actingAs($this->merchant)->post(route('merchant.import.undo', $first))->assertSessionHas('warning');
    expect(ExcelRow::where('excel_file_id', $file->id)->count())->toBe(4);

    // Somebody worked on the latest import's rows: refused.
    $row = $second->rows()->first();
    ExcelFileChangeLog::create(['excel_file_id' => $file->id, 'excel_row_id' => $row->id, 'excel_header_id' => $this->h['bom_quantity']->id, 'row_number' => $row->row_number, 'header_name' => 'BOM Quantity', 'old_value' => '10', 'new_value' => '12', 'changed_by' => $this->merchant->id, 'batch_id' => 'x']);
    $this->actingAs($this->merchant)->post(route('merchant.import.undo', $second))->assertSessionHas('warning');
    expect(ExcelRow::where('excel_file_id', $file->id)->count())->toBe(4);

    ExcelFileChangeLog::query()->delete();
    $this->actingAs($this->merchant)->post(route('merchant.import.undo', $second))->assertSessionHas('success');
    expect(ExcelRow::where('excel_file_id', $file->id)->pluck('row_number')->all())->toBe([1, 2, 3])
        ->and($second->fresh()->undone_at)->not->toBeNull()->and($file->fresh()->total_rows)->toBe(3);

    // Now the earlier one is the latest and can go too.
    $this->actingAs($this->merchant)->post(route('merchant.import.undo', $first))->assertSessionHas('success');
    expect(ExcelRow::where('excel_file_id', $file->id)->count())->toBe(2);
});

it('shows Add rows from Excel and the undo entry in the file menu', function () {
    $file = apFirstFile($this);
    [, $c] = apAppend($this, $file, [['A1', 'RED', 'Thread', '10']]);
    $c();

    $this->actingAs($this->merchant)->get(route('merchant.workspace', ['tab' => 'files']))
        ->assertOk()->assertSee('Add rows from Excel')->assertSee('Undo last import (1 rows)');
});
