<?php

namespace Tests\Feature;

use Mockery;
use Tests\TestCase;
use App\Models\User;
use App\Models\LocationProductsTable;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GetImmediateInventoryByLocationTest extends TestCase
{
    use RefreshDatabase; // This now applies to the in-memory SQLite DB

    public function test_getImmediateInventoryByLocation()
    {
        // Define the test location
        $location = 'Test Location';

        // Mock LocationProductsTable join query
        $immediateProductsMock = collect([
            (object) ['product_id' => 1, 'location' => $location],
            (object) ['product_id' => 2, 'location' => $location],
        ]);

        // Mock the join query and response
        LocationProductsTable::shouldReceive('join')
            ->with('products', 'products.product_id', '=', 'location_products_tables.product_id')
            ->andReturnSelf();
        LocationProductsTable::shouldReceive('where')->with('products.status', 'active')->andReturnSelf();
        LocationProductsTable::shouldReceive('where')->with('location_products_tables.location', $location)->andReturnSelf();
        LocationProductsTable::shouldReceive('where')->with('inventory_type', 'immediate')->andReturnSelf();
        LocationProductsTable::shouldReceive('where')->with('day', date('l'))->andReturnSelf();
        LocationProductsTable::shouldReceive('get')->andReturn($immediateProductsMock);

        // Mock User and its API call
        $shopMock = Mockery::mock(User::class)->makePartial();
        $shopMock->shouldReceive('api')->andReturnSelf();
        $shopMock->shouldReceive('rest')->andReturn([
            'body' => [
                'metafields' => [
                    [
                        'key' => 'json',
                        'value' => json_encode([
                            "Test Location:" . date('d-m-Y') . ":5",
                            "Other Location:" . date('d-m-Y') . ":2"
                        ])
                    ]
                ]
            ]
        ]);

        // Mock finding the user
        User::shouldReceive('find')->with(env('db_shop_id', 1))->andReturn($shopMock);

        // Call the route
        $response = $this->get(route('getImmediateInventoryByLocation', ['location' => $location]));

        // Assert status is 200
        $response->assertStatus(200);

        // Assert the response contains the correct quantity
        $response->assertSee('5'); // This asserts that the quantity 5 is returned as expected
    }
}
