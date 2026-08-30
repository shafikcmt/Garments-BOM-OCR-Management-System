<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping for the Commercial department, outside its report.
 *
 * The REPORT is covered in tests/Feature/Commercial/ReportTest.php, which owns
 * the column boundary and the filters and now carries the Commercial-viewer
 * scope assertions too. It needed no production code at all: CommercialReportService
 * extends DepartmentReportService, which already uses ScopesToAssignedBuyers,
 * so adding 'commercial' to BuyerScope::SCOPED_DEPARTMENTS scoped the report by
 * itself.
 *
 * What is left for this file is the rest of the department:
 *
 *   dashboard   file and row counts, both buyer-linked, both scoped.
 *   workspace   the shared BOM file list — DELIBERATELY UNFILTERED, the same
 *               rule Merchandising and Supply Chain follow.
 *
 * Commercial raises no PO or PRA, so there are no write paths to guard here.
 */
function cmScopeUser(array $buyerIds = [], string $mode = BuyerScope::MODE_ALL): User
{
    Role::findOrCreate('commercial', 'web');

    $user = User::factory()->create([
        'buyer_scope_mode' => $mode,
        'buyer_id' => $buyerIds[0] ?? null,
    ])->assignRole('commercial');

    if ($buyerIds !== []) {
        $user->buyers()->sync($buyerIds);
    }

    return $user->fresh();
}

function cmScopeFile(?int $buyerId): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 1,
    ]);

    ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    return $file;
}

/** Counts read through the trait, the same way the dashboard builds them. */
function cmScopeCounter(): object
{
    return new class {
        use App\Support\Concerns\ScopesToAssignedBuyers;

        public function rows(): int
        {
            $q = ExcelRow::query();
            $this->applyBuyerScope($q);

            return $q->count();
        }

        public function files(): int
        {
            $q = ExcelFile::query();
            $this->applyBuyerScopeToFiles($q);

            return $q->count();
        }
    };
}

beforeEach(function () {
    Role::findOrCreate('commercial', 'web');
    Role::findOrCreate('admin', 'web');

    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    // The untagged file is here on purpose: every live BOM file carries a null
    // buyer_id, so a scope that drops untagged rows empties the department.
    cmScopeFile($this->buyerA->id);
    cmScopeFile($this->buyerB->id);
    cmScopeFile(null);
});

it('scopes the commercial department', function () {
    expect(BuyerScope::appliesTo('commercial'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The three buyer states
|--------------------------------------------------------------------------
*/

it('counts only a one-buyer commercial user own buyer plus the untagged archive', function () {
    $user = cmScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);
    $this->actingAs($user);

    expect($user->isBuyerConfined())->toBeTrue()
        ->and(cmScopeCounter()->files())->toBe(2)
        ->and(cmScopeCounter()->rows())->toBe(2);

    $this->get(route('commercial.dashboard'))->assertOk();
});

it('counts every buyer a multi-buyer commercial user holds, not just the first', function () {
    $user = cmScopeUser([$this->buyerA->id, $this->buyerB->id], BuyerScope::MODE_SPECIFIC);
    $this->actingAs($user);

    expect($user->scopedBuyerIds())->toBe([$this->buyerA->id, $this->buyerB->id])
        ->and(cmScopeCounter()->files())->toBe(3);

    $this->get(route('commercial.dashboard'))->assertOk();
});

/* THE DEFAULT. Switching Commercial on confined nobody. */
it('leaves an unassigned commercial user unconfined and counting everything', function () {
    $user = cmScopeUser();
    $this->actingAs($user);

    expect($user->isBuyerConfined())->toBeFalse()
        ->and($user->buyer_scope_mode)->toBe(BuyerScope::MODE_ALL)
        ->and(cmScopeCounter()->files())->toBe(3)
        ->and(cmScopeCounter()->rows())->toBe(3);

    $this->get(route('commercial.dashboard'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| The deliberate non-changes
|--------------------------------------------------------------------------
*/

/* The workspace file list is an EDIT LOCK, not a visibility filter. Switching
 * Commercial on must not quietly start hiding files from it. */
it('leaves the commercial workspace file list unfiltered', function () {
    $this->actingAs(cmScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC))
        ->get(route('commercial.workspace'))
        ->assertOk()
        ->assertViewHas('files', fn ($files) => $files->count() === 3);
});

/* The edit lock, which the department gains along with the read scope: a
 * confined Commercial user may view another buyer's file but not edit it, and
 * the untagged archive stays editable by everyone. */
it('locks a confined commercial user out of another buyer file', function () {
    $user = cmScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $mine = ExcelFile::where('buyer_id', $this->buyerA->id)->first();
    $theirs = ExcelFile::where('buyer_id', $this->buyerB->id)->first();
    $untagged = ExcelFile::whereNull('buyer_id')->first();

    expect($theirs->isBuyerLockedForUser($user))->toBeTrue()
        ->and($mine->isBuyerLockedForUser($user))->toBeFalse()
        ->and($untagged->isBuyerLockedForUser($user))->toBeFalse();
});

it('never restricts a super admin, whatever they hold', function () {
    $admin = User::factory()->create([
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
        'buyer_id' => $this->buyerA->id,
    ])->assignRole('admin');

    expect($admin->isBuyerConfined())->toBeFalse()
        ->and($admin->scopedBuyerIds())->toBe([]);
});
