<?php

use App\Http\Controllers\AmountProductsLocationWeekdayController;
use App\Http\Controllers\ArtisanController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\DriverAdditionalController;
use App\Http\Controllers\HomeDeliveryController;
use App\Http\Controllers\LocationProductsTableController;
use App\Http\Controllers\LocationRevenueController;
use App\Http\Controllers\LocationsTextController;
use App\Http\Controllers\OperationDaysController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\OrderDetailsForRpiController;
use App\Http\Controllers\PersonalNotepadController;
use App\Http\Controllers\StoresController;
use App\Http\Controllers\TechAdminController;
use App\Livewire\Stores\StoresList;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Route::get('/', function () {
//     session_start();
//     $shop = $_SESSION['auth'] = Auth::user();
//     $domain = $_SESSION['domain'] = $shop->getDomain()->toNative();
//     return view('welcome');
// })->middleware(['verify.shopify'])->name('home');

//Route::domain('{subdomain}.sushi.catering')->group(function () {
    // Route::get('/kitchen_admin', [KitchenController::class, 'kitchen_admin'])->name('kitchen_admin');
    Route::get('/kitchen/{uuid}', [KitchenController::class, 'display'])->name('kitchen.display');
    Route::resource('kitchen', KitchenController::class);

    // Route::get('/drivers_admin', [DriverController::class, 'drivers_admin'])->name('drivers_admin');
    Route::get('/drivers/{uuid}', [DriverController::class, 'display'])->name('drivers.display');
    Route::resource('drivers', DriverController::class);
    Route::resource('drivers_additional', DriverAdditionalController::class);

    // RPI Order Details — public UUID-based routes (same pattern as kitchen/drivers)
    Route::get('/order_details_for_rpi/{uuid}', [OrderDetailsForRpiController::class, 'display'])->name('order_details_for_rpi.display');
    Route::resource('order_details_for_rpi', OrderDetailsForRpiController::class);

    Route::post('/delivery/fulfilled/{order_id}', [DeliveryController::class, 'MarkAsDelivered'])->name('delivery.MarkAsDelivered');
    Route::resource('delivery', DeliveryController::class);

    //});


Route::get('/migrate/{type?}', [ArtisanController::class, 'migrate']);
Route::get('/cache', [ArtisanController::class, 'cache']);
Route::get('/storage', [ArtisanController::class, 'storage']);
Route::get('/queue/start', [ArtisanController::class, 'queue_start']);
Route::get('/queue/stop', [ArtisanController::class, 'queue_stop']);
Route::get('/queue/clear', [ArtisanController::class, 'queue_clear']);
Route::get('/queue/retry', [ArtisanController::class, 'queue_retry']);

// Public routes — called by storefront JS (no Shopify session token available),
// these methods have their own Auth::user() fallback to User::find(env('db_shop_id', 1))
Route::get('/getLocations/{location?}', [ShopifyController::class, 'getLocations'])->name('getLocations');
Route::any('/updateSelectedDate/{date}', [ShopifyController::class, 'updateSelectedDate'])->name('updateSelectedDate');
Route::any('/deliverySelectedDate/{date}', [ShopifyController::class, 'deliverySelectedDate'])->name('deliverySelectedDate');
Route::get('/getImmediateInventoryByLocation/{location?}', [ShopifyController::class, 'getImmediateInventoryByLocation'])->name('getImmediateInventoryByLocation');
Route::get('/getImmediateInventoryByLocationForYesterday/{location?}', [ShopifyController::class, 'getImmediateInventoryByLocationForYesterday'])->name('getImmediateInventoryByLocationForYesterday');

// Tech Admin is intentionally available outside the embedded Shopify app so
// operators can open the monitoring page directly. These routes therefore sit
// outside verify.shopify while the controller still validates every request.
Route::get('/tech/admin', [TechAdminController::class, 'index'])->name('tech_admin.index');
Route::get('/tech/admin/statuses', [TechAdminController::class, 'statuses'])->name('tech_admin.statuses');
Route::post('/tech/admin/check-pi', [TechAdminController::class, 'checkPi'])->name('tech_admin.check_pi');
Route::post('/tech/admin/command', [TechAdminController::class, 'command'])->name('tech_admin.command');

Route::middleware(['verify.shopify'])->group(function () {
    Route::get('/', [ShopifyController::class, 'index'])->name('home');
    Route::get('/metafields', [ShopifyController::class, 'getMetafields'])->name('metafields');
    Route::get('/products', [ShopifyController::class, 'getProducts'])->name('products');
    Route::get('/getProductsList', [ShopifyController::class, 'getProductsList'])->name('getProductsList');
    Route::get('/getProductsListJson', [ShopifyController::class, 'getProductsListJson'])->name('getProductsListJson');
    Route::get('/getWebhooks', [ShopifyController::class, 'getWebhooks'])->name('getWebhooks');
    Route::get('/setWebhooks', [ShopifyController::class, 'setWebhooks'])->name('setWebhooks');
    Route::get('/testmail', [ShopifyController::class, 'testmail'])->name('testmail');
    Route::get('/getTheme', [ShopifyController::class, 'getTheme'])->name('getTheme');
    Route::resource('shopify', ShopifyController::class);

    // locations
    Route::get('/getLocationsTextList', [LocationsTextController::class, 'getLocationsTextList'])->name('getLocationsTextList');
    Route::post('/locations_text/addLocation', [LocationsTextController::class, 'addLocation'])->name('locations_text.addLocation');
    // Excel export route — must be before the resource route so /export/excel isn't caught by {locations_text}
    Route::get('/locations_text/export/excel', [LocationsTextController::class, 'exportExcel'])->name('locations_text.exportExcel');
    Route::resource('locations_text', LocationsTextController::class);

    // orders
    Route::get('/getOrdersList', [OrdersController::class, 'getOrdersList'])->name('getOrdersList');
    Route::resource('orders', OrdersController::class);

    // operation days
    Route::get('/getOperationDaysList', [OperationDaysController::class, 'getOperationDaysList'])->name('getOperationDaysList');
    Route::resource('operationdays', OperationDaysController::class);

    // Amount Products Location Weekday Table
    Route::get('/getAmountProductsLocationWeekdayList', [AmountProductsLocationWeekdayController::class, 'getAmountProductsLocationWeekdayList'])->name('getAmountProductsLocationWeekdayList');
    Route::resource('amountproductslocationweekday', AmountProductsLocationWeekdayController::class);

    // Locations Revenue Table
    Route::get('/getLocationsRevenueList', [LocationRevenueController::class, 'getLocationsRevenueList'])->name('getLocationsRevenueList');
    Route::resource('locations_revenue', LocationRevenueController::class);

    // Location Products Table
    Route::get('/getLocationsProductsJSON', [LocationProductsTableController::class, 'getLocationsProductsJSON'])->name('getLocationsProductsJSON');
    Route::post('/ImportDefaultMenu', [LocationProductsTableController::class, 'ImportDefaultMenu'])->name('ImportDefaultMenu');
    Route::get('/location_products/quick-set', [LocationProductsTableController::class, 'quickSet'])->name('location_products.quick_set');
    Route::post('/location_products/quick-set/reset-today', [LocationProductsTableController::class, 'resetTodayImmediateInventory'])->name('location_products.quick_set.reset_today');
    // Route::post('/location_products/updateDay', [LocationProductsTableController::class, 'updateDay'])->name('location_products.updateDay');
    Route::resource('location_products', LocationProductsTableController::class);

    // home delivery overview
    Route::resource('home_delivery', HomeDeliveryController::class);

    // get api limit information
    Route::get('/apiLimit', [ShopifyController::class, 'apiLimit']);

    // personal notepad
    Route::resource('personal_notepad', PersonalNotepadController::class);

    // store
    Route::get('/getStoresList', [StoresList::class, 'getStoresList'])->name('getStoresList');
    Route::resource('stores', StoresController::class);

});
