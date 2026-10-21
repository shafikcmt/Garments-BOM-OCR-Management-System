<?php

use App\Http\Controllers\FollowUpController;
use Illuminate\Support\Facades\Route;

// Follow-up tasks (Time and Action). Open to the roles a task can be given to
// and to Management, who watch them; who may change a task is checked per task.
Route::prefix('follow-ups')
    ->middleware(['auth', 'role:merchant,supply_chain,account,commercial,admin,management'])
    ->name('follow_ups.')
    ->group(function () {
        Route::get('/', [FollowUpController::class, 'index'])->name('index');
        Route::post('/', [FollowUpController::class, 'store'])->name('store');
        Route::post('/{task}/done', [FollowUpController::class, 'done'])->name('done');
        Route::post('/{task}/reopen', [FollowUpController::class, 'reopen'])->name('reopen');
    });
