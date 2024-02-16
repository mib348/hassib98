<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AfterAuthenticateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        ini_set('max_execution_time', 0);
        Log::info(env('APP_NAME') . ' app has been installed on your store');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info(env('APP_NAME') . ' app has been installed on your store');
    }
}
