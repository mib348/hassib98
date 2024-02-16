<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

class AppUninstalledJob extends \Osiset\ShopifyApp\Messaging\Jobs\AppUninstalledJob
{
    public function __construct()
    {
        ini_set('max_execution_time', 0);
        Log::info(env('APP_NAME') . ' app has been un-installed from your store');
    }
}
