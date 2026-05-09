<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingDeliveryDestination;
use Illuminate\Http\Request;

class BookingDeliveryDestinationController extends Controller
{
    public function index()
    {
        $destinations = BookingDeliveryDestination::query()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate(15);

        return view('admin.booking-delivery-destinations.index', compact('destinations'));
    }

    public function create()
    {
        return view('admin.booking-delivery-destinations.create');
    }

    public function store(Request $request)
    {
        BookingDeliveryDestination::create($this->validatedData($request));

        return redirect()
            ->route('admin.booking-delivery-destinations.index')
            ->with('success', 'Delivery destination created successfully.');
    }

    public function edit(BookingDeliveryDestination $bookingDeliveryDestination)
    {
        return view('admin.booking-delivery-destinations.edit', [
            'destination' => $bookingDeliveryDestination,
        ]);
    }

    public function update(Request $request, BookingDeliveryDestination $bookingDeliveryDestination)
    {
        $bookingDeliveryDestination->update($this->validatedData($request));

        return redirect()
            ->route('admin.booking-delivery-destinations.index')
            ->with('success', 'Delivery destination updated successfully.');
    }

    public function destroy(BookingDeliveryDestination $bookingDeliveryDestination)
    {
        $bookingDeliveryDestination->delete();

        return redirect()
            ->route('admin.booking-delivery-destinations.index')
            ->with('success', 'Delivery destination deleted successfully.');
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
