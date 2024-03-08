<?php

use App\Http\Controllers\ShopifyController;
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
// Route::get('/getProductsList', function(){
//     return redirect('https://council-moms-commissioners-tip.trycloudflare.com/authenticate');
// })->name('api_getProductsList');
