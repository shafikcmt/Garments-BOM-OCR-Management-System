<?php

use App\Http\Controllers\SupplyChain\PaymentRequestController;

it('spans an item name once across consecutive rows of the same style and vendor', function () {
    $controller = (new ReflectionClass(PaymentRequestController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($controller, 'annotateItemSpans');
    $method->setAccessible(true);

    $rows = collect([
        ['style' => 'S1', 'vendor_name' => 'A', 'material_type' => 'Main Label'],
        ['style' => 'S1', 'vendor_name' => 'A', 'material_type' => 'Size Label'],
        ['style' => 'S1', 'vendor_name' => 'A', 'material_type' => 'size label'],
        ['style' => 'S1', 'vendor_name' => 'A', 'material_type' => 'Size Label'],
        // Same item name, different style: never spanned into the rows above.
        ['style' => 'S2', 'vendor_name' => 'A', 'material_type' => 'Size Label'],
        // Same style and item, different vendor: never spanned either.
        ['style' => 'S2', 'vendor_name' => 'B', 'material_type' => 'Size Label'],
    ]);

    $spans = $method->invoke($controller, $rows)->pluck('item_rowspan')->all();

    expect($spans)->toBe([1, 3, 0, 0, 1, 1]);
});
