<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to list all deployed Shopify functions
 *
 * This helps identify the function IDs needed for creating automatic discounts
 */
class ListShopifyFunctions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:list-functions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all deployed Shopify functions with their IDs and details';

    /**
     * Execute the console command.
     *
     * This command queries all Shopify functions and displays their details
     * including the function ID (GID) needed for creating automatic discounts
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

            $this->info('Fetching Shopify Functions...');
            $this->line('');

            // GraphQL query to fetch all Shopify functions
            // This returns function details including the ID (GID) needed for discount creation
            $query = '
                query {
                    shopifyFunctions(first: 25) {
                        nodes {
                            id
                            apiType
                            title
                            app {
                                title
                            }
                        }
                    }
                }
            ';

            // Execute the GraphQL query
            $response = $api->graph($query);

            // Check for GraphQL-level errors
            if (isset($response['errors']) && !empty($response['errors'])) {
                $errorMessage = json_encode($response['errors']);
                Log::error('GraphQL error listing Shopify functions: ' . $errorMessage);
                $this->error('Failed to list Shopify functions due to GraphQL error:');
                $this->error($errorMessage);
                return Command::FAILURE;
            }

            // Parse the response data - support both possible response shapes from the SDK
            // The Shopify SDK may return data in either ['body']['data'] or ['body']['container']['data']
            $rawData = $response['body']['data']['shopifyFunctions'] ??
                       $response['body']['container']['data']['shopifyFunctions'] ?? null;

            // Convert ResponseAccess object to plain array to avoid string conversion issues
            $data = json_decode(json_encode($rawData), true);

            // Check if we got functions back
            if (!isset($data['nodes']) || empty($data['nodes'])) {
                $this->warn('No Shopify functions found.');
                $this->line('');
                $this->info('Make sure your Shopify app has deployed functions.');
                return Command::SUCCESS;
            }

            $functions = $data['nodes'];

            // Display the functions in a formatted table
            $this->info('Found ' . count($functions) . ' Shopify function(s):');
            $this->line('');

            // Create table data for display
            $tableData = [];
            foreach ($functions as $function) {
                $tableData[] = [
                    'ID' => $function['id'] ?? 'N/A',
                    'Title' => $function['title'] ?? 'N/A',
                    'API Type' => $function['apiType'] ?? 'N/A',
                    'App' => $function['app']['title'] ?? 'N/A',
                ];
            }

            // Display functions in a table format
            $this->table(
                ['ID', 'Title', 'API Type', 'App'],
                $tableData
            );

            $this->line('');

            // Look for loyalty program function specifically
            $loyaltyFunction = collect($functions)->first(function($fn) {
                $title = strtolower($fn['title'] ?? '');
                return str_contains($title, 'loyalty');
            });

            if ($loyaltyFunction) {
                $this->info('✓ Loyalty function found!');
                $this->line('');
                $this->line('Function ID for discount creation:');
                $this->line($loyaltyFunction['id']);
                $this->line('');
                $this->info('Use this ID with: php artisan loyalty:create-discount "' . $loyaltyFunction['id'] . '"');
            } else {
                $this->warn('⚠ No loyalty function found in the list above.');
                $this->line('');
                $this->info('If you have a loyalty function deployed, copy its ID from the table above');
                $this->info('and use it with: php artisan loyalty:create-discount "FUNCTION_ID"');
            }

            return Command::SUCCESS;

        } catch (\Throwable $th) {
            // Catch and log any exceptions during command execution
            Log::error('Exception listing Shopify functions: ' . $th->getMessage(), [
                'exception' => $th,
                'trace' => $th->getTraceAsString(),
            ]);
            $this->error('Failed to list Shopify functions: ' . $th->getMessage());
            return Command::FAILURE;
        }
    }
}
