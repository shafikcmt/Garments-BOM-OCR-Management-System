<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingInstruction;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Booking instruction library.
 *
 * TWO TIERS OF ACCESS, and the difference is the whole point of this class:
 *
 *   admin                       everything, including is_default.
 *   booking.instructions.manage suggestions only — create, edit and delete
 *                               rows with is_default = false.
 *
 * A default instruction prints on every matching PO without anyone asking for
 * it, so promoting a row to default is a change to what suppliers receive
 * company-wide. That decision stays with the admin. The radio is disabled in
 * the form for anyone else, and every write below re-checks it, because a
 * disabled input is a courtesy to the user and not a guard against a request.
 */
class BookingInstructionController extends Controller
{
    public function index()
    {
        $instructions = BookingInstruction::query()
            ->with('buyer')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('instruction')
            ->paginate(20);

        $stats = [
            'total' => BookingInstruction::count(),
            'default' => BookingInstruction::where('is_default', true)->count(),
            'suggested' => BookingInstruction::where('is_default', false)->count(),
            'active' => BookingInstruction::where('is_active', true)->count(),
        ];

        return view('admin.booking-instructions.index', [
            'instructions' => $instructions,
            'stats' => $stats,
            'canManageDefaults' => $this->canManageDefaults(),
        ]);
    }

    public function create()
    {
        return view('admin.booking-instructions.create', [
            'instruction' => new BookingInstruction([
                'is_default' => false,
                'is_active' => true,
                'sort_order' => ((int) BookingInstruction::max('sort_order')) + 10,
            ]),
            'buyers' => $this->buyerOptions(),
            'canManageDefaults' => $this->canManageDefaults(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        BookingInstruction::create($data);

        return redirect()
            ->route('admin.booking-instructions.index')
            ->with('success', 'Booking instruction created successfully.');
    }

    public function edit(BookingInstruction $bookingInstruction)
    {
        $this->authorizeTouching($bookingInstruction);

        return view('admin.booking-instructions.edit', [
            'instruction' => $bookingInstruction,
            'buyers' => $this->buyerOptions($bookingInstruction->buyer_id),
            'canManageDefaults' => $this->canManageDefaults(),
        ]);
    }

    public function update(Request $request, BookingInstruction $bookingInstruction)
    {
        $this->authorizeTouching($bookingInstruction);

        $data = $this->validatedData($request);
        $data['updated_by'] = auth()->id();

        $bookingInstruction->update($data);

        return redirect()
            ->route('admin.booking-instructions.index')
            ->with('success', 'Booking instruction updated successfully.');
    }

    public function destroy(BookingInstruction $bookingInstruction)
    {
        $this->authorizeTouching($bookingInstruction);

        $bookingInstruction->delete();

        return redirect()
            ->route('admin.booking-instructions.index')
            ->with('success', 'Booking instruction deleted successfully.');
    }

    /**
     * Buyers offered on the instruction form.
     *
     * withTrashed() when a soft-deleted buyer is the one already selected: six
     * of the nine buyers are soft-deleted and two still appear on live POs, so
     * an active-only list would silently blank the field on edit and re-save
     * the instruction as global — quietly widening it back to every buyer.
     */
    private function buyerOptions(?int $selectedId = null)
    {
        return Buyer::query()
            ->when($selectedId, fn ($query) => $query->withTrashed())
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $selectedId))
            ->orderBy('buyer_name')
            ->get(['id', 'buyer_name']);
    }

    /**
     * May the current user decide whether an instruction is a default one?
     *
     * Admin only. Asked through the role rather than a permission because there
     * is deliberately no permission to grant here — the answer is meant to be
     * "the super admin, and nobody else" until somebody decides otherwise.
     */
    private function canManageDefaults(): bool
    {
        return (bool) auth()->user()?->hasRole('admin');
    }

    /**
     * An existing default row is off limits to a permission-only holder.
     *
     * Edit and delete both route through here, so the two ways of damaging a
     * default — rewriting its text and removing it outright — are closed by the
     * same check rather than by two that could drift apart.
     */
    private function authorizeTouching(BookingInstruction $instruction): void
    {
        if ($instruction->is_default && ! $this->canManageDefaults()) {
            throw new AccessDeniedHttpException(
                'Default instructions can only be changed by an administrator.'
            );
        }
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'instruction' => ['required', 'string', 'max:1000'],
            'instruction_type' => ['required', 'in:default,suggestion'],
            'buyer_id' => ['nullable', 'integer', 'exists:buyers,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'instruction' => trim($data['instruction']),
            // Blank means global. Cast through ?: null so an empty select value
            // stores NULL rather than 0, which no buyer would ever match.
            'buyer_id' => ((int) ($data['buyer_id'] ?? 0)) ?: null,
            // The form disables the "Default instruction" radio for a
            // permission-only holder; this is the check that actually enforces
            // it, against a hand-made POST as much as a stale page.
            'is_default' => $this->canManageDefaults() && $data['instruction_type'] === 'default',
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
