<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A half-finished Record Issue or Record Receiving form.
 *
 * Purely saved form state. Nothing here is read by the Consumable Stock Report,
 * the ledger, either report screen or any stock check — see the migration for
 * why that separation is the whole point of the design.
 */
class StoreFormDraft extends Model
{
    /** The Record Issue form. */
    public const FORM_ISSUE = 'issue';

    /** The Record Receiving form. */
    public const FORM_RECEIVING = 'receiving';

    /**
     * Fields the form posts that are NOT part of the saved state.
     *
     * `_token` is per-session and would be stale on resume; `action` is the
     * button that was pressed; `draft_id` is which draft is being replaced and
     * would otherwise be restored into the form and point at itself.
     *
     * @var list<string>
     */
    public const NOT_FORM_STATE = ['_token', '_method', 'action', 'draft_id', 'form'];

    protected $fillable = ['form', 'created_by', 'label', 'payload'];

    protected $casts = ['payload' => 'array'];

    /** This user's drafts for one screen, newest touched first. */
    public function scopeMine(Builder $query, string $form): Builder
    {
        return $query
            ->where('form', $form)
            ->where('created_by', auth()->id())
            ->orderByDesc('updated_at');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the signed-in user may open or delete this draft.
     *
     * Both halves matter. The owner check is the rule; the form check stops a
     * receiving draft being replayed into the issue form, where its fields
     * would land in whatever happened to share a name.
     */
    public function belongsToCurrentUser(string $form): bool
    {
        return $this->form === $form && (int) $this->created_by === (int) auth()->id();
    }

    /**
     * The form state, minus anything that must not be restored.
     *
     * Filtered on the way OUT as well as on the way in, so a draft saved before
     * this list grew cannot put a stale token back into the form.
     *
     * @return array<string, mixed>
     */
    public function formState(): array
    {
        return collect($this->payload ?? [])
            ->except(self::NOT_FORM_STATE)
            ->all();
    }
}
