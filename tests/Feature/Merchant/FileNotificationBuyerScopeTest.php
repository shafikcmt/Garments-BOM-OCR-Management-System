<?php

use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping on the file-activity notifications.
 *
 * THE LEAK THIS EXISTS FOR. Both fan-outs — ExcelUploadController::
 * notifyOtherRoles() on upload and ExcelFileController::
 * notifyOtherRolesAboutFileUpdate() on save — selected every non-merchant user
 * and wrote an AppNotification for each, with no buyer rule at all. The message
 * carries the FILE NAME, and layouts/navbar prints title and message in full in
 * the bell dropdown, so a Commercial user confined to one buyer was handed the
 * file names of every other buyer without clicking anything.
 *
 * It is the same rule already settled for the Workspace file list: locking the
 * edit does not un-leak the label.
 *
 * NOT LIVE WHEN FOUND, and worth knowing why the tests matter anyway: merchants
 * are excluded from these notifications entirely, and no user outside
 * Merchandising is confined today, so nobody was receiving another buyer's file
 * name yet. It would have started the day an admin ticked a buyer on a
 * Commercial, Accounts, Store or Supply Chain account — which is a supported
 * action, not a hypothetical.
 */
function fnUser(string $role, array $buyerIds = []): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create([
        'status' => 1,
        'buyer_scope_mode' => $buyerIds === [] ? BuyerScope::MODE_ALL : BuyerScope::MODE_SPECIFIC,
        'buyer_id' => $buyerIds[0] ?? null,
    ])->assignRole($role);

    if ($buyerIds !== []) {
        $user->buyers()->sync($buyerIds);
    }

    return $user->fresh();
}

function fnFile(?int $buyerId): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'macys-bom.xlsx',
        'original_file_name' => 'macys-bom.xlsx',
        'file_path' => 'macys-bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 1,
    ]);

    $ownerRole = Role::findOrCreate('merchant', 'web');
    $position = 0;

    $headers = collect(['buyer_name', 'style_name', 'season_name'])
        ->map(fn ($key) => ExcelHeader::firstOrCreate(
            ['header_key' => $key],
            [
                'header_name' => ucwords(str_replace('_', ' ', $key)),
                'owner_role_id' => $ownerRole->id,
                'position' => $position++,
                'is_active' => true,
            ]
        ));

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    foreach ($headers as $header) {
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => 'Zara']);
    }

    return $file->fresh();
}

/** Save one cell as the file's own merchant, which is what triggers the fan-out. */
function fnSaveAs(User $actor, ExcelFile $file, string $value = 'EDITED'): void
{
    $header = ExcelHeader::where('header_key', 'style_name')->first();
    $row = $file->rows()->first();

    test()->actingAs($actor)->put(route('uploaded-files.update', $file->id), [
        'cells' => [$row->id => [$header->id => $value]],
    ]);
}

function fnNotifiedIds(): array
{
    return AppNotification::pluck('user_id')->all();
}

beforeEach(function () {
    $this->macys = Buyer::create(['buyer_name' => 'MACYS', 'is_active' => true]);
    $this->hugo = Buyer::create(['buyer_name' => 'Hugo Boss', 'is_active' => true]);
});

it('does not notify a user confined to another buyer that a file changed', function () {
    $file = fnFile($this->macys->id);

    $editor = fnUser('merchant', [$this->macys->id]);
    $onMacys = fnUser('commercial', [$this->macys->id]);
    $onHugo = fnUser('commercial', [$this->hugo->id]);
    $unconfined = fnUser('account');

    fnSaveAs($editor, $file);

    $notified = fnNotifiedIds();

    expect($notified)->toContain($onMacys->id)
        ->and($notified)->toContain($unconfined->id)
        // The leak: the message names macys-bom.xlsx, and the navbar prints it.
        ->and($notified)->not->toContain($onHugo->id);
});

/**
 * The exemption that must survive. Every live BOM file is untagged, so a rule
 * that withheld untagged files would silence these notifications for everybody
 * — the fix would read as "notifications stopped working".
 */
it('still notifies everyone about an untagged file', function () {
    $file = fnFile(null);

    $editor = fnUser('merchant', [$this->macys->id]);
    $onHugo = fnUser('commercial', [$this->hugo->id]);
    $unconfined = fnUser('account');

    fnSaveAs($editor, $file);

    $notified = fnNotifiedIds();

    expect($notified)->toContain($onHugo->id)
        ->and($notified)->toContain($unconfined->id);
});

/** Merchants were never recipients, and the buyer filter must not change that. */
it('leaves the merchant exclusion and the actor exclusion alone', function () {
    $file = fnFile($this->macys->id);

    $editor = fnUser('merchant', [$this->macys->id]);
    $otherMerchant = fnUser('merchant', [$this->macys->id]);
    $onMacys = fnUser('commercial', [$this->macys->id]);

    fnSaveAs($editor, $file);

    $notified = fnNotifiedIds();

    expect($notified)->toContain($onMacys->id)
        ->and($notified)->not->toContain($editor->id)
        ->and($notified)->not->toContain($otherMerchant->id);
});

/**
 * The message body is the thing that leaks, so assert on it directly rather
 * than only on the recipient list — a future change that notified everyone with
 * a redacted body would be a different design, and this test should be the one
 * that has to be rewritten to allow it.
 */
it('never writes another buyers file name into a confined users notification', function () {
    $file = fnFile($this->macys->id);

    $editor = fnUser('merchant', [$this->macys->id]);
    $onHugo = fnUser('commercial', [$this->hugo->id]);

    fnSaveAs($editor, $file);

    $theirs = AppNotification::where('user_id', $onHugo->id)->get();

    expect($theirs)->toBeEmpty();

    foreach ($theirs as $notification) {
        expect($notification->message)->not->toContain('macys-bom.xlsx');
    }
});
