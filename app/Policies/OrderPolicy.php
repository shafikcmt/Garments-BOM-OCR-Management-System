<?php
// app/Policies/OrderPolicy.php
class OrderPolicy
{
    public function create(User $user)
    {
        return $user->hasRole('merchant');
    }

    public function submit(User $user, Order $order)
    {
        return $user->hasRole('merchant')
            && $order->status === 'draft'
            && $order->created_by === $user->id;
    }

    public function approve(User $user)
    {
        return $user->hasRole('admin');
    }

    public function work(User $user, Order $order)
    {
        return $order->status === 'approved';
    }
}

