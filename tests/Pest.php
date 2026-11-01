<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Every requisition approval stage filled in, as Admin would on Requisition
 * Signatures and Departments: a Store head, and the HR Audit, Finance &
 * Commercial and Operation Head users on the final sheet's boxes. Without
 * these the stage engine refuses to submit a sheet.
 *
 * @return array{store_head: \App\Models\User, hr: \App\Models\User, finance: \App\Models\User, operation: \App\Models\User}
 */
function requisitionApprovers(): array
{
    $make = fn (string $name) => \App\Models\User::factory()->create(['name' => $name]);

    $approvers = [
        'store_head' => $make('Store Head Approver'),
        'hr' => $make('HR Audit Approver'),
        'finance' => $make('Finance Approver'),
        'operation' => $make('Operation Approver'),
    ];

    \App\Models\Department::updateOrCreate(
        ['key' => 'store'],
        ['label' => 'Store', 'head_user_id' => $approvers['store_head']->id, 'is_active' => true]
    );

    // The shipped Final chain has no HR Audit box (owner, 2026-11-02), but Admin
    // may still add one. These tests exercise that longer chain, so put the
    // box back as Admin would: Prepared, Store Head, HR Audit, Finance, Operation.
    if (! \App\Models\RequisitionSignatureBlock::query()->where('level', 'final')->where('title', 'HR Head')->exists()) {
        \App\Models\RequisitionSignatureBlock::query()->forLevel('final')->where('sequence', '>=', 3)->orderByDesc('sequence')->get()
            ->each(fn ($b) => $b->update(['sequence' => $b->sequence + 1]));
        \App\Models\RequisitionSignatureBlock::create([
            'level' => 'final', 'sequence' => 3, 'label' => 'Audit by', 'title' => 'HR Head',
            'assignee_type' => 'user', 'user_id' => $approvers['hr']->id, 'stamp_source' => null,
        ]);
    }

    // Final sheet boxes 3, 4 and 5 are the specific-user stages.
    $final = \App\Models\RequisitionSignatureBlock::query()->forLevel('final')->get()->values();
    foreach (['hr', 'finance', 'operation'] as $i => $key) {
        $final->get($i + 2)?->update(['assignee_type' => 'user', 'user_id' => $approvers[$key]->id]);
    }

    // Individual sheet: boxes 4 and 5 are Finance and Operation.
    $individual = \App\Models\RequisitionSignatureBlock::query()->forLevel('individual')->get()->values();
    foreach (['finance', 'operation'] as $i => $key) {
        $individual->get($i + 3)?->update(['assignee_type' => 'user', 'user_id' => $approvers[$key]->id]);
    }

    // As saving Requisition Signatures does: whoever holds a stage can open
    // the requisition screens.
    foreach ($approvers as $approver) {
        \App\Services\RequisitionWorkflow::grantView($approver->id);
    }

    return $approvers;
}
