<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyMember;
use App\Models\LoyaltyTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Storage\Queries\Shop as IShopQuery;

/**
 * LoyaltyController
 *
 * Handles all loyalty program API endpoints including:
 * - Customer loyalty status retrieval for checkout extensions
 * - Administrative loyalty member management
 * - Point adjustments and manual interventions
 * - Integration with Shopify customer metafields
 */
class LoyaltyController extends Controller
{
    protected $shopQuery;

    /**
     * Constructor - Initialize shop query service for Shopify API access
     */
    public function __construct(IShopQuery $shopQuery)
    {
        $this->shopQuery = $shopQuery;
    }

    /**
     * Get loyalty status for a customer by email.
     * This endpoint is used by the checkout UI extension to display loyalty information.
     *
     * @param string $email Customer email address
     * @return JsonResponse Loyalty status and eligibility information
     */
    public function getStatus(string $email): JsonResponse
    {
        try {
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'error' => 'Invalid email format'
                ], 400);
            }

            // Find loyalty member by email
            $member = LoyaltyMember::where('email', $email)->first();

            if (!$member) {
                return response()->json([
                    'is_member' => false,
                    'message' => 'Customer is not enrolled in the loyalty program'
                ]);
            }

            // Calculate free items eligibility
            $freeItemsAvailable = $member->calculateFreeItemsAvailable();
            $itemsUntilNextFree = $member->itemsUntilNextFreeItem();

            return response()->json([
                'is_member' => true,
                'status' => $member->status,
                'points' => $member->points_balance,
                'lifetime_points' => $member->lifetime_points,
                'tier' => $member->tier,
                'items_purchased' => $member->items_purchased_count,
                'free_items_available' => $freeItemsAvailable,
                'next_free_item_in' => $itemsUntilNextFree === 4 ? 0 : $itemsUntilNextFree,
                'member_since' => $member->created_at->format('Y-m-d')
            ]);

        } catch (\Exception $e) {
            Log::error('Loyalty status retrieval error: ' . $e->getMessage(), [
                'email' => $email,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve loyalty status'
            ], 500);
        }
    }

    /**
     * Register a new customer in the loyalty program.
     * Used for manual enrollment or when customer opts in.
     *
     * @param Request $request Registration data
     * @return JsonResponse Registration result
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:loyalty_members,email',
                'shopify_customer_id' => 'required|string|unique:loyalty_members,shopify_customer_id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 422);
            }

            // Create new loyalty member
            $member = LoyaltyMember::create([
                'shopify_customer_id' => $request->shopify_customer_id,
                'email' => $request->email,
                'status' => 'active'
            ]);

            // Update Shopify customer metafields to mark as loyalty member
            $this->updateCustomerMetafields($member);

            Log::info('New loyalty member registered', [
                'member_id' => $member->id,
                'email' => $member->email
            ]);

            return response()->json([
                'success' => true,
                'member' => [
                    'id' => $member->id,
                    'email' => $member->email,
                    'tier' => $member->tier,
                    'status' => $member->status
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Loyalty member registration error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to register loyalty member'
            ], 500);
        }
    }

    /**
     * Get all loyalty members with pagination.
     * Administrative endpoint for viewing program participants.
     *
     * @param Request $request Query parameters for filtering and pagination
     * @return JsonResponse Paginated list of loyalty members
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = LoyaltyMember::with(['transactions' => function($query) {
                $query->latest()->take(5); // Include last 5 transactions for each member
            }]);

            // Apply filters if provided
            if ($request->has('tier')) {
                $query->where('tier', $request->tier);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $searchTerm = $request->search;
                $query->where(function($q) use ($searchTerm) {
                    $q->where('email', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('shopify_customer_id', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Order by most recent activity
            $query->latest('updated_at');

            // Paginate results
            $perPage = min($request->get('per_page', 20), 100); // Max 100 per page
            $members = $query->paginate($perPage);

            return response()->json($members);

        } catch (\Exception $e) {
            Log::error('Loyalty members index error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to retrieve loyalty members'
            ], 500);
        }
    }

    /**
     * Get detailed information about a specific loyalty member.
     * Administrative endpoint for customer service and detailed analysis.
     *
     * @param int $id Loyalty member ID
     * @return JsonResponse Detailed member information
     */
    public function show(int $id): JsonResponse
    {
        try {
            $member = LoyaltyMember::with(['transactions' => function($query) {
                $query->latest();
            }])->find($id);

            if (!$member) {
                return response()->json([
                    'error' => 'Loyalty member not found'
                ], 404);
            }

            return response()->json([
                'member' => $member,
                'statistics' => [
                    'total_transactions' => $member->transactions->count(),
                    'points_earned' => $member->transactions->where('type', 'earned')->sum('points'),
                    'points_redeemed' => abs($member->transactions->where('type', 'redeemed')->sum('points')),
                    'free_items_available' => $member->calculateFreeItemsAvailable(),
                    'items_until_next_free' => $member->itemsUntilNextFreeItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Loyalty member show error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to retrieve loyalty member details'
            ], 500);
        }
    }

    /**
     * Manually update loyalty points for a member.
     * Administrative endpoint for point adjustments and bonuses.
     *
     * @param Request $request Adjustment details
     * @return JsonResponse Update result
     */
    public function updatePoints(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'member_id' => 'required|exists:loyalty_members,id',
                'points' => 'required|integer',
                'description' => 'required|string|max:255',
                'type' => 'required|in:earned,redeemed,adjusted'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 422);
            }

            $member = LoyaltyMember::find($request->member_id);

            // Create transaction record
            LoyaltyTransaction::create([
                'loyalty_member_id' => $member->id,
                'type' => $request->type,
                'points' => $request->points,
                'description' => $request->description,
                'metadata' => [
                    'admin_adjustment' => true,
                    'adjusted_by' => auth()->user()->name ?? 'System',
                    'adjusted_at' => now()->toISOString()
                ]
            ]);

            // Update member balance
            $member->points_balance += $request->points;
            if ($request->type === 'earned' && $request->points > 0) {
                $member->lifetime_points += $request->points;
                $member->updateTier();
            }
            $member->save();

            // Update Shopify metafields
            $this->updateCustomerMetafields($member);

            Log::info('Manual loyalty points update', [
                'member_id' => $member->id,
                'points_change' => $request->points,
                'type' => $request->type,
                'new_balance' => $member->points_balance
            ]);

            return response()->json([
                'success' => true,
                'member' => [
                    'id' => $member->id,
                    'email' => $member->email,
                    'points_balance' => $member->points_balance,
                    'lifetime_points' => $member->lifetime_points,
                    'tier' => $member->tier
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Loyalty points update error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to update loyalty points'
            ], 500);
        }
    }

    /**
     * Update customer metafields in Shopify with loyalty information.
     * This ensures checkout extensions can access current loyalty status.
     *
     * @param LoyaltyMember $member The loyalty member to update
     */
    protected function updateCustomerMetafields(LoyaltyMember $member): void
    {
        try {
            // Get the shop - for now using the first active shop
            // You may need to adjust this based on your multi-shop setup
            $shop = $this->shopQuery->getByDomain(ShopDomain::fromNative(config('app.shopify_domain')));

            if (!$shop) {
                Log::warning('No shop found for metafield update');
                return;
            }

            // Calculate current free items
            $freeItems = $member->calculateFreeItemsAvailable();

            // Prepare GraphQL mutation for updating customer metafields
            $mutation = '
                mutation customerUpdate($input: CustomerInput!) {
                    customerUpdate(input: $input) {
                        customer {
                            id
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
            ';

            $variables = [
                'input' => [
                    'id' => "gid://shopify/Customer/{$member->shopify_customer_id}",
                    'metafields' => [
                        [
                            'namespace' => 'loyalty',
                            'key' => 'status',
                            'value' => $member->status,
                            'type' => 'single_line_text_field'
                        ],
                        [
                            'namespace' => 'loyalty',
                            'key' => 'points',
                            'value' => (string)$member->points_balance,
                            'type' => 'number_integer'
                        ],
                        [
                            'namespace' => 'loyalty',
                            'key' => 'free_items',
                            'value' => (string)$freeItems,
                            'type' => 'number_integer'
                        ],
                        [
                            'namespace' => 'loyalty',
                            'key' => 'tier',
                            'value' => $member->tier,
                            'type' => 'single_line_text_field'
                        ]
                    ]
                ]
            ];

            // Execute GraphQL mutation
            $response = $shop->api()->graph($mutation, $variables);

            // Check for errors in the response
            if (!empty($response['data']['customerUpdate']['userErrors'])) {
                Log::error('Customer metafield update errors:', [
                    'member_id' => $member->id,
                    'errors' => $response['data']['customerUpdate']['userErrors']
                ]);
            } else {
                Log::info('Customer metafields updated successfully', [
                    'member_id' => $member->id,
                    'customer_id' => $member->shopify_customer_id
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to update customer metafields: ' . $e->getMessage(), [
                'member_id' => $member->id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}