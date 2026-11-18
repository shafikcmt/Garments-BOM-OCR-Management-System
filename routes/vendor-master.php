<?php

use App\Http\Controllers\Shared\VendorMasterController as V;
use Illuminate\Support\Facades\Route;

// Vendor profile shared by Admin, Merchant, Supply Chain, Accounts ...: access is decided
// by permission inside the controller / App\Services\VendorMaster, not by the menu used.
Route::middleware('auth')->prefix('vendor-master')->name('vendors.')->group(function () {
    Route::get('/{supplier}', [V::class, 'show'])->name('show');
    Route::put('/{supplier}', [V::class, 'update'])->name('update');
    Route::post('/{supplier}/workflow/{action}', [V::class, 'workflow'])->name('workflow');
    Route::post('/modifications/{log}', [V::class, 'decideModification'])->name('modification.decide');

    Route::post('/{supplier}/contacts', [V::class, 'storeContact'])->name('contacts.store');
    Route::post('/{supplier}/contacts/{contact}/primary', [V::class, 'primaryContact'])->name('contacts.primary');
    Route::delete('/{supplier}/contacts/{contact}', [V::class, 'destroyContact'])->name('contacts.destroy');

    Route::post('/{supplier}/addresses', [V::class, 'storeAddress'])->name('addresses.store');
    Route::post('/{supplier}/addresses/{address}/primary', [V::class, 'primaryAddress'])->name('addresses.primary');
    Route::delete('/{supplier}/addresses/{address}', [V::class, 'destroyAddress'])->name('addresses.destroy');

    Route::post('/{supplier}/categories', [V::class, 'storeCategory'])->name('categories.store');
    Route::delete('/{supplier}/categories/{category}', [V::class, 'destroyCategory'])->name('categories.destroy');

    Route::post('/{supplier}/items', [V::class, 'storeItem'])->name('items.store');
    Route::delete('/{supplier}/items/{item}', [V::class, 'destroyItem'])->name('items.destroy');

    Route::post('/{supplier}/business-units', [V::class, 'storeBusinessUnit'])->name('bu.store');
    Route::delete('/{supplier}/business-units/{mapping}', [V::class, 'destroyBusinessUnit'])->name('bu.destroy');

    Route::post('/{supplier}/bank-requests', [V::class, 'requestBank'])->name('bank.request');
    Route::post('/bank-requests/{bankRequest}/decide', [V::class, 'decideBank'])->name('bank.decide');
    Route::post('/bank-requests/{bankRequest}/cancel', [V::class, 'cancelBank'])->name('bank.cancel');
});
