<?php

use App\Models\PaymentRequest;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The management dashboard reports on real PRA counts. These lock the two
 * things that matter on a screen someone makes decisions from: the figures
 * come from the database, and a trend percentage is only shown when there is
 * something real to compare against.
 */
function managementUser(): User
{
    Role::findOrCreate('management', 'web');

    return User::factory()->create()->assignRole('management');
}

function makePra(string $status, ?string $createdAt = null): PaymentRequest
{
    static $seq = 0;
    $seq++;

    $pra = PaymentRequest::create([
        'request_no' => 'PRA-TEST-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
        'status' => $status,
        'created_by' => User::factory()->create()->id,
    ]);

    // created_at is not fillable, so Eloquent stamps "now" regardless of what
    // is passed to create(). Backdating has to happen after the insert.
    if ($createdAt) {
        $pra->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }

    return $pra->refresh();
}

it('renders for a management user', function () {
    $this->actingAs(managementUser())
        ->get(route('management.dashboard'))
        ->assertOk();
});

it('reports counts taken from the database, not placeholders', function () {
    makePra(PaymentRequest::STATUS_APPROVED);
    makePra(PaymentRequest::STATUS_APPROVED);
    makePra(PaymentRequest::STATUS_REJECTED);
    makePra('draft');

    $response = $this->actingAs(managementUser())
        ->get(route('management.dashboard'))
        ->assertOk();

    $stats = $response->viewData('stats');

    expect($stats['approved'])->toBe(2)
        ->and($stats['rejected'])->toBe(1)
        ->and($stats['draft'])->toBe(1)
        ->and($stats['total'])->toBe(4);
});

it('builds a six month trend including months with nothing in them', function () {
    makePra('draft', now()->subMonths(2)->toDateTimeString());
    makePra('draft', now()->toDateTimeString());
    makePra('draft', now()->toDateTimeString());

    $trend = $this->actingAs(managementUser())
        ->get(route('management.dashboard'))
        ->assertOk()
        ->viewData('trend');

    expect($trend)->toHaveCount(6);

    // Empty months are kept so the shape of the line is not flattering.
    expect(collect($trend)->last()['value'])->toBe(2)
        ->and(collect($trend)->pluck('value')->sum())->toBe(3);
});

it('hides the trend badge when last month had nothing to compare against', function () {
    // Everything this month, nothing last month — a percentage change here
    // would be division by zero dressed up as insight.
    makePra('draft');

    $delta = $this->actingAs(managementUser())
        ->get(route('management.dashboard'))
        ->assertOk()
        ->viewData('delta');

    expect($delta)->toBeNull();
});

it('shows a real percentage when both months have data', function () {
    makePra('draft', now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateTimeString());
    makePra('draft', now()->toDateTimeString());
    makePra('draft', now()->toDateTimeString());

    $delta = $this->actingAs(managementUser())
        ->get(route('management.dashboard'))
        ->assertOk()
        ->viewData('delta');

    // 1 last month -> 2 this month
    expect($delta)->toBe(100.0);
});
