<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderSection;
use App\Models\OrderField;
use App\Models\OrderValue;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    // List all orders
    public function index()
    {
        $orders = Order::latest()->get();
        return view('admin.orders.index', compact('orders'));
    }

    // Approve order
    public function approve(Order $order)
    {
        $order->status = 'approved';
        $order->approved_by = Auth::id();
        $order->save();

        return redirect()->back()->with('success', 'Order approved successfully.');
    }

    // Reject order
    public function reject(Order $order)
    {
        $order->status = 'rejected';
        $order->approved_by = Auth::id();
        $order->save();

        return redirect()->back()->with('error', 'Order rejected.');
    }

    // Show order with role-wise dynamic fields (after approval)
    public function show(Order $order)
    {
        if($order->status != 'approved'){
            return redirect()->back()->with('error', 'Order not approved yet.');
        }

        // Fetch all active sections & fields for roles
        $sections = OrderSection::with(['fields' => function($q){
            $q->where('is_active', true)->orderBy('sort_order');
        }])->where('is_active', true)->orderBy('sort_order')->get();

        return view('admin.orders.show', compact('order', 'sections'));
    }

    // Store role-wise field data
    public function storeFieldData(Request $request, Order $order)
    {
        $data = $request->input('fields', []);

        foreach($data as $field_id => $value){
            OrderValue::updateOrCreate(
                ['order_id' => $order->id, 'field_id' => $field_id, 'user_id' => Auth::id()],
                ['value' => $value, 'role_id' => Auth::user()->role_id]
            );
        }

        return redirect()->back()->with('success', 'Order data saved successfully.');
    }
}
