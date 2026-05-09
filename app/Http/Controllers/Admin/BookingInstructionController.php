<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingInstruction;
use Illuminate\Http\Request;

class BookingInstructionController extends Controller
{
    public function index()
    {
        $instructions = BookingInstruction::query()
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
        ]);
    }

    public function store(Request $request)
    {
        BookingInstruction::create($this->validatedData($request));

        return redirect()
            ->route('admin.booking-instructions.index')
            ->with('success', 'Booking instruction created successfully.');
    }

    public function edit(BookingInstruction $bookingInstruction)
    {
        return view('admin.booking-instructions.edit', [
            'instruction' => $bookingInstruction,
        ]);
    }

    public function update(Request $request, BookingInstruction $bookingInstruction)
    {
        $bookingInstruction->update($this->validatedData($request));

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

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'instruction' => ['required', 'string', 'max:1000'],
            'instruction_type' => ['required', 'in:default,suggestion'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'instruction' => trim($data['instruction']),
            'is_default' => $data['instruction_type'] === 'default',
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
