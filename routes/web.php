<?php

use App\Http\Controllers\AmountProductsLocationWeekdayController;
use App\Http\Controllers\ArtisanController;
use App\Http\Controllers\LocationRevenueController;
use App\Http\Controllers\OperationDaysController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ShopifyController;
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
Route::get('/getWebhooks', [ShopifyController::class, 'getWebhooks'])->name('getWebhooks');
Route::get('/setWebhooks', [ShopifyController::class, 'setWebhooks'])->name('setWebhooks');
Route::get('/testmail', [ShopifyController::class, 'testmail'])->name('testmail');
Route::get('/getTheme', [ShopifyController::class, 'getTheme'])->name('getTheme');
Route::any('/updateSelectedDate/{date}', [ShopifyController::class, 'updateSelectedDate'])->name('updateSelectedDate');
Route::resource('shopify', ShopifyController::class);

Route::get('/getLocations', [ShopifyController::class, 'getLocations'])->name('getLocations');

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

