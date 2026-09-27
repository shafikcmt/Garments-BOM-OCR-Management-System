<?php

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
 * THE "OCR Checked" BOX on the printed PRA.
 *
 * It was never a stored field — just two empty checkboxes and two blank lines
 * printed for somebody to fill in by hand. A departmental PRA now fills them
 * from the Accounts Team check (step 4).
 *
 * THE RULE THAT MATTERS MOST IS THE NEGATIVE ONE: before step 4, and on every
 * legacy PRA, the box prints EXACTLY the blank form it always did. Ticking "No"
 * on an unreached step would assert a failed check that never happened, which is
 * worse on a payment document than saying nothing.
 */
function ocUser(string $role, bool $head = false, string $name = 'X'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function ocPra(User $creator, Buyer $buyer, bool $departmental = true): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
        'created_by' => $creator->id,
    ]);

    $file = ExcelFile::create([
        'file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b.xlsx',
        'uploaded_by' => $creator->id, 'buyer_id' => $buyer->id, 'total_rows' => 1,
    ]);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id]);

    return $departmental ? app(PraWorkflow::class)->start($pra->fresh()) : $pra->fresh();
}

function ocCheck(PaymentRequest $pra): ?array
{
    return PaymentRequestSettings::ocrCheckFor($pra->fresh()->load('approvals.approver'));
}

beforeEach(function () {
    $this->workflow = app(PraWorkflow::class);
    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);

    $this->creator = ocUser('supply_chain', name: 'Creator');
    $this->merchantTeam = ocUser('merchant', name: 'Merch Team');
    $this->merchantTeam->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $this->merchantTeam->save();
    $this->merchantTeam->buyers()->sync([$this->buyer->id]);
    $this->merchantTeam = $this->merchantTeam->fresh();

    $this->merchantHead = ocUser('merchant', head: true, name: 'Merch Head');
    $this->accountsTeam = ocUser('account', name: 'Accounts Checker');
    $this->accountsHead = ocUser('account', head: true, name: 'Acc Head');
    $this->commercialHead = ocUser('commercial', head: true, name: 'Comm Head');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->pra = ocPra($this->creator, $this->buyer);
});

// --- Blank until step 4 ------------------------------------------------------

it('leaves the box blank on a brand-new departmental PRA', function () {
    expect(ocCheck($this->pra))->toBeNull();
});

it('leaves the box blank while the PRA is still before step 4', function () {
    // Steps 2 and 3 done; the Accounts Team has not checked yet.
    $this->workflow->approve($this->pra, $this->merchantTeam);
    expect(ocCheck($this->pra))->toBeNull();

    $this->workflow->approve($this->pra->fresh(), $this->merchantHead);
    expect(ocCheck($this->pra))->toBeNull();
});

it('does not fill the box from any step other than 4', function () {
    // The merchant check is step 2 and is a different question entirely.
    $this->workflow->approve($this->pra, $this->merchantTeam);

    expect(ocCheck($this->pra))->toBeNull()
        ->and(PaymentRequestSettings::FLOW2_OCR_CHECK_SEQUENCE)->toBe(4);
});

// --- Filled from step 4 ------------------------------------------------------

it('fills the box from whoever performed the Accounts Team check', function () {
    foreach ([$this->merchantTeam, $this->merchantHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    $this->workflow->approve($this->pra->fresh(), $this->accountsTeam);

    $check = ocCheck($this->pra);

    expect($check['name'])->toBe('Accounts Checker')
        ->and($check['date'])->not->toBe('');
});

it('names the person who actually acted, not the department', function () {
    // Two Accounts Team members; the second one checks.
    $second = ocUser('account', name: 'Second Checker');

    foreach ([$this->merchantTeam, $this->merchantHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    $this->workflow->approve($this->pra->fresh(), $second);

    expect(ocCheck($this->pra)['name'])->toBe('Second Checker');
});

it('keeps the box filled once the chain completes', function () {
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam, $this->accountsHead, $this->commercialHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }

    expect($this->pra->fresh()->status)->toBe(PaymentRequest::STATUS_COMPLETED)
        ->and(ocCheck($this->pra)['name'])->toBe('Accounts Checker');
});

it('clears the box for the new cycle after a rejection and resubmit', function () {
    // A resubmitted PRA is being checked again from step 2. The previous
    // round's tick belongs to a closed cycle and must not carry over as
    // though this round had already been checked.
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    expect(ocCheck($this->pra))->not->toBeNull();

    $this->workflow->reject($this->pra->fresh(), $this->accountsHead, 'PI mismatch.');
    $this->workflow->resubmit($this->pra->fresh());

    expect(ocCheck($this->pra))->toBeNull();
});

// --- Rendered output ---------------------------------------------------------

it('renders the tick and the name on the screen once step 4 is done', function () {
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }

    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $this->pra->fresh()))
        ->assertOk()->getContent();

    expect($html)->toContain('OCR Checked')
        ->toContain('Accounts Checker');
});

it('renders the blank form on screen before step 4', function () {
    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $this->pra))
        ->assertOk()->getContent();

    // The box is there, unticked, with nobody named.
    expect($html)->toContain('OCR Checked')
        ->not->toContain('Accounts Checker');
});

// --- LEGACY IS UNTOUCHED -----------------------------------------------------

it('never fills the box on a legacy PRA', function () {
    $legacy = ocPra($this->creator, $this->buyer, departmental: false);

    expect(PaymentRequestSettings::ocrCheckFor($legacy))->toBeNull();
});

it('never fills it even when a legacy PRA has been checked and approved', function () {
    // THE POINT: flow 1 has its own checker, recorded in checked_by, and the
    // printed box stays a hand-signed blank exactly as it always was.
    $checker = ocUser('management', name: 'Legacy Checker');
    $legacy = ocPra($this->creator, $this->buyer, departmental: false);
    $legacy->update([
        'status' => PaymentRequest::STATUS_APPROVED,
        'checked_by' => $checker->id,
        'checked_at' => now(),
    ]);

    PraApproval::create([
        'payment_request_id' => $legacy->id, 'approver_id' => $checker->id,
        'cycle' => 1, 'stage' => PraApproval::STAGE_CHECK,
        'status' => PraApproval::STATUS_APPROVED, 'acted_at' => now(),
    ]);

    expect(PaymentRequestSettings::ocrCheckFor($legacy->fresh()->load('approvals.approver')))->toBeNull();
});

it('renders a legacy PRA screen with the blank box and no checker name', function () {
    $checker = ocUser('management', name: 'Legacy Checker');
    $legacy = ocPra($this->creator, $this->buyer, departmental: false);
    $legacy->update(['status' => PaymentRequest::STATUS_PENDING_APPROVAL]);
    PraApproval::create([
        'payment_request_id' => $legacy->id, 'approver_id' => $checker->id,
        'cycle' => 1, 'stage' => PraApproval::STAGE_CHECK,
        'status' => PraApproval::STATUS_APPROVED, 'acted_at' => now(),
    ]);

    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $legacy->fresh()))
        ->assertOk()->getContent();

    // NARROWED TO THE BOX ITSELF, on purpose. The checker's name DOES appear
    // elsewhere on this page — in the legacy Approval Status card, which has
    // listed the approvers all along and is none of this change's business.
    // Asserting against the whole document would fail on correct behaviour.
    // Anchored on the ATTRIBUTE, not the bare class name — the bare name also
    // appears earlier in the page's stylesheet, and matching that grabs the
    // whole document instead of the box.
    $box = str($html)->after('class="pra-check-box"')->before('</div>')->toString();

    expect($html)->toContain('OCR Checked')
        ->and($box)->not->toContain('Legacy Checker');
});
