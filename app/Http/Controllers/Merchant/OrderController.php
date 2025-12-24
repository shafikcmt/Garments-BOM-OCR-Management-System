<?php
namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use Auth;

class OrderController extends Controller
{
    // List Orders
    public function index()
    {
        $orders = Order::where('created_by', Auth::id())->latest()->get();
        return view('merchant.orders.index', compact('orders'));
    }

    // Show single order
    public function show($id)
    {
        $order = Order::where('id', $id)
            ->where('created_by', Auth::id())
            ->firstOrFail();

        return response()->json($order);
    }

    // Store new order
    public function store(Request $request)
    {
        $data = $this->validateOrder($request);

        $order = Order::create(array_merge($data, ['created_by' => Auth::id()]));

        return redirect()->back()->with('success', 'Order created successfully.');
    }

    // Update existing order
    public function update(Request $request, $id)
    {
        $data = $this->validateOrder($request);

        $order = Order::where('id', $id)
            ->where('created_by', Auth::id())
            ->firstOrFail();

        $order->update($data);

        return redirect()->back()->with('success', 'Order updated successfully.');
    }

    // Delete order
    public function destroy($id)
    {
        $order = Order::where('id', $id)
            ->where('created_by', Auth::id())
            ->where('status', '!=', 'approved')
            ->firstOrFail();

        $order->delete();
        return redirect()->back()->with('success', 'Order deleted successfully');
    }

    // Validation helper
    private function validateOrder(Request $request)
    {
        return $request->validate([
            'buyer_name' => 'required|string|max:255',
            'division' => 'required|string|max:255',
            'season_name' => 'required|string|max:255',
            'order_status' => 'required|string|max:50',
            'order_category' => 'required|string|max:50',
            'product_type' => 'required|string|max:255',
            'style_name' => 'required|string|max:255',
            'po_number' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'wash_type' => 'nullable|string|max:255',
            'order_qty' => 'required|numeric|min:0',
            'sewing_qty' => 'required|numeric|min:0',
            'smv' => 'nullable|numeric|min:0',
            'fob' => 'nullable|numeric|min:0',
            'gm' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:255',
            'pcd' => 'nullable|date',
            'x_fty' => 'nullable|date',
            'x_country' => 'nullable|date',
            'original_x_fty' => 'nullable|date',
            'original_x_country' => 'nullable|date',
            'shipment_status' => 'nullable|string|max:255',
            'fabric_booking_status' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:500',
        ]);
    }
}
