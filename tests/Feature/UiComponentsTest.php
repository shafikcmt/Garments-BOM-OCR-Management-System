<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

/**
 * Shared UI components.
 *
 * The form components read the validation bag themselves, which is the point:
 * every form that used them stops being able to forget an @error block and
 * silently reject the user without saying why.
 */
function render(string $template, array $data = []): string
{
    return (string) Blade::render($template, $data);
}

/**
 * Render with a populated error bag, as a redirect-back would produce.
 *
 * Shared rather than passed as data: Blade components have their own scope, so
 * $errors only reaches inside one because ShareErrorsFromSession shares it
 * globally on a real request. Passing it as data would leave the component
 * looking at an empty bag and the test passing for the wrong reason.
 */
function renderWithErrors(string $template, array $errors): string
{
    $bag = new ViewErrorBag();
    $bag->put('default', new Illuminate\Support\MessageBag($errors));

    View::share('errors', $bag);

    try {
        return (string) Blade::render($template);
    } finally {
        View::share('errors', new ViewErrorBag());
    }
}

it('links label, help text and input together', function () {
    $html = render('<x-form.field name="qty" label="Physical Rcv Qty" help="Drives the ledger" required />');

    expect($html)->toContain('for="f_qty"')
        ->and($html)->toContain('id="f_qty"')
        ->and($html)->toContain('aria-describedby="f_qty_help"')
        ->and($html)->toContain('Drives the ledger')
        ->and($html)->toContain('required');
});

it('shows the validation error for its own field without the form asking', function () {
    $html = renderWithErrors(
        '<x-form.field name="qty" label="Qty" />',
        ['qty' => 'Physical Rcv Qty is required.']
    );

    expect($html)->toContain('Physical Rcv Qty is required.')
        ->and($html)->toContain('is-invalid')
        ->and($html)->toContain('aria-invalid="true"');
});

it('leaves a field alone when the error belongs to another one', function () {
    $html = renderWithErrors(
        '<x-form.field name="qty" label="Qty" />',
        ['unit_price' => 'Unit price is invalid.']
    );

    expect($html)->not->toContain('is-invalid')
        ->and($html)->not->toContain('Unit price is invalid.');
});

it('marks the selected option in a select', function () {
    $html = render(
        '<x-form.select name="source" :options="$options" selected="internal_po" blank="Choose…" />',
        ['options' => ['booking' => 'Booking-wise', 'internal_po' => 'Internal PO-wise']]
    );

    expect($html)->toContain('<option value="">Choose…</option>')
        ->and($html)->toContain('value="internal_po" selected');
});

it('renders checkbox, radio and switch from one component', function () {
    expect(render('<x-form.check name="a" label="Check" />'))->toContain('type="checkbox"');
    expect(render('<x-form.check name="b" label="Radio" as="radio" />'))->toContain('type="radio"');
    expect(render('<x-form.check name="c" label="Switch" as="switch" />'))->toContain('form-switch');
});

it('gives a badge a tone without hardcoding a colour', function () {
    $html = render('<x-badge tone="success" icon="check2">Approved</x-badge>');

    expect($html)->toContain('bg-success-subtle')
        ->and($html)->toContain('bi-check2')
        ->and($html)->toContain('Approved');
});

it('announces flash messages to assistive tech', function () {
    session()->flash('success', 'Receiving recorded.');

    $html = render('<x-flash />');

    expect($html)->toContain('role="alert"')
        ->and($html)->toContain('Receiving recorded.')
        ->and($html)->toContain('alert-success');
});

it('lists every validation error in one summary', function () {
    $html = renderWithErrors('<x-flash />', [
        'qty' => 'Qty is required.',
        'date' => 'Date is required.',
    ]);

    expect($html)->toContain('Please correct the following:')
        ->and($html)->toContain('Qty is required.')
        ->and($html)->toContain('Date is required.');
});

it('keeps the old store flash path working for the views that include it', function () {
    session()->flash('warning', 'This file is locked.');

    $html = (string) view('store._flash')->render();

    expect($html)->toContain('This file is locked.')
        ->and($html)->toContain('alert-warning');
});

it('carries the busy label a double-submit guard needs', function () {
    $html = render('<x-submit-button busy="Saving…" icon="check-lg">Save</x-submit-button>');

    expect($html)->toContain('data-submit-button')
        ->and($html)->toContain('data-busy-label="Saving…"')
        ->and($html)->toContain('type="submit"');
});
