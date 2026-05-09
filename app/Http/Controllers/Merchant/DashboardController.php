<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        return view('merchant.dashboard');
    }
}   