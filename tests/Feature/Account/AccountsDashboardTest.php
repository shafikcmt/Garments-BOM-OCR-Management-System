<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use App\Services\PaymentPosition;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/** The Accounts dashboard: money to pay, what is late, who is owed, where requests stand. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-10 09:00:00');
    $this->accountant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('account', 'web'));
    $this->file = ExcelFile::create(['file_name' => 'a.xlsx', 'original_file_name' => 'a.xlsx', 'file_path' => 'a', 'status' => 'processing', 'uploaded_by' => $this->accountant->id]);
    $this->n = 0;
});
afterEach(fn () => Carbon::setTestNow());

function acdPra($test, string $supplier, float $amount, ?string $required, string $status = 'approved', ?string $reference = null): PaymentRequest
{
    $n = ++$test->n;
    $pra = PaymentRequest::create(['request_no' => 'PRA-' . $n, 'supplier_name' => $supplier, 'status' => $status, 'total_pi_amount' => $amount,
        'created_by' => $test->accountant->id, 'data' => $reference ? ['payment_reference' => $reference] : null]);
    $row = ExcelRow::create(['excel_file_id' => $test->file->id, 'row_number' => $n]);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $test->file->id, 'excel_row_id' => $row->id, 'po_no' => 'PO' . $n,
        'pi_amount' => $amount, 'payment_required_date' => $required]);

    return $pra;
}

function acdSeed($test): void
{
    acdPra($test, 'Acme Trims', 1000, '2026-11-01');                      // overdue by 9 days
    acdPra($test, 'Acme Trims', 500, '2026-11-12');                       // this week
    acdPra($test, 'Beta Mills', 300, '2026-11-25');                       // in 2 weeks
    acdPra($test, 'Beta Mills', 200, null);                               // no required date
    acdPra($test, 'Gamma Labels', 700, '2026-10-20', 'completed', 'PV-1'); // already paid
    acdPra($test, 'Delta', 900, '2026-11-02', 'pending_approval');        // not payable yet
}

it('works out what is due, overdue, due this week and paid', function () {
    acdSeed($this);
    $payable = PaymentRequest::whereIn('status', ['approved', 'pending_completion', 'completed'])->withCount('items')->withSum('items as lines_amount', 'pi_amount')
        ->withMin('items as required_date', 'payment_required_date')->get();

    $d = app(PaymentPosition::class)->dashboard($payable);
    expect($d)->toMatchArray(['due_count' => 4, 'due_amount' => 2000.0, 'overdue_count' => 1, 'overdue_amount' => 1000.0,
        'week_count' => 1, 'week_amount' => 500.0, 'paid_count' => 1, 'paid_amount' => 700.0, 'no_date_count' => 1, 'due_only_count' => 4, 'partial_count' => 0]);
    expect($d['buckets'])->toBe(['Overdue' => 1000.0, 'This week' => 500.0, 'Next week' => 0.0, 'In 2 weeks' => 300.0, 'In 3 weeks' => 0.0, 'Later' => 0.0, 'No date' => 200.0]);
    expect(collect($d['suppliers'])->pluck('supplier')->all())->toBe(['Acme Trims', 'Beta Mills'])
        ->and($d['suppliers'][0])->toMatchArray(['amount' => 1500.0, 'count' => 2, 'overdue' => 1]);
    expect($d['overdue_list'])->toHaveCount(1)->and($d['overdue_list'][0]['days'])->toBe(9)->and($d['overdue_list'][0]['pra']->request_no)->toBe('PRA-1');
});

it('shows the cards, the charts, the supplier bars and the overdue list on the page', function () {
    acdSeed($this);

    $res = $this->actingAs($this->accountant)->get(route('account.dashboard'))->assertOk()
        ->assertSee('Awaiting payment')->assertSee('Overdue')->assertSee('Due in 7 days')->assertSee('Paid')
        ->assertSee('Amount to pay, by when it is needed')->assertSee('In 2 weeks')->assertSee('Where the requests stand')
        ->assertSee('Owed to suppliers')->assertSee('Acme Trims')->assertSee('1 overdue')
        ->assertSee('Pay these first')->assertSee('PRA-1')->assertSee('9 day(s) late')
        ->assertSee('Your share of the BOM')->assertSee('Payment requests raised');
    $res->assertDontSee('PRA-6');   // pending approval is not payable yet
});

it('agrees with the Payments screen on what is due', function () {
    acdSeed($this);
    $dash = $this->actingAs($this->accountant)->get(route('account.dashboard'))->viewData('pay');
    $pay = $this->get(route('account.payments.index'))->viewData('stats');

    expect($dash['due_amount'])->toBe($pay['due_amount'])->and($dash['due_count'])->toBe($pay['due_count'])->and($dash['paid_count'])->toBe($pay['paid_count']);
});

it('renders with nothing to show and keeps to the buyers a user holds', function () {
    $this->actingAs($this->accountant)->get(route('account.dashboard'))->assertOk()
        ->assertSee('Nothing is waiting to be paid.')->assertSee('Nothing is past its required date.')->assertSee('Nothing to show yet.');
});
