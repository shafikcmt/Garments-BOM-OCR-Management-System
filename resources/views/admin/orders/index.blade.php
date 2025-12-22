@extends('layouts.app')
@section('content')
<div class="container">
    <h4>All Orders</h4>
    <table class="table table-bordered">
        <thead>
            <tr><th>Order No</th><th>Buyer</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
        @foreach($orders as $order)
            <tr>
                <td>{{ $order->order_number }}</td>
                <td>{{ $order->buyer_name }}</td>
                <td><span class="badge bg-{{ $order->status=='approved'?'success':($order->status=='rejected'?'danger':'warning') }}">{{ ucfirst($order->status) }}</span></td>
                <td>
                    <a href="{{ route('admin.orders.show',$order->id) }}" class="btn btn-info btn-sm">View</a>
                    @if($order->status=='pending')
                        <form class="d-inline" method="POST" action="{{ route('admin.orders.approve',$order->id) }}">@csrf <button class="btn btn-success btn-sm">Approve</button></form>
                        <form class="d-inline" method="POST" action="{{ route('admin.orders.reject',$order->id) }}">@csrf <button class="btn btn-danger btn-sm">Reject</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
