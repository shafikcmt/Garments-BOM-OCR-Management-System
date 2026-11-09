<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MotherStyle;
use App\Models\StyleDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Document bank of a style. Merchandising and Admin upload and delete;
 * Management reads. Everyone is checked against the style's buyer.
 */
class StyleDocumentController extends Controller
{
    public function store(Request $request, MotherStyle $mother)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);

        $data = $request->validate([
            'document' => ['required', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,gif,webp,xls,xlsx,csv,doc,docx,ppt,pptx,txt,zip'],
            'title' => ['nullable', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:60'],
        ], [
            'document.required' => 'Choose a file to upload.',
            'document.max' => 'The file is too large. The limit is 15 MB.',
            'document.mimes' => 'This file type is not allowed. Use PDF, image, Excel, Word, PowerPoint, text or zip.',
        ]);

        $file = $request->file('document');
        $path = $file->store('style-documents/' . $mother->id, 'local');

        $mother->documents()->create([
            'category' => trim((string) ($data['category'] ?? '')) ?: null,
            'title' => trim((string) ($data['title'] ?? '')) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Document added.');
    }

    public function download(Request $request, StyleDocument $document)
    {
        $mother = $document->motherStyle;
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin', 'management']) && $request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    public function destroy(Request $request, StyleDocument $document)
    {
        $mother = $document->motherStyle;
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Document deleted.');
    }
}
