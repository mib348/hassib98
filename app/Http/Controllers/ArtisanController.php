<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ArtisanController extends Controller
{
    public function migrate(Request $request)
    {
        if($request->input('type') == 'fresh') {
            Artisan::call('migrate:fresh');
            echo $output = Artisan::output() . '<br>';
        } else {
            Artisan::call('migrate');
            echo $output = Artisan::output() . '<br>';
        }

    }

    public function cache(Request $request){
        // echo shell_exec('php artisan config:clear');
        var_dump(exec('php artisan route:list > 2&1'));
        var_dump(shell_exec('php artisan route:list > 2&1'));
        Artisan::call('cache:clear');
        echo $output = Artisan::output() . '<br>';
        Artisan::call('config:clear');
        echo $output = Artisan::output() . '<br>';
        Artisan::call('view:clear');
        echo $output = Artisan::output() . '<br>';
        Artisan::call('route:clear');
        echo $output = Artisan::output() . '<br>';
        Artisan::call('config:cache');
        echo $output = Artisan::output() . '<br>';
        Artisan::call('view:cache');
        echo $output = Artisan::output() . '<br>';
        Artisan::call('route:cache');
        echo $output = Artisan::output() . '<br>';
    }

    public function storage(Request $request){
        Artisan::call('storage:link');
        echo $output = Artisan::output() . '<br>';
    }

    public function queue_start(Request $request){
        Artisan::call('queue:work', [
            '--daemon' => 'true',
            '--timeout' => '0',
            '--memory' => '1024',
        ]);
        echo $output = Artisan::output() . '<br>';
    }

    public function queue_stop(Request $request){
        Artisan::call('queue:work', [
            '--stop-when-empty',
        ]);
        echo $output = Artisan::output() . '<br>';
    }
    public function queue_retry(Request $request){
        Artisan::call('queue:retry', [
            'all',
        ]);
        echo $output = Artisan::output() . '<br>';
    }

    public function queue_clear(Request $request){
        Artisan::call('queue:clear');
        echo $output = Artisan::output() . '<br>';
        Artisan::call('queue:flush');
        echo $output = Artisan::output() . '<br>';
    }
}
