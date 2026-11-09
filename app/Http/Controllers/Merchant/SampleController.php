<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\MotherStyle;
use App\Models\Sample;
use App\Models\SampleType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Sample tracking. The Samples list shows every sample of the user's buyers
 * (late ones first); samples are added and updated from the style page or the
 * list. Merchandising and Admin edit, Management reads. Sample types are a
 * list kept here, not in code.
 */
class SampleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $buyers = Buyer::active()
            ->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))
            ->orderBy('buyer_name')->get();
        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));
        $status = array_key_exists($request->query('status'), Sample::STATUSES) || $request->query('status') === 'open' ? $request->query('status') : 'open';
        $typeId = (int) $request->query('type') ?: null;

        $samples = Sample::query()
            ->with(['type', 'motherStyle.buyer', 'motherStyle.season', 'childStyle', 'logs'])
            ->whereHas('motherStyle', fn ($q) => $q->whereIn('buyer_id', $buyer ? [$buyer->id] : $buyers->pluck('id')))
            ->when($status === 'open', fn ($q) => $q->whereNotIn('status', Sample::CLOSED))
            ->when($status !== 'open', fn ($q) => $q->where('status', $status))
            ->when($typeId, fn ($q) => $q->where('sample_type_id', $typeId))
            ->orderByRaw('case when required_date is null then 1 else 0 end')
            ->orderBy('required_date')
            ->limit(500)
            ->get();

        $today = Carbon::today();

        return view('merchant.samples', [
            'samples' => $samples,
            'buyers' => $buyers,
            'buyer' => $buyer,
            'status' => $status,
            'typeId' => $typeId,
            'types' => SampleType::orderBy('position')->orderBy('name')->get(),
            'late' => $samples->filter(fn ($s) => $s->isLate($today))->count(),
            'canEdit' => $user->hasAnyRole(['merchant', 'admin']),
        ]);
    }

    /** One sample's history: every status change and buyer comment, with the rework count. */
    public function show(Request $request, Sample $sample)
    {
        $sample->load(['motherStyle.buyer', 'motherStyle.season', 'childStyle', 'type', 'logs.user:id,name']);
        abort_unless($request->user()->hasBuyerAccess((int) $sample->motherStyle->buyer_id), 403);

        return view('merchant.sample', ['sample' => $sample]);
    }

    /** The buyers' comments on samples that were sent back, searchable: what to remember for the next sample. */
    public function lessons(Request $request)
    {
        $user = $request->user();
        $buyers = Buyer::active()->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))->orderBy('buyer_name')->get();
        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));
        $term = trim((string) $request->query('q', ''));

        $logs = \App\Models\SampleLog::with(['sample.type', 'sample.motherStyle.buyer', 'sample.motherStyle.season'])
            ->whereIn('status', Sample::REWORK)->whereNotNull('note')->where('note', '!=', '')
            ->whereHas('sample.motherStyle', fn ($q) => $q->whereIn('buyer_id', $buyer ? [$buyer->id] : $buyers->pluck('id')))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('note', '%' . $term . '%')
                ->orWhereHas('sample.motherStyle', fn ($s) => $s->whereLike('style_no', '%' . $term . '%')->orWhereLike('product_group', '%' . $term . '%'))))
            ->orderByDesc('event_date')->orderByDesc('id')->limit(300)->get();

        return view('merchant.sample-lessons', ['logs' => $logs, 'buyers' => $buyers, 'buyer' => $buyer, 'term' => $term]);
    }

    public function store(Request $request, MotherStyle $mother)
    {
        $this->authorizeEdit($request, $mother);
        $data = $this->validated($request, $mother);

        $sample = $mother->samples()->create($data + ['status' => 'requested', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $sample->assignTag();
        $sample->logs()->create(['status' => 'requested', 'event_date' => Carbon::today()->toDateString(), 'user_id' => $request->user()->id, 'created_at' => now()]);

        return back()->with('success', 'Sample added. Tag ID ' . $sample->tag_id . '.');
    }

    public function update(Request $request, Sample $sample)
    {
        $this->authorizeEdit($request, $sample->motherStyle);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Sample::STATUSES))],
            'sent_date' => ['nullable', 'date'],
            'courier_awb' => ['nullable', 'string', 'max:120'],
            'buyer_comment' => ['nullable', 'string', 'max:2000'],
            'comment_date' => ['nullable', 'date'],
        ]);

        if ($data['status'] === 'sent' && empty($data['sent_date'])) {
            $data['sent_date'] = $sample->sent_date?->toDateString() ?? Carbon::today()->toDateString();
        }
        if (! empty($data['buyer_comment']) && empty($data['comment_date']) && $data['buyer_comment'] !== $sample->buyer_comment) {
            $data['comment_date'] = Carbon::today()->toDateString();
        }

        $statusChanged = $data['status'] !== $sample->status;
        $commentChanged = ! empty($data['buyer_comment']) && $data['buyer_comment'] !== $sample->buyer_comment;

        $sample->update($data + ['updated_by' => $request->user()->id]);

        // Remember what happened: a new status, or a new buyer comment, is one line of history.
        if ($statusChanged || $commentChanged) {
            $sample->logs()->create([
                'status' => $sample->status, 'note' => $commentChanged ? $data['buyer_comment'] : null,
                'event_date' => ($commentChanged ? ($data['comment_date'] ?? null) : null) ?: Carbon::today()->toDateString(),
                'user_id' => $request->user()->id, 'created_at' => now(),
            ]);
        }

        return back()->with('success', 'Sample updated.');
    }

    public function destroy(Request $request, Sample $sample)
    {
        $this->authorizeEdit($request, $sample->motherStyle);
        $sample->delete();

        return back()->with('success', 'Sample deleted.');
    }

    public function storeType(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:60', 'unique:sample_types,name']]);
        SampleType::create($data + ['position' => (int) SampleType::max('position') + 1]);

        return back()->with('success', 'Sample type "' . $data['name'] . '" added.');
    }

    public function toggleType(Request $request, SampleType $type)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']), 403);
        $type->update(['is_active' => ! $type->is_active]);

        return back()->with('success', 'Sample type "' . $type->name . '" ' . ($type->is_active ? 'switched on.' : 'switched off.'));
    }

    private function validated(Request $request, MotherStyle $mother): array
    {
        return $request->validate([
            'sample_type_id' => ['required', Rule::exists('sample_types', 'id')->where('is_active', true)],
            'child_style_id' => ['nullable', Rule::exists('child_styles', 'id')->where('mother_style_id', $mother->id)],
            'gmts_color' => ['nullable', 'string', 'max:120'],
            'size' => ['nullable', 'string', 'max:40'],
            'qty' => ['required', 'integer', 'min:1', 'max:10000'],
            'required_date' => ['nullable', 'date'],
        ], ['sample_type_id.required' => 'Choose the sample type.']);
    }

    private function authorizeEdit(Request $request, MotherStyle $mother): void
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);
    }
}
