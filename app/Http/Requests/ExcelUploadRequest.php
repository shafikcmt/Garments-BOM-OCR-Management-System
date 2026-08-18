<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExcelUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->mayUploadBom();
    }

    /**
     * The buyers this user may tag an upload with.
     *
     * Empty means UNSCOPED — a merchant with no assignment, and every other
     * department — who may pick any buyer, exactly as before buyer scoping
     * existed. Non-empty means the choice is confined to these.
     *
     * @return list<int>
     */
    private function allowedBuyerIds(): array
    {
        return auth()->user()?->merchantBuyerIds() ?? [];
    }

    /**
     * Fill in the buyer for the user who is never asked to choose one.
     *
     * A merchant with exactly one buyer sees a read-only chip rather than a
     * dropdown, so their form posts no buyer_id at all. Injecting it here
     * rather than adding a hidden field to the form means the rules below can
     * demand a buyer from EVERY scoped user without the single-buyer screen
     * having to change, and means a request that omits the field cannot slip
     * past the membership check by simply not carrying one.
     *
     * Deliberately only when there is exactly one, and only when nothing was
     * posted:
     *
     *   - Several buyers, nothing posted → left empty, so `required` fires. A
     *     silent default would tag a file to a buyer the user did not pick,
     *     which is the quiet-wrong-answer this whole phase exists to remove.
     *   - Something already posted → left alone, so it faces Rule::in below
     *     and is REFUSED if it is not theirs. Overwriting it here would hide
     *     the attempt instead of rejecting it.
     */
    protected function prepareForValidation(): void
    {
        $allowed = $this->allowedBuyerIds();

        if (count($allowed) === 1 && ! $this->filled('buyer_id')) {
            $this->merge(['buyer_id' => $allowed[0]]);
        }
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
            'buyer_id' => $this->buyerRules(),
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * THE ACCESS CONTROL FOR UPLOAD TAGGING. Read this before changing it.
     *
     * A scoped merchant's buyer_id must be one of their OWN buyers, and
     * Rule::in against merchantBuyerIds() is the whole of that control. It
     * applies to every scoped user alike — one buyer or five — so there is no
     * user for whom a posted buyer_id goes unchecked.
     *
     * WHAT THIS REPLACED, and why it had to: the controller used to resolve
     * the tag as `merchantBuyerId() ?? $request->integer('buyer_id')`. Null
     * scope meant "unscoped, may pick anything", which was true while every
     * scoped user had exactly one buyer. The obvious way to support several —
     * returning null when there is no single answer — would have dropped a
     * multi-buyer merchant straight through to unvalidated request input and
     * let them tag a file to ANY buyer in the system. An escalation wearing
     * the clothes of a null-safety refactor.
     *
     * The unscoped branch keeps today's rule unchanged. That is not an
     * oversight: an unscoped user is unrestricted BY DESIGN, the same reason
     * an untagged file is editable by everyone rather than by nobody.
     *
     * @return list<mixed>
     */
    private function buyerRules(): array
    {
        $allowed = $this->allowedBuyerIds();

        if ($allowed === []) {
            return ['required', 'integer', 'exists:buyers,id'];
        }

        return ['required', 'integer', Rule::in($allowed)];
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
            // Says the buyer is not theirs rather than that it does not exist.
            // The distinction matters to the honest user who picked a stale
            // option, and tells the dishonest one nothing they did not know.
            'buyer_id.in' => 'You are not assigned to that buyer. Choose one of your own buyers.',
        ];
    }
}
