<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        return view('store.dashboard');
    }
}