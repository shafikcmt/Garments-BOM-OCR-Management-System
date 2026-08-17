<?php

namespace App\Http\Controllers\Concerns;

use App\Models\StoreFormDraft;
use Illuminate\Http\Request;

/**
 * Saving, resuming and discarding a half-finished Record Issue / Record
 * Receiving form.
 *
 * The controller says which form it is in $draftForm; everything else is the
 * same on both screens, so it lives here once.
 *
 * RESUMING IS DONE BY FLASHING THE PAYLOAD AS OLD INPUT. Both forms already
 * repopulate themselves from old() — that is how a rejected submission comes
 * back filled in, item lines and all — so a draft put into the old-input bag
 * and redirected to the screen reopens the real form, fully editable, with no
 * second rendering path to build or keep in step. Resume is therefore not a
 * preview of a draft; it IS the form, exactly as the user left it.
 *
 * SAVING IS LENIENT ON PURPOSE. A draft exists because somebody was
 * interrupted, so it is half-typed by definition and validating it the way a
 * real submission is validated would refuse to save the very thing this is for.
 * Nothing here writes stock, so nothing here needs to be complete or even
 * consistent. The full checks — required fields, and the stock-availability
 * rules — run when the form is submitted for real, unchanged.
 */
trait ManagesFormDrafts
{
    /**
     * Keep a half-finished form.
     *
     * Updates the draft that was resumed rather than making a second copy of
     * it: without that, saving twice while working on one requisition would
     * leave two drafts, and the user would have to work out which is newer.
     */
    public function saveDraft(Request $request)
    {
        $this->authorizeDraftAction();

        $payload = collect($request->except(StoreFormDraft::NOT_FORM_STATE))
            // Drop lines the user added and never filled in, so an untouched
            // blank row does not come back on resume as an error waiting to
            // happen.
            ->map(fn ($value, $key) => $key === 'items' ? $this->pruneDraftItems($value) : $value)
            ->all();

        $existing = $this->resolveDraft($request->input('draft_id'));

        if ($existing) {
            $existing->update([
                'label' => $this->draftLabel($payload),
                'payload' => $payload,
            ]);
        } else {
            StoreFormDraft::create([
                'form' => $this->draftForm,
                'created_by' => auth()->id(),
                'label' => $this->draftLabel($payload),
                'payload' => $payload,
            ]);
        }

        return back()->with('success', 'Draft saved. It is waiting under Saved Drafts on this screen.');
    }

    /**
     * Reopen a draft in the form it came from.
     *
     * flashInput puts the payload where old() reads from, so the redirect lands
     * on the ordinary screen and the ordinary form fills itself in.
     */
    public function resumeDraft(StoreFormDraft $storeFormDraft)
    {
        $this->authorizeDraftAction();

        abort_unless($storeFormDraft->belongsToCurrentUser($this->draftForm), 403,
            'That draft belongs to someone else.');

        session()->flashInput($storeFormDraft->formState() + ['draft_id' => $storeFormDraft->id]);

        return redirect()->to($this->draftReturnUrl())
            ->with('success', 'Draft reopened. Finish it and record it, or save it again for later.');
    }

    public function destroyDraft(StoreFormDraft $storeFormDraft)
    {
        $this->authorizeDraftAction();

        abort_unless($storeFormDraft->belongsToCurrentUser($this->draftForm), 403,
            'That draft belongs to someone else.');

        $storeFormDraft->delete();

        return back()->with('success', 'Draft deleted.');
    }

    /**
     * Throw away the draft a successful submission came from.
     *
     * Called after the real record is written, never before: if the submission
     * is rejected the draft has to still be there.
     */
    protected function discardDraftAfterSubmit(Request $request): void
    {
        $this->resolveDraft($request->input('draft_id'))?->delete();
    }

    /** This user's drafts for this screen. */
    protected function myDrafts()
    {
        return StoreFormDraft::query()->mine($this->draftForm)->get();
    }

    /** The draft named by the form, only if it is this user's and this form's. */
    private function resolveDraft(mixed $id): ?StoreFormDraft
    {
        if (blank($id)) {
            return null;
        }

        $draft = StoreFormDraft::find($id);

        return $draft?->belongsToCurrentUser($this->draftForm) ? $draft : null;
    }

    /**
     * Prune item lines that carry nothing.
     *
     * A line counts as touched when any of its fields has a value; the row of
     * empty inputs the form always shows at the bottom is not worth saving.
     *
     * @param  mixed  $items
     * @return array<int, mixed>
     */
    private function pruneDraftItems($items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($line) => collect((array) $line)->contains(fn ($v) => filled($v)))
            ->values()
            ->all();
    }
}
