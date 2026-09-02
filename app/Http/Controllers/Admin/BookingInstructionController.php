<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingInstruction;
use App\Models\Buyer;
use Illuminate\Http\Request;

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

        return view('admin.booking-instructions.index', compact('instructions', 'stats'));
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
        return view('admin.booking-instructions.edit', [
            'instruction' => $bookingInstruction,
            'buyers' => $this->buyerOptions($bookingInstruction->buyer_id),
        ]);
    }

    public function update(Request $request, BookingInstruction $bookingInstruction)
    {
        $data = $this->validatedData($request);
        $data['updated_by'] = auth()->id();

        $bookingInstruction->update($data);

        return redirect()
            ->route('admin.booking-instructions.index')
            ->with('success', 'Booking instruction updated successfully.');
    }

    public function destroy(BookingInstruction $bookingInstruction)
    {
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
            'is_default' => $data['instruction_type'] === 'default',
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
