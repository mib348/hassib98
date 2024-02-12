<?php

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


Route::get('/', [ShopifyController::class, 'index'])->middleware(['verify.shopify'])->name('home');
Route::get('/metafields', [ShopifyController::class, 'getMetafields'])->name('metafields');
Route::get('/products', [ShopifyController::class, 'getProducts'])->name('products');
Route::get('/getProductsList', [ShopifyController::class, 'getProductsList'])->name('getProductsList');
Route::resource('shopify', ShopifyController::class);
