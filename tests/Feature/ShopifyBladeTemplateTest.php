<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class ShopifyBladeTemplateTest extends TestCase
{
    /**
     * Test if the default blade template contains a Bootstrap CSS link.
     *
     * @return void
     */
    public function test_shopify_default_blade_contains_bootstrap_css()
    {
        // Mock the Shopify-specific context
        $shop = new \stdClass();
        $shop->name = 'test-shop';
        Auth::shouldReceive('user')->andReturn($shop);

        // Create a mock request with the 'host' parameter
        $request = Request::create('/', 'GET', ['host' => 'test-host']);
        $this->app->instance('request', $request);

        // Render the view and capture its content
        $content = View::file(base_path('vendor/kyon147/laravel-shopify/src/resources/views/layouts/default.blade.php'))->render();

        // Assert that a Bootstrap CSS link is present (for any version)
        $this->assertMatchesRegularExpression(
            '/https:\/\/cdn\.jsdelivr\.net\/npm\/bootstrap@\d+(\.\d+)*\/dist\/css\/bootstrap\.min\.css/',
            $content,
            'Bootstrap CSS link is missing or does not match the expected pattern in the template.'
        );
    }

    /**
     * Test if the Bootstrap CSS is actually loaded.
     *
     * This checks if the Bootstrap file can be loaded from the given URL.
     *
     * @return void
     */
    public function test_shopify_bootstrap_css_is_loaded()
    {
        // Mock the Shopify-specific context
        $shop = new \stdClass();
        $shop->name = 'test-shop';
        Auth::shouldReceive('user')->andReturn($shop);

        // Create a mock request with the 'host' parameter
        $request = Request::create('/', 'GET', ['host' => 'test-host']);
        $this->app->instance('request', $request);

        // Use regex to match any Bootstrap version in the template
        $content = View::file(base_path('vendor/kyon147/laravel-shopify/src/resources/views/layouts/default.blade.php'))->render();

        // Extract the Bootstrap URL using regex
        preg_match('/https:\/\/cdn\.jsdelivr\.net\/npm\/bootstrap@\d+(\.\d+)*\/dist\/css\/bootstrap\.min\.css/', $content, $matches);

        // Ensure we found a Bootstrap URL
        $this->assertNotEmpty($matches, 'No Bootstrap URL found in the template.');

        // URL of the Bootstrap CSS
        $bootstrapUrl = $matches[0];

        // Use file_get_contents to check if the URL is accessible
        $response = file_get_contents($bootstrapUrl);

        // Check that the file is not empty and contains valid CSS content
        $this->assertNotEmpty($response, 'Failed to load Bootstrap CSS from the CDN.');

        // Optionally, you can check if the content contains a known Bootstrap class, like 'container'
        $this->assertStringContainsString(
            '.container',
            $response,
            'Bootstrap CSS does not contain expected class definitions.'
        );
    }

    /**
     * Regression test for embedded navigation loads where Shopify sends a session token
     * but there is no authenticated Laravel user yet.
     */
    public function test_shopify_layout_renders_with_token_when_auth_user_is_null()
    {
        $token = $this->buildUnsignedJwt([
            'dest' => 'https://sushi2024.myshopify.com',
            'iss' => 'https://sushi2024.myshopify.com/admin',
        ]);

        $request = Request::create('/', 'GET', ['token' => $token, 'menu' => 1]);
        $this->app->instance('request', $request);

        // The layout must render without touching Auth::user()->name.
        Auth::shouldReceive('user')->never();

        $content = View::make('shopify-app::layouts.default')->render();

        $this->assertStringContainsString('name="shopify-api-key"', $content);
    }

    private function buildUnsignedJwt(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        return $this->base64UrlEncode($header).'.'.$this->base64UrlEncode($payload).'.signature';
    }

    private function base64UrlEncode(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }
}
