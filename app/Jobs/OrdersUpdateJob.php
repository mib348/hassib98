<?php namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use stdClass;

class OrdersUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain
     *
     * @var ShopDomain|string
     */
    public $shopDomain;

    /**
     * The webhook data
     *
     * @var object
     */
    public $data;

    /**
     * Create a new job instance.
     *
     * @param string   $shopDomain The shop's myshopify domain.
     * @param stdClass $data       The webhook data (JSON decoded).
     *
     * @return void
     */
    public function __construct($shopDomain, $data)
    {
        ini_set('max_execution_time', 0);
        $this->shopDomain = $shopDomain;
        $this->data = $data;
        Log::info('Constructor Order Update Webhook: '. json_encode($this->data));
    }

    public function fail($error){
        Log::error('Handler Order Update Webhook Job Fail: '. json_encode($error));
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            Log::info('Handler Order Update Webhook: '. json_encode($this->data));

            $shop = Auth::user();
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));

            // Assuming $this->data contains order details including line items
            $orderData = json_decode(json_encode($this->data), true);
            $lineItems = $orderData['line_items'] ?? [];

            $namespace = 'custom';
            $key = 'status';
            $metafieldEndpoint = "/admin/api/2024-01/orders/{$orderData['id']}/metafields.json";

            // Fetch the current metafield for the product
            $metafieldsResponse = $shop->api()->rest('GET', $metafieldEndpoint);
            $metafields = $metafieldsResponse['body']['metafields'] ?? [];

            // Find the specific metafield we want to update
            // $metafield = collect($metafields)->firstWhere('namespace', $namespace)->where('key', $key);
            $metafield = null;
            foreach ($metafieldsResponse['body']['metafields'] as $item) {
                if ($item['namespace'] === $namespace && $item['key'] === $key) {
                    $metafield = $item;
                    break; // Stop the loop once the matching metafield is found
                }
            }

            if ($metafield) {
                $values = json_decode($metafield['value'], TRUE);

                dd($values);

                // foreach ($values as $value) {
                //     list($date, $quantity) = explode(':', $value);
                // }
            }

        } catch (\Throwable $th) {
            $errorDetails = [
                'message' => $th->getMessage(),
                'code' => $th->getCode(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString(), // Be cautious with logging stack trace in production environments
            ];
            Log::error('Handler Order Update Webhook Error: ' . json_encode($errorDetails));

            throw $th;
        }
    }
}
