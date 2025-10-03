<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Artisan command to create an automatic loyalty discount via Shopify GraphQL API
 *
 * This command uses the discountAutomaticAppCreate mutation to programmatically
 * create automatic discounts without requiring merchant UI interaction.
 *
 * The discount function (loyalty-program) must already be deployed to Shopify
 * and the app must have write_discounts scope enabled in shopify.app.toml
 */
class CreateLoyaltyDiscount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loyalty:create-discount {functionId? : Optional Shopify Function ID (GID). If not provided, will auto-detect.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create automatic loyalty discount (Buy 4 Get 1 Free) via Shopify GraphQL API';

    /**
     * Execute the console command.
     *
     * This command creates an automatic discount that:
     * - Auto-detects the loyalty function ID or uses the provided GID
     * - Starts immediately upon creation
     * - Combines with product discounts only (not order or shipping discounts)
     * - Reads customer metafields to determine eligibility and free items
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle()
    {
        try {
            // Get the authenticated shop user - first try Auth, fallback to default shop ID from .env
            $shop = Auth::user();
            if (!isset($shop) || !$shop) {
                $shop = User::find(env('db_shop_id', 1));
            }

            // Get the Shopify API instance for making GraphQL requests
            $api = $shop->api();

            $this->info('Creating automatic loyalty discount via GraphQL...');
            $this->line('');

            // Get the function ID - either from argument or by querying Shopify
            $functionId = $this->argument('functionId');

            if (!$functionId) {
                $this->info('No function ID provided. Searching for loyalty function...');
                $functionId = $this->findLoyaltyFunctionId($api);

                if (!$functionId) {
                    $this->error('Could not find loyalty function automatically.');
                    $this->line('');
                    $this->info('Please run: php artisan shopify:list-functions');
                    $this->info('Then use: php artisan loyalty:create-discount "FUNCTION_ID"');
                    return Command::FAILURE;
                }

                $this->info('✓ Found loyalty function: ' . $functionId);
                $this->line('');
            }

            // Prepare the GraphQL mutation for discountAutomaticAppCreate
            // This mutation creates an automatic discount programmatically without requiring merchant UI interaction
            // The discount function must already be deployed and available in Shopify
            // We use the function's GID (e.g., gid://shopify/ShopifyFunction/12345) not the handle
            // Note: discountClasses is an array that specifies which types of items can receive discounts
            // For loyalty "Buy 4 Get 1 Free", we use PRODUCT to discount individual items
            $mutation = '
                mutation {
                    discountAutomaticAppCreate(
                        automaticAppDiscount: {
                            title: "Loyalty - Buy 4 Get 1 Free"
                            functionId: "' . $functionId . '"
                            startsAt: "' . Carbon::now()->toIso8601String() . '"
                            combinesWith: {
                                productDiscounts: true
                                orderDiscounts: false
                                shippingDiscounts: false
                            }
                            discountClasses: [PRODUCT]
                        }
                    ) {
                        automaticAppDiscount {
                            discountId
                            title
                            status
                            startsAt
                            combinesWith {
                                productDiscounts
                                orderDiscounts
                                shippingDiscounts
                            }
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
            ';

            // Execute the GraphQL mutation
            $response = $api->graph($mutation);

            // Check for GraphQL-level errors (not user errors)
            if (isset($response['errors']) && !empty($response['errors'])) {
                $errorMessage = json_encode($response['errors']);
                Log::error('GraphQL error creating loyalty discount: ' . $errorMessage);
                $this->error('Failed to create loyalty discount due to GraphQL error:');
                $this->error($errorMessage);
                return Command::FAILURE;
            }

            // Parse the response data - support both possible response shapes from the SDK
            // The Shopify SDK may return data in either ['body']['data'] or ['body']['container']['data']
            // Convert ResponseAccess objects to plain array for easier manipulation
            $rawData = $response['body']['data']['discountAutomaticAppCreate'] ??
                       $response['body']['container']['data']['discountAutomaticAppCreate'] ?? null;

            // Convert ResponseAccess object to plain array to avoid string conversion issues
            $data = json_decode(json_encode($rawData), true);

            // Debug: Log the raw response to help diagnose issues
            Log::info('Shopify GraphQL Response:', ['data' => $data]);
            $this->line('Raw response logged to storage/logs/laravel.log');
            $this->line('');

            // Check for user errors returned by the mutation
            // User errors are application-level errors like missing scopes or invalid input
            if (isset($data['userErrors']) && !empty($data['userErrors'])) {
                $userErrors = collect($data['userErrors'])->map(function($error) {
                    // Safely extract field - handle if it's an array or string
                    $field = 'general';
                    if (isset($error['field'])) {
                        $field = is_array($error['field']) ? implode('.', $error['field']) : (string) $error['field'];
                    }

                    // Safely extract message - handle if it's an array or string
                    $message = 'Unknown error';
                    if (isset($error['message'])) {
                        $message = is_array($error['message']) ? json_encode($error['message']) : (string) $error['message'];
                    }

                    return $field . ': ' . $message;
                })->implode("\n");

                Log::error('User errors creating loyalty discount: ' . $userErrors);
                $this->error('Failed to create loyalty discount:');
                $this->error($userErrors);
                $this->line('');

                // Check if the error is related to missing scopes
                $errorString = strtolower(json_encode($data['userErrors']));
                if (str_contains($errorString, 'scope') || str_contains($errorString, 'permission')) {
                    $this->warn('⚠ MISSING SCOPE DETECTED:');
                    $this->warn('You need to add write_discounts scope to your shopify.app.toml configuration.');
                    $this->line('');
                    $this->info('Steps to fix:');
                    $this->info('1. Add "write_discounts" to the scopes array in shopify.app.toml');
                    $this->info('2. Redeploy your app to Shopify');
                    $this->info('3. Re-run this command');
                }

                return Command::FAILURE;
            }

            // Success! Extract and log the discount details
            if (isset($data['automaticAppDiscount'])) {
                $discount = $data['automaticAppDiscount'];
                $discountId = $discount['discountId'] ?? null;

                // Log the success with discount details for debugging and audit trail
                Log::info('Loyalty discount created successfully', [
                    'discountId' => $discountId,
                    'title' => $discount['title'] ?? null,
                    'status' => $discount['status'] ?? null,
                    'startsAt' => $discount['startsAt'] ?? null,
                    'combinesWith' => $discount['combinesWith'] ?? null,
                ]);

                // Display success message to console
                $this->info('✓ Loyalty discount created successfully!');
                $this->line('');
                $this->line('Discount Details:');
                $this->line('================');
                $this->line('Discount ID: ' . ($discountId ?? 'N/A'));
                $this->line('Title: ' . ($discount['title'] ?? 'N/A'));
                $this->line('Status: ' . ($discount['status'] ?? 'N/A'));
                $this->line('Starts At: ' . ($discount['startsAt'] ?? 'N/A'));
                $this->line('');
                $this->line('Combines With:');
                $this->line('  - Product Discounts: ' . (($discount['combinesWith']['productDiscounts'] ?? false) ? 'Yes' : 'No'));
                $this->line('  - Order Discounts: ' . (($discount['combinesWith']['orderDiscounts'] ?? false) ? 'Yes' : 'No'));
                $this->line('  - Shipping Discounts: ' . (($discount['combinesWith']['shippingDiscounts'] ?? false) ? 'Yes' : 'No'));
                $this->line('');
                $this->info('The discount is now active in Shopify Admin → Discounts');
                $this->info('The deployed function will automatically execute on every cart operation');
                $this->info('and apply discounts based on customer loyalty.status and loyalty.free_items metafields.');

                return Command::SUCCESS;
            }

            // If we reach here, something unexpected happened with the response structure
            Log::error('Unexpected response creating loyalty discount: ' . json_encode($response));
            $this->error('Unexpected response from Shopify API');
            $this->error('Check logs for more details');
            return Command::FAILURE;

        } catch (\Throwable $th) {
            // Catch and log any exceptions during command execution
            Log::error('Exception creating loyalty discount: ' . $th->getMessage(), [
                'exception' => $th,
                'trace' => $th->getTraceAsString(),
            ]);
            $this->error('Failed to create loyalty discount: ' . $th->getMessage());
            $this->line('');
            $this->error('Stack trace:');
            $this->error($th->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Find the loyalty function ID by querying Shopify functions
     *
     * This method queries all deployed Shopify functions and searches for one
     * with "loyalty" in the title
     *
     * @param mixed $api The Shopify API instance
     * @return string|null The function GID if found, null otherwise
     */
    protected function findLoyaltyFunctionId($api)
    {
        try {
            // GraphQL query to fetch all Shopify functions
            $query = '
                query {
                    shopifyFunctions(first: 25) {
                        nodes {
                            id
                            title
                            apiType
                        }
                    }
                }
            ';

            // Execute the GraphQL query
            $response = $api->graph($query);

            // Parse the response data - support both possible response shapes from the SDK
            $rawData = $response['body']['data']['shopifyFunctions'] ??
                       $response['body']['container']['data']['shopifyFunctions'] ?? null;

            // Convert ResponseAccess object to plain array
            $data = json_decode(json_encode($rawData), true);

            // Check if we got functions back
            if (!isset($data['nodes']) || empty($data['nodes'])) {
                return null;
            }

            // Search for loyalty function - look for "loyalty" in the title (case-insensitive)
            foreach ($data['nodes'] as $function) {
                $title = strtolower($function['title'] ?? '');
                if (str_contains($title, 'loyalty')) {
                    return $function['id'];
                }
            }

            return null;

        } catch (\Throwable $th) {
            Log::error('Exception finding loyalty function: ' . $th->getMessage());
            return null;
        }
    }
}
