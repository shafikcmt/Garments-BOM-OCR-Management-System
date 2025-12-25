<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Imports\OrdersImport;
use App\Exports\OrdersExport;
use App\Exports\OrdersDemoExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use PDF;
use Maatwebsite\Excel\Validators\ValidationException;


class OrderController extends Controller
{
    /* =======================
       List Orders
    ======================== */
    public function index()
    {
        $orders = Order::where('created_by', Auth::id())
            ->latest()
            ->get();

        return view('merchant.orders.index', compact('orders'));
    }

    /* =======================
       Show Single Order (AJAX)
    ======================== */
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

    // Auto-calculate values
    $data['total_minutes'] = ($data['order_qty'] ?? 0) * ($data['smv'] ?? 0);
    $data['sales_value']   = ($data['order_qty'] ?? 0) * ($data['fob'] ?? 0);
    $data['balance_to_sewing'] = ($data['order_qty'] ?? 0) - ($data['sewing_qty'] ?? 0);

    // Calculate dates
    if (!empty($data['x_fty'])) {
        $xFty = strtotime($data['x_fty']);
        $data['pcd']      = date('d-m-Y', $xFty - 45*24*60*60);
        $data['x_country'] = date('d-m-Y', $xFty + 2*24*60*60);
    }
    if (!empty($data['original_x_fty'])) {
        $orig = strtotime($data['original_x_fty']);
        $data['original_x_country'] = date('d-m-Y', $orig + 2*24*60*60);
    }

    $order = Order::create(array_merge($data, ['created_by' => Auth::id()]));

    return redirect()->back()->with('success', 'Order created successfully.');
}

// Update existing order
public function update(Request $request, $id)
{
    $data = $this->validateOrder($request);

    // Auto-calculate values
    $data['total_minutes'] = ($data['order_qty'] ?? 0) * ($data['smv'] ?? 0);
    $data['sales_value']   = ($data['order_qty'] ?? 0) * ($data['fob'] ?? 0);
    $data['balance_to_sewing'] = ($data['order_qty'] ?? 0) - ($data['sewing_qty'] ?? 0);

    // Calculate dates
    if (!empty($data['x_fty'])) {
        $xFty = strtotime($data['x_fty']);
        $data['pcd']      = date('d-m-Y', $xFty - 45*24*60*60);
        $data['x_country'] = date('d-m-Y', $xFty + 2*24*60*60);
    }
    if (!empty($data['original_x_fty'])) {
        $orig = strtotime($data['original_x_fty']);
        $data['original_x_country'] = date('d-m-Y', $orig + 2*24*60*60);
    }

    $order = Order::where('id', $id)
                  ->where('created_by', Auth::id())
                  ->firstOrFail();

    $order->update($data);

    return redirect()->back()->with('success', 'Order updated successfully.');
}



    /* =======================
       Delete Order
    ======================== */
    public function destroy($id)
    {
        $order = Order::where('id', $id)
            ->where('created_by', Auth::id())
            ->where('status', '!=', 'approved')
            ->firstOrFail();

        $order->delete();

        return redirect()->back()->with('success', 'Order deleted successfully');
    }

    /* =======================
       Validation + Calculations
    ======================== */
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


    /* =======================
       Bulk Import
    ======================== */
public function import(Request $request)
{
    $import = new OrdersImport(auth()->id());
    Excel::import($import, $request->file('file'));

    if ($import->failures()->isNotEmpty()) {
        $errors = [];
        foreach ($import->failures() as $failure) {
            $errors[] = 'Row '.$failure->row().': '.implode(', ', $failure->errors());
        }
        // Show failed rows in alert box
        return redirect()->back()->with('import_errors', $errors);
    }

    return redirect()->back()->with('success', 'Orders imported successfully.');
}



    public function downloadDemoFile()
{
    $headers = [
        'buyer_name', 'division', 'season_name', 'order_status', 'order_category',
        'product_type', 'style_name', 'po_number', 'description', 'wash_type',
        'order_qty', 'sewing_qty', 'smv', 'fob', 'gm', 'destination', 'x_fty',
        'original_x_fty', 'shipment_status', 'fabric_booking_status', 'remarks'
    ];

    $filename = 'orders_demo.xlsx';

    return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\DemoExport($headers), $filename);
}


    /* =======================
       Export → Excel
    ======================== */
    public function exportExcel(Request $request)
    {
        return Excel::download(
            new OrdersExport(Auth::id(), $request->all()),
            'orders.xlsx'
        );
    }

    /* =======================
       Export → PDF
    ======================== */
    public function exportPDF(Request $request)
    {
        $orders = Order::where('created_by', Auth::id());

        if ($request->buyer_name)
            $orders->where('buyer_name', 'like', "%{$request->buyer_name}%");

        if ($request->season_name)
            $orders->where('season_name', 'like', "%{$request->season_name}%");

        if ($request->order_status)
            $orders->where('order_status', $request->order_status);

        $orders = $orders->get();

        $pdf = PDF::loadView('merchant.orders.report', compact('orders'));

        return $pdf->download('orders_report.pdf');
    }
}
