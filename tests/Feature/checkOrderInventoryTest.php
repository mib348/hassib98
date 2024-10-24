<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Locations; // Ensure the correct model namespace

class checkOrderInventoryTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test the checkOrderInventory API endpoint
     */
    public function test_check_order_inventory_sameday_preorder_time_expired()
    {
        // Arrange: Create a mock location record for the test
        $location = Locations::factory()->create([
            'name' => 'Asklepios Altona',                // Location as in the order
            'immediate_inventory' => 'Y',                // Ensure 'immediate_inventory' is 'Y'
            'sameday_preorder_end_time' => now()->subMinutes(30), // Set time 30 minutes in the past
        ]);

        // Example order items being posted to the API
        $items = json_encode([
            [
                'id' => 42210627452998,
                'properties' => [
                    'max_quantity' => '8',
                    'location' => $location->name,      // Matching location in DB
                    'date' => now()->format('d-m-Y'),   // Today's date
                    'day' => 'Friday',
                    'no_station' => 'N',
                    'additional_inventory' => 'N',
                    'immediate_inventory' => 'N',       // immediate_inventory is 'N' in order
                ],
                'quantity' => 1,
                'variant_id' => 42210627452998,
                'price' => 95,
            ]
        ]);

        // Act: Send the POST request to the API endpoint
        $response = $this->postJson(route('checkOrderInventory'), [
            'items' => $items,
        ]);

        // Assert: Check if the API responds with 'sameday_preorder_time_expired' = 1 (true)
        $response->assertStatus(200)
                 ->assertJson([
                     'sameday_preorder_time_expired' => 1
                 ]);
    }

    /**
     * Test the checkOrderInventory API when immediate_inventory is 'N' and no expiry
     */
    public function test_check_order_inventory_sameday_preorder_time_not_expired()
    {
        // Arrange: Create a mock location record for the test
        $location = Locations::factory()->create([
            'name' => 'Asklepios Altona',                // Location as in the order
            'immediate_inventory' => 'Y',                // Ensure 'immediate_inventory' is 'Y'
            'sameday_preorder_end_time' => now()->addMinutes(30), // Set time 30 minutes in the future
        ]);

        // Example order items being posted to the API
        $items = json_encode([
            [
                'id' => 42210627452998,
                'properties' => [
                    'max_quantity' => '8',
                    'location' => $location->name,      // Matching location in DB
                    'date' => now()->format('d-m-Y'),   // Today's date
                    'day' => 'Friday',
                    'no_station' => 'N',
                    'additional_inventory' => 'N',
                    'immediate_inventory' => 'N',       // immediate_inventory is 'N' in order
                ],
                'quantity' => 1,
                'variant_id' => 42210627452998,
                'price' => 95,
            ]
        ]);

        // Act: Send the POST request to the API endpoint
        $response = $this->postJson(route('checkOrderInventory'), [
            'items' => $items,
        ]);

        // Assert: Check if the API responds with 'sameday_preorder_time_expired' = 0 (false)
        $response->assertStatus(200)
                 ->assertJson([
                     'sameday_preorder_time_expired' => 0
                 ]);
    }
}
