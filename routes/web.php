<?php

use App\Http\Controllers\AmountProductsLocationWeekdayController;
use App\Http\Controllers\ArtisanController;
use App\Http\Controllers\LocationProductsTableController;
use App\Http\Controllers\LocationRevenueController;
use App\Http\Controllers\LocationsTextController;
use App\Http\Controllers\OperationDaysController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\PersonalNotepadController;
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
    Route::resource('kitchen', KitchenController::class);
//});


Route::get('/migrate/{type?}', [ArtisanController::class, 'migrate']);
Route::get('/cache', [ArtisanController::class, 'cache']);
Route::get('/storage', [ArtisanController::class, 'storage']);
Route::get('/queue/start', [ArtisanController::class, 'queue_start']);
Route::get('/queue/stop', [ArtisanController::class, 'queue_stop']);
Route::get('/queue/clear', [ArtisanController::class, 'queue_clear']);
Route::get('/queue/retry', [ArtisanController::class, 'queue_retry']);

Route::get('/', [ShopifyController::class, 'index'])->middleware(['verify.shopify'])->name('home');
Route::get('/metafields', [ShopifyController::class, 'getMetafields'])->name('metafields');
Route::get('/products', [ShopifyController::class, 'getProducts'])->name('products');
Route::get('/getProductsList', [ShopifyController::class, 'getProductsList'])->name('getProductsList');
Route::get('/getProductsListJson', [ShopifyController::class, 'getProductsListJson'])->name('getProductsListJson');
Route::get('/getWebhooks', [ShopifyController::class, 'getWebhooks'])->name('getWebhooks');
Route::get('/setWebhooks', [ShopifyController::class, 'setWebhooks'])->name('setWebhooks');
Route::get('/testmail', [ShopifyController::class, 'testmail'])->name('testmail');
Route::get('/getTheme', [ShopifyController::class, 'getTheme'])->name('getTheme');
Route::any('/updateSelectedDate/{date}', [ShopifyController::class, 'updateSelectedDate'])->name('updateSelectedDate');
Route::resource('shopify', ShopifyController::class);

//locations
Route::get('/getLocations/{location?}', [ShopifyController::class, 'getLocations'])->name('getLocations');
Route::get('/getLocationsTextList', [LocationsTextController::class, 'getLocationsTextList'])->name('getLocationsTextList');
Route::resource('locations_text', LocationsTextController::class);

//orders
Route::get('/getOrdersList', [OrdersController::class, 'getOrdersList'])->name('getOrdersList');
Route::resource('orders', OrdersController::class);

//operation days
Route::get('/getOperationDaysList', [OperationDaysController::class, 'getOperationDaysList'])->name('getOperationDaysList');
Route::resource('operationdays', OperationDaysController::class);

//Amount Products Location Weekday Table
Route::get('/getAmountProductsLocationWeekdayList', [AmountProductsLocationWeekdayController::class, 'getAmountProductsLocationWeekdayList'])->name('getAmountProductsLocationWeekdayList');
Route::resource('amountproductslocationweekday', AmountProductsLocationWeekdayController::class);

//Locations Revenue Table
Route::get('/getLocationsRevenueList', [LocationRevenueController::class, 'getLocationsRevenueList'])->name('getLocationsRevenueList');
Route::resource('locations_revenue', LocationRevenueController::class);

//Location Products Table
Route::get('/getLocationsProductsJSON', [LocationProductsTableController::class, 'getLocationsProductsJSON'])->name('getLocationsProductsJSON');
// Route::post('/location_products/updateDay', [LocationProductsTableController::class, 'updateDay'])->name('location_products.updateDay');
Route::resource('location_products', LocationProductsTableController::class);

//get api limit information
Route::get('/apiLimit', [ShopifyController::class, 'apiLimit']);

//personal notepad
Route::resource('personal_notepad', PersonalNotepadController::class);
