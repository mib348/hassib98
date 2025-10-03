<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\TokenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
// Route::get('/getProductsList', function (Request $request) {
//     $products = URL::tokenRoute('api_getProductsList', ['host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvZGM5ZWY5']);
//     return redirect($products);
// });

// Route::get('/getProducts', [ShopifyController::class, 'getProducts'])->name('api_getProducts');
Route::get('/getProductsJson', [ShopifyController::class, 'getProductsJson'])->name('getProductsJson');
Route::get('/getProductsQty', [ShopifyController::class, 'getProductsQty'])->name('getProductsQty');
Route::any('/getOrderCreationWebhook', [ShopifyController::class, 'getOrderCreationWebhook'])->name('getOrderCreationWebhook');
Route::any('/getOrderUpdateWebhook', [ShopifyController::class, 'getOrderUpdateWebhook'])->name('getOrderUpdateWebhook');
Route::any('/getOrderPaymentWebhook', [ShopifyController::class, 'getOrderPaymentWebhook'])->name('getOrderPaymentWebhook');
Route::any('/getordernumber/{order_id}', [ShopifyController::class, 'getordernumber'])->name('getordernumber');
Route::any('/checkCartProductsQty', [ShopifyController::class, 'checkCartProductsQty'])->name('checkCartProductsQty');
Route::any('/checkOrderInventory', [ShopifyController::class, 'checkOrderInventory'])->name('checkOrderInventory');
Route::any('/getLocationsList', [ShopifyController::class, 'getLocationsList'])->name('getLocationsList');
// Route::get('/getProductsList', function(){
//     return redirect('https://council-moms-commissioners-tip.trycloudflare.com/authenticate');
// })->name('api_getProductsList');
Route::middleware('auth:sanctum')->resource('/order/fulfillment', FulfillmentController::class)->parameters([
    'fulfillment' => 'order',
]);
Route::middleware('auth:sanctum')->post('/generate-token', [TokenController::class, 'generateToken']);
Route::post('/contact-handler', [ContactController::class, 'store'])
    ->name('contact.store');




/* LOYALTY PROGRAM */
// Loyalty Program Routes
Route::prefix('loyalty')->name('loyalty.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public Endpoints (No Authentication Required)
    |--------------------------------------------------------------------------
    |
    | These endpoints are used by the Shopify checkout UI extensions and
    | should be accessible without authentication for seamless integration.
    |
    */

    // Get loyalty status for checkout extension
    // Used by: Checkout UI extension to display customer loyalty information
    Route::get('/status/{email}', [LoyaltyController::class, 'getStatus'])
        ->name('status')
        ->where('email', '.*'); // Allow dots in email parameter

    /*
    |--------------------------------------------------------------------------
    | Protected Administrative Endpoints
    |--------------------------------------------------------------------------
    |
    | These endpoints require authentication and are used for administrative
    | management of the loyalty program. Adjust middleware as needed.
    |
    */

    Route::middleware('auth:sanctum')->group(function () {

        // Loyalty member management
        Route::get('/members', [LoyaltyController::class, 'index'])
            ->name('members.index');

        Route::get('/member/{id}', [LoyaltyController::class, 'show'])
            ->name('members.show')
            ->where('id', '[0-9]+');

        // Loyalty member registration (manual enrollment)
        Route::post('/register', [LoyaltyController::class, 'register'])
            ->name('register');

        // Manual point adjustments (admin only)
        Route::post('/update-points', [LoyaltyController::class, 'updatePoints'])
            ->name('update-points');

    });

});

/*
|--------------------------------------------------------------------------
| Additional Route Suggestions
|--------------------------------------------------------------------------
|
| Uncomment and customize these routes as needed for your implementation:
|
*/

// Optional: Webhook endpoint for external loyalty system integration
// Route::post('/loyalty/webhook/external', [LoyaltyController::class, 'handleExternalWebhook'])
//     ->name('loyalty.webhook.external');

// Optional: Bulk operations endpoint for administrative tasks
// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/loyalty/bulk-update', [LoyaltyController::class, 'bulkUpdate'])
//         ->name('loyalty.bulk-update');
//
//     Route::get('/loyalty/export', [LoyaltyController::class, 'export'])
//         ->name('loyalty.export');
// });

// Optional: Customer self-service endpoints (if building customer portal)
// Route::middleware('auth:customer')->group(function () {
//     Route::get('/my-loyalty', [LoyaltyController::class, 'myLoyalty'])
//         ->name('loyalty.my-loyalty');
//
//     Route::get('/my-loyalty/history', [LoyaltyController::class, 'myHistory'])
//         ->name('loyalty.my-history');
// });
