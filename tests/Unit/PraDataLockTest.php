<?php

use App\Models\AppSetting;
use App\Support\PraDataLock;

it('defaults both protection rules to on', function () {
    expect(PraDataLock::submitLockEnabled())->toBeTrue();
    expect(PraDataLock::approvalLockEnabled())->toBeTrue();
})->uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('turns a rule off from the admin setting', function () {
    AppSetting::put(PraDataLock::KEY_AFTER_SUBMIT, '0');
    AppSetting::put(PraDataLock::KEY_AFTER_APPROVAL, '0');

    expect(PraDataLock::submitLockEnabled())->toBeFalse();
    expect(PraDataLock::approvalLockEnabled())->toBeFalse();
    expect(PraDataLock::forRows([1, 2]))->toBe([]);
})->uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);
