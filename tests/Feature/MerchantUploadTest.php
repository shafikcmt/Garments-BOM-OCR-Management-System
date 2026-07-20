<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

/**
 * The upload form is progressive enhancement over a plain <form>: the script
 * adds drag-and-drop and a progress bar, but the markup and the server flow
 * stay the same. These lock the parts that must keep working with or without
 * JavaScript — the form still posts a single file field, and the server's own
 * validation still decides what is acceptable.
 */
function merchantUser(): User
{
    Role::findOrCreate('merchant', 'web');

    return User::factory()->create()->assignRole('merchant');
}

it('renders the upload zone inside a real form', function () {
    $response = $this->actingAs(merchantUser())
        ->get(route('merchant.workspace'))
        ->assertOk();

    // Degrades without JS: a genuine form around a genuine file input.
    $response->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('data-upload-form', false)
        ->assertSee('name="file"', false)
        ->assertSee('gx-dropzone', false);
});

it('advertises only the formats the server actually accepts', function () {
    $response = $this->actingAs(merchantUser())
        ->get(route('merchant.workspace'))
        ->assertOk();

    // The server rejects everything except spreadsheets, so the zone must not
    // invite PDFs or images.
    $response->assertSee('.xlsx,.xls,.csv', false)
        ->assertDontSee('.pdf', false)
        ->assertDontSee('image/', false);
});

it('rejects a file type the zone does not advertise', function () {
    $this->actingAs(merchantUser())
        ->post(route('merchant.excel.store'), [
            'file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');
});

it('rejects an image, which the OCR name might otherwise suggest is allowed', function () {
    $this->actingAs(merchantUser())
        ->post(route('merchant.excel.store'), [
            'file' => UploadedFile::fake()->image('bom.jpg'),
        ])
        ->assertSessionHasErrors('file');
});

it('requires a file', function () {
    $this->actingAs(merchantUser())
        ->post(route('merchant.excel.store'), [])
        ->assertSessionHasErrors('file');
});
