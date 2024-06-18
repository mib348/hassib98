<?php

namespace App\Console\Commands;

use App\Models\LocationRevenue;
use App\Models\Orders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportRevenue extends Command
{
    protected $signature = 'shopify:import-revenue';
    protected $description = 'Import Revenue from Shopify';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Import Revenue from Shopify');
        echo 'Import Revenue from Shopify' . PHP_EOL;

        // $arrOrders = Orders::select('location', 'date', 'day', 'total_price')
        //                     ->whereRaw("location in (select name from locations)
        //                                 and (total_price IS NOT NULL and total_price != 0)
        //                                 and financial_status = 'paid'")->get()->toArray();

        // foreach ($arrOrders as $key => $order) {
        //     $arrData = LocationRevenue::updateOrInsert([
        //         'location' => $order['location'],
        //         'date' => $order['date'],
        //         'day' => $order['day'],
        //     ], [
        //         'location' => $order['location'],
        //         'date' => $order['date'],
        //         'day' => $order['day'],
        //         'amount' => $order['total_price'],
        //     ]);
        // }

        $arrOrders = Orders::selectRaw('location, DATE_FORMAT(date, "%Y-%m") as month_year, SUM(total_price) as total_revenue')
        ->whereRaw("location in (select name from locations)
                    and financial_status = 'paid'")
        ->groupBy('location', 'month_year')
        ->get()->toArray();
        // $arrOrders = Orders::selectRaw('location, DATE_FORMAT(date, "%Y-%m") as month_year, SUM(total_price) as total_revenue')
        // ->whereRaw("location in (select name from locations)
        //             and (total_price IS NOT NULL and total_price != 0)
        //             and financial_status = 'paid'")
        // ->groupBy('location', 'month_year')
        // ->get()->toArray();

foreach ($arrOrders as $order) {
// Insert or update the record in the LocationRevenue table
$arrData = LocationRevenue::updateOrInsert([
'location' => $order['location'],
'date' => $order['month_year'], // Use month-year as date
], [
'location' => $order['location'],
'date' => $order['month_year'], // Use month-year as date
'amount' => $order['total_revenue'],
]);
}

        Log::info('Import Revenue from Shopify completed');
        echo 'Import Revenue from Shopify completed' . PHP_EOL;
    }
}
