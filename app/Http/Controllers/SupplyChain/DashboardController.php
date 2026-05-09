<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        return view('supply-chain.dashboard');
    }
}