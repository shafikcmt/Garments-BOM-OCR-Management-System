<?php

use App\Models\AppSetting;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\User;
use App\Services\PraWorkflow;
use App\Support\BuyerScope;
use App\Support\PaymentRequestSettings;
use Spatie\Permission\Models\Role;

/**
 * THE PRINTED DOCUMENT for a departmental PRA.
 *
 * Four signature blocks, no fixed names, no fixed images, and a completion line
 * for the step that pays rather than signs. The rules being defended:
 *
 *   - a block shows a real signature ONLY after someone actually acted;
 *   - a pending block may NAME who is expected, but must never carry an image
 *     and must say "Pending approval" — a name on an unsigned line that reads
 *     as an endorsement is the failure mode this whole design guards against;
 *   - a legacy PRA's three blocks are untouched by every one of these changes.
 */
function sbUser(string $role, bool $head = false, string $name = 'X'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function sbPra(User $creator, ?Buyer $buyer, bool $departmental = true): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
        'created_by' => $creator->id,
    ]);

    $file = ExcelFile::create([
        'file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b.xlsx',
        'uploaded_by' => $creator->id, 'buyer_id' => $buyer?->id, 'total_rows' => 1,
    ]);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id]);

    return $departmental ? app(PraWorkflow::class)->start($pra->fresh()) : $pra->fresh();
}

function sbBlocks(PaymentRequest $pra): array
{
    return PaymentRequestSettings::signatureBlocksFor($pra->fresh()->load('approvals.approver'), false);
}

beforeEach(function () {
    $this->workflow = app(PraWorkflow::class);
    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);

    $this->creator = sbUser('supply_chain', name: 'Creator Person');
    $this->merchantTeam = sbUser('merchant', name: 'Merch Team Person');
    $this->merchantTeam->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $this->merchantTeam->save();
    $this->merchantTeam->buyers()->sync([$this->buyer->id]);
    $this->merchantTeam = $this->merchantTeam->fresh();

    $this->merchantHead = sbUser('merchant', head: true, name: 'Merch Head Person');
    $this->accountsTeam = sbUser('account', name: 'Acc Team Person');
    $this->accountsHead = sbUser('account', head: true, name: 'Acc Head Person');
    $this->commercialHead = sbUser('commercial', head: true, name: 'Comm Head Person');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->pra = sbPra($this->creator, $this->buyer);
});

// --- Structure ---------------------------------------------------------------

it('prints four blocks for a departmental PRA and three for a legacy one', function () {
    expect(sbBlocks($this->pra))->toHaveCount(4)
        ->and(sbBlocks(sbPra($this->creator, $this->buyer, departmental: false)))->toHaveCount(3);
});

it('names the four blocks in print order', function () {
    expect(collect(sbBlocks($this->pra))->pluck('officer')->all())
        ->toBe(['prepared', 'checked', 'approved_merchant', 'approved_finance']);
});

it('gives the Commercial Head no signature block', function () {
    // Step 6 pays; it does not endorse. Its record is the completion line.
    expect(collect(sbBlocks($this->pra))->pluck('officer')->all())
        ->not->toContain('completed')
        ->not->toContain('commercial');
});

// --- Pending vs signed -------------------------------------------------------

it('names the single expected approver but marks the block pending', function () {
    $blocks = collect(sbBlocks($this->pra));

    $checked = $blocks->firstWhere('officer', 'checked');

    expect($checked['pending'])->toBeTrue()
        // One merchant team member holds this buyer, so the document can say who.
        ->and($checked['name'])->toBe('Merch Team Person')
        // NEVER an image on a block nobody has signed.
        ->and($checked['src'])->toBeNull()
        ->and($checked['date'])->toBe('');
});

it('refuses to guess when several people could act', function () {
    // Two Accounts Heads: any one may act, so naming either would be a lie.
    sbUser('account', head: true, name: 'Second Acc Head');

    $finance = collect(sbBlocks($this->pra))->firstWhere('officer', 'approved_finance');

    expect($finance['pending'])->toBeTrue()
        ->and($finance['name'])->toBe('');
});

it('fills a block with the actual approver once the step is done', function () {
    $this->workflow->approve($this->pra, $this->merchantTeam, 'Checked.');

    $checked = collect(sbBlocks($this->pra))->firstWhere('officer', 'checked');

    expect($checked['pending'] ?? false)->toBeFalse()
        ->and($checked['name'])->toBe('Merch Team Person')
        ->and($checked['dynamic'])->toBeTrue()
        ->and($checked['date'])->not->toBe('');
});

it('shows whoever actually acted, not whoever was expected', function () {
    // Two heads; the SECOND one acts. The block must name that person.
    $second = sbUser('account', head: true, name: 'Second Acc Head');

    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    $this->workflow->approve($this->pra->fresh(), $second);

    $finance = collect(sbBlocks($this->pra))->firstWhere('officer', 'approved_finance');

    expect($finance['name'])->toBe('Second Acc Head')
        ->and($finance['name'])->not->toBe('Acc Head Person');
});

it('takes the creator block from the PRA itself', function () {
    $prepared = collect(sbBlocks($this->pra))->firstWhere('officer', 'prepared');

    expect($prepared['name'])->toBe('Creator Person')
        ->and($prepared['date'])->not->toBe('');
});

// --- The completion line -----------------------------------------------------

it('has no completion line until the Commercial Head completes', function () {
    expect(PaymentRequestSettings::completionLineFor($this->pra))->toBeNull();

    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam, $this->accountsHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }

    expect(PaymentRequestSettings::completionLineFor($this->pra->fresh()->load('approvals.approver')))->toBeNull();
});

it('records who paid, and when, once step 6 is done', function () {
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam, $this->accountsHead, $this->commercialHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }

    $line = PaymentRequestSettings::completionLineFor($this->pra->fresh()->load('approvals.approver'));

    expect($line['name'])->toBe('Comm Head Person')
        ->and($line['date'])->not->toBe('');
});

it('never gives a legacy PRA a completion line', function () {
    expect(PaymentRequestSettings::completionLineFor(sbPra($this->creator, $this->buyer, departmental: false)))
        ->toBeNull();
});

// --- Admin-configurable labels -----------------------------------------------

it('prints the default labels when nothing is configured', function () {
    expect(collect(sbBlocks($this->pra))->pluck('title')->all())->toBe([
        'Prepared By', 'Checked By', 'Approved By (Merchant)', 'Approved By (Finance and Commercial)',
    ]);
});

it('prints an admin-edited label', function () {
    \App\Models\PraStep::where('sequence', 5)->update(['signature_label' => 'Approved By (Finance)']);

    expect(collect(sbBlocks($this->pra))->firstWhere('officer', 'approved_finance')['title'])
        ->toBe('Approved By (Finance)');
});

it('prints no box for a step whose box title is cleared, and one more for a step that gets a title', function () {
    \App\Models\PraStep::where('sequence', 2)->update(['signature_label' => null]);
    \App\Models\PraStep::where('sequence', 4)->update(['signature_label' => 'Checked By (Accounts)']);

    expect(collect(sbBlocks($this->pra->fresh()))->pluck('title')->all())->toBe([
        'Prepared By', 'Approved By (Merchant)', 'Checked By (Accounts)', 'Approved By (Finance and Commercial)',
    ]);
});

it('lets a super admin save the labels and nobody else reach the screen', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get(route('admin.payment-settings.edit'))->assertOk();

    $this->actingAs($admin)->post(route('admin.payment-settings.update'), ['pra_working_days' => 7])->assertRedirect();

    // The creator's box title is edited on PRA Approval Steps, not here.
    $this->actingAs($admin)->post(route('admin.pra-steps.prepared'), ['prepared_enabled' => 1, 'prepared_title' => 'Raised By'])->assertRedirect();

    expect(PaymentRequestSettings::flow2Label('prepared'))->toBe('Raised By');

    // Not an admin -> not this screen.
    $this->actingAs($this->accountsHead)->get(route('admin.payment-settings.edit'))->assertForbidden();
});

// --- LEGACY IS UNTOUCHED -----------------------------------------------------

it('leaves a legacy PRA with its exact three original titles', function () {
    $legacy = sbPra($this->creator, $this->buyer, departmental: false);

    expect(collect(sbBlocks($legacy))->pluck('title')->all())
        ->toBe(['Prepared By', 'Checked By', 'Approved By']);
});

it('does not let a departmental label edit re-title a legacy document', function () {
    // THE REASON THESE KEYS ARE SEPARATE. Sharing OFFICERS would have re-titled
    // all 19 live flow-1 PRAs the moment an admin edited a departmental label.
    AppSetting::put(PaymentRequestSettings::flow2LabelKey('checked'), 'Merchant Team Verification');

    $legacy = sbPra($this->creator, $this->buyer, departmental: false);

    expect(collect(sbBlocks($legacy))->pluck('title')->all())
        ->toBe(['Prepared By', 'Checked By', 'Approved By'])
        ->and(PaymentRequestSettings::OFFICERS['checked'])->toBe('Checked By');
});

it('keeps the legacy dynamic block behaviour exactly as it was', function () {
    $approver = sbUser('management', name: 'Legacy Approver');
    $legacy = sbPra($this->creator, $this->buyer, departmental: false);
    $legacy->update(['status' => PaymentRequest::STATUS_APPROVED]);

    PraApproval::create([
        'payment_request_id' => $legacy->id, 'approver_id' => $approver->id,
        'cycle' => 1, 'stage' => PraApproval::STAGE_APPROVE,
        'status' => PraApproval::STATUS_APPROVED, 'acted_at' => now(),
    ]);

    $blocks = collect(sbBlocks($legacy));

    expect($blocks)->toHaveCount(3)
        ->and($blocks->firstWhere('officer', 'approved')['name'])->toBe('Legacy Approver')
        // No block on a legacy PRA is ever flagged pending — that key belongs to
        // the departmental builder alone.
        ->and($blocks->filter(fn ($b) => ! empty($b['pending'])))->toBeEmpty();
});

// --- Title line: designation, then department ---------------------------------
//
// The line under a signer's name is the admin-set designation ("Senior
// Merchandiser") when there is one, and the department the role implies when
// there is not — with "Head" for a department admin, so a document waiting on
// or signed by a Head says so. Both the signed and the pending state carry it;
// on a pending block it names the post the document is waiting on, and the
// "Pending approval" caption is unchanged.

it('prints the designation under a signer who has one, and the department under one who does not', function () {
    $this->merchantTeam->forceFill(['designation' => 'Senior Merchandiser'])->save();

    foreach ([$this->merchantTeam, $this->merchantHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }

    $blocks = collect(sbBlocks($this->pra));

    expect($blocks->firstWhere('officer', 'checked')['designation'])->toBe('Senior Merchandiser')
        // No designation set: the department, with Head for a department admin.
        ->and($blocks->firstWhere('officer', 'approved_merchant')['designation'])->toBe('Merchandising Head')
        // The creator is a plain team member: the department alone.
        ->and($blocks->firstWhere('officer', 'prepared')['designation'])->toBe('Supply Chain');
});

it('carries the expected person\'s title on a pending block, still captioned pending', function () {
    $this->accountsHead->forceFill(['designation' => 'Finance Controller'])->save();

    $blocks = collect(sbBlocks($this->pra));
    $checked = $blocks->firstWhere('officer', 'checked');
    $finance = $blocks->firstWhere('officer', 'approved_finance');

    expect($checked['pending'])->toBeTrue()
        ->and($checked['name'])->toBe('Merch Team Person')
        ->and($checked['designation'])->toBe('Merchandising')
        ->and($finance['pending'])->toBeTrue()
        ->and($finance['name'])->toBe('Acc Head Person')
        ->and($finance['designation'])->toBe('Finance Controller')
        ->and($finance['src'])->toBeNull();
});

it('prints no title on a pending block when nobody can be named', function () {
    sbUser('account', head: true, name: 'Second Acc Head');

    $finance = collect(sbBlocks($this->pra))->firstWhere('officer', 'approved_finance');

    expect($finance['name'])->toBe('')->and($finance['designation'])->toBe('');
});

it('renders the title line on screen and in the PDF for both signed and pending blocks', function () {
    $this->merchantTeam->forceFill(['designation' => 'Senior Merchandiser'])->save();
    $this->workflow->approve($this->pra->fresh(), $this->merchantTeam);

    $pra = $this->pra->fresh();

    $screen = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $pra))
        ->assertOk()
        ->getContent();
    $pdf = view('supply-chain.payment-requests.approval_pdf', [
        'paymentRequest' => $pra->load('items', 'approvals.approver'),
        'summary' => [], 'approvalRows' => [],
    ])->render();

    foreach ([$screen, $pdf] as $html) {
        expect($html)
            ->toContain('Senior Merchandiser')   // signed block, typed (no image uploaded)
            ->toContain('Merchandising Head');   // pending block, expected Head
    }
});
