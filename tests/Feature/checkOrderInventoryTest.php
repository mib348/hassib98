<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class checkOrderInventoryTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_checkOrderInventory_api(): void
    {
        // Example order items being posted to the API
        $items = '[{"id":42210627452998,"properties":{"max_quantity":"8","location":"Asklepios Altona","date":"25-10-2024","day":"Friday","no_station":"N","additional_inventory":"N","immediate_inventory":"N"},"quantity":1,"variant_id":42210627452998,"key":"42210627452998:fec0f8313f3c56d0060b3006319d9f52","title":"Bento Berlin","price":95,"original_price":95,"presentment_price":0.95,"discounted_price":95,"line_price":95,"original_line_price":95,"total_discount":0,"discounts":[],"sku":null,"grams":0,"vendor":"sushi.catering","taxable":true,"product_id":7711522652230,"product_has_only_default_variant":true,"gift_card":false,"final_price":95,"final_line_price":95,"url":"/products/bento-berlin?variant=42210627452998","featured_image":{"aspect_ratio":1,"alt":"Bento Berlin","height":500,"url":"https://cdn.shopify.com/s/files/1/0588/9179/6550/files/Bento-Berlin.png?v=1728482752","width":500},"image":"https://cdn.shopify.com/s/files/1/0588/9179/6550/files/Bento-Berlin.png?v=1728482752","handle":"bento-berlin","requires_shipping":false,"product_type":"","product_title":"Bento Berlin","product_description":"6x California Gurke Frischkäse Sesam | 6x Maki Avocado","variant_title":null,"variant_options":["Default Title"],"options_with_values":[{"name":"Title","value":"Default Title"}],"line_level_discount_allocations":[],"line_level_total_discount":0,"quantity_rule":{"min":1,"max":null,"increment":1},"has_components":false}]';

        // Send the request to your API endpoint
        $response = $this->post('https://dev.sushi.catering/api/checkOrderInventory', ['items' => $items]);

        // Assert that the status code is 200
        $response->assertStatus(200);

        // Check if the response contains a boolean (0 or 1)
        $response->assertJson(fn ($json) =>
            $json->where('sameday_preorder_time_expired', fn($value) => is_bool($value) || $value === 0 || $value === 1)
        );
    }
}
