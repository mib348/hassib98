<?php

namespace Tests\Feature;

use App\Http\Controllers\ShopifyController;
use App\Models\User;
use Mockery;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class GetImmediateInventoryByLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_getImmediateInventoryByLocation()
    {
        // Define the test location
        $location = 'Test Location';

        // Mock the DB facade to simulate the query
        $immediateProductsMock = collect([
            (object) ['product_id' => 1, 'location' => $location],
            (object) ['product_id' => 2, 'location' => $location],
        ]);

        // Mock the database query using DB facade
        DB::shouldReceive('table')->with('location_products_tables')
            ->andReturnSelf(); // Mock table
        DB::shouldReceive('join')->with('products', 'products.product_id', '=', 'location_products_tables.product_id')
            ->andReturnSelf(); // Mock join
        DB::shouldReceive('where')->with('products.status', 'active')
            ->andReturnSelf(); // Mock where condition
        DB::shouldReceive('where')->with('location_products_tables.location', $location)
            ->andReturnSelf(); // Mock location condition
        DB::shouldReceive('where')->with('inventory_type', 'immediate')
            ->andReturnSelf(); // Mock inventory type condition
        DB::shouldReceive('where')->with('day', date('l'))
            ->andReturnSelf(); // Mock day condition
        DB::shouldReceive('get')
            ->andReturn($immediateProductsMock); // Mock the result of get

        // Mock User and Shopify API response
        $shopMock = Mockery::mock(User::class)->makePartial();
        $shopMock->shouldReceive('api')->andReturnSelf();
        $shopMock->shouldReceive('rest')->andReturn([
            'body' => [
                'metafields' => [
                    [
                        'key' => 'json',
                        'value' => json_encode([
                            "Test Location:" . date('d-m-Y') . ":5", // Mock 5 as quantity
                            "Other Location:" . date('d-m-Y') . ":2"
                        ])
                    ]
                ]
            ]
        ]);

        // Mock finding the user
        $this->mock(User::class, function ($mock) use ($shopMock) {
            $mock->shouldReceive('find')->with(env('db_shop_id', 1))->andReturn($shopMock);
        });

        // Call the method and assert the correct quantity is returned
        $quantity = ShopifyController::getImmediateInventoryByLocation($location);

        // Assert that the total quantity is 5 (from the mocked data)
        $this->assertEquals(5, $quantity);
    }
}
