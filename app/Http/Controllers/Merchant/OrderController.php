<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use Auth;
use Carbon\Carbon;

class OrderController extends Controller
{
    // List Orders
    public function index()
    {
        $orders = Order::where('created_by', Auth::id())
            ->latest()
            ->get();

        return view('merchant.orders.index', compact('orders'));
    }

    // Create / Edit Order
    public function store(Request $request)
    {
        $request->validate([
            'buyer_name'      => 'required',
            'season_name'     => 'required',
            'order_number'    => 'required',
            'style_name'      => 'required',
            'quantity'        => 'required|numeric',
            'shipment_date'   => 'required|date',
            'contract_number' => 'required',
        ]);

        // EDIT EXISTING ORDER
        if ($request->order_id) {
            $order = Order::where('id', $request->order_id)
                ->where('created_by', Auth::id())
                ->where('status', '!=', 'approved')
                ->firstOrFail();

            $order->update([
                'buyer_name'      => $request->buyer_name,
                'season_name'     => $request->season_name,
                'order_number'    => $request->order_number,
                'style_name'      => $request->style_name,
                'quantity'        => $request->quantity,
                'shipment_date'   => $request->shipment_date,
                'contract_number' => $request->contract_number,
                'status'          => $request->status ?? 'draft',
            ]);

            return redirect()->back()->with('success','Order updated successfully');
        }

        // CREATE NEW ORDER
        Order::create([
            'buyer_name'      => $request->buyer_name,
            'season_name'     => $request->season_name,
            'order_number'    => $request->order_number,
            'style_name'      => $request->style_name,
            'quantity'        => $request->quantity,
            'shipment_date'   => $request->shipment_date,
            'contract_number' => $request->contract_number,
            'status'          => $request->status ?? 'draft',
            'created_by'      => Auth::id(),
        ]);

        return redirect()->back()->with('success','Order created successfully');
    }

    // Delete Order
    public function destroy($id)
    {
        $order = Order::where('id', $id)
            ->where('created_by', Auth::id())
            ->where('status', '!=', 'approved') // cannot delete approved orders
            ->firstOrFail();

        $order->delete();
        return redirect()->back()->with('success','Order deleted successfully');
    }
}
