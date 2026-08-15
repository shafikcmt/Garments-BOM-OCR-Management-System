<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExcelUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->mayUploadBom();
    }

    /**
     * A merchant scoped to a buyer never chooses one — the controller takes it
     * from their own assignment, so the field is neither shown nor accepted.
     * Everyone else still must pick, exactly as before.
     */
    private function buyerIsChosenByUser(): bool
    {
        return auth()->user()?->merchantBuyerId() === null;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/octet-stream,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/excel,application/x-excel',
            ],
            'buyer_id' => [
                $this->buyerIsChosenByUser() ? 'required' : 'nullable',
                'integer',
                'exists:buyers,id',
            ],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file.',
            'file.file' => 'Uploaded item must be a valid file.',
            'file.mimes' => 'Only xlsx, xls, csv file is allowed.',
            'file.mimetypes' => 'Invalid file format detected. Please upload xlsx, xls, or csv file.',
            'buyer_id.required' => 'Please select a buyer for this file.',
            'buyer_id.exists' => 'Selected buyer is not available. Please choose an active buyer.',
        ];
    }
}