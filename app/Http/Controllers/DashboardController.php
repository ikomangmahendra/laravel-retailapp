<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): View
    {
        $totalProducts = Product::query()->count();

        $totalStockValue = Product::query()->sum(DB::raw('stock * selling_price'));

        $lowStockProducts = Product::query()
            ->with('category')
            ->where('stock', '<', 10)
            ->orderBy('stock')
            ->get();

        return view('dashboard', compact('totalProducts', 'totalStockValue', 'lowStockProducts'));
    }
}
