<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        return view('commercial.dashboard');
    }
}