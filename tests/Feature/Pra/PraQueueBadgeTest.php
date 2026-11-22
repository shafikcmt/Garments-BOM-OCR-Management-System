<?php

use App\Support\PraQueueCount;
use App\Models\User;

it('counts zero for nobody and for a user with nothing waiting, and never throws', function () {
    expect(PraQueueCount::forUser(null))->toBe(0);

    $user = User::factory()->create();

    expect(PraQueueCount::forUser($user))->toBe(0);

    PraQueueCount::forget($user->id);
    PraQueueCount::forget(null);
});
