<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\Store;
use Tests\TestCase;

class ScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_extracts_product_and_persists_offer(): void
    {
        Http::fake([
            'www.amazon.com.mx/*' => Http::response(<<<'HTML'
                <html><head><meta property="og:image" content="https://images.example.test/book.jpg"></head>
                <body><h1 id="productTitle">Black Clover Vol. 1</h1><span class="a-price"><span class="a-offscreen">$500.00</span></span></body></html>
                HTML, 200),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/scan', [
            'url' => 'https://www.amazon.com.mx/Black-Clover-Vol-Yuki-Tabata/dp/1974702219',
        ]);

        $response->assertCreated()->assertJsonPath('product.name', 'Black Clover Vol. 1');
        $response->assertJsonPath('offer.price', 500);
        $this->assertDatabaseHas('stores', ['domain' => 'www.amazon.com.mx', 'name' => 'Amazon']);
        $this->assertDatabaseHas('products', ['slug' => 'black-clover-vol-1', 'search_count' => 1]);
        $this->assertDatabaseHas('product_offers', ['price' => 500]);
    }

    public function test_scan_rejects_private_urls(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/scan', ['url' => 'http://127.0.0.1:8000/product'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_price_drop_creates_alert_for_active_monitor(): void
    {
        Http::fake([
            'www.amazon.com.mx/*' => Http::sequence()
                ->push('<html><body><h1 id="productTitle">Monitor product</h1><span class="a-offscreen">$500.00</span></body></html>')
                ->push('<html><body><h1 id="productTitle">Monitor product</h1><span class="a-offscreen">$450.00</span></body></html>'),
        ]);
        $user = User::factory()->create();
        $url = 'https://www.amazon.com.mx/product/monitor';

        $this->actingAs($user)->postJson('/api/scan', ['url' => $url])->assertCreated();
        $product = \App\Models\Product::firstOrFail();
        $this->actingAs($user)->putJson("/api/products/{$product->id}/monitor", ['interval_minutes' => 60])->assertCreated();
        $this->actingAs($user)->postJson('/api/scan', ['url' => $url])->assertCreated();

        $this->assertDatabaseHas('price_alerts', ['user_id' => $user->id, 'previous_price' => 500, 'current_price' => 450]);
    }

    public function test_scan_does_not_persist_product_without_verified_price(): void
    {
        Http::fake([
            'www.amazon.com.mx/*' => Http::response('<html><body><h1 id="productTitle">Store page</h1></body></html>'),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/scan', ['url' => 'https://www.amazon.com.mx/store'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseHas('scans', ['status' => 'failed']);
    }

    public function test_user_can_update_store_selectors(): void
    {
        $user = User::factory()->create();
        $store = Store::create(['name' => 'Amazon', 'domain' => 'www.amazon.com.mx', 'selectors' => ['name' => '#productTitle']]);

        $this->actingAs($user)
            ->putJson("/api/stores/{$store->id}/selectors", [
                'selectors' => ['price' => '[data-price]', 'image' => '#main-image', 'custom_1' => '#bylineInfo'],
            ])
            ->assertOk()
            ->assertJsonPath('store.selectors.price', '[data-price]')
            ->assertJsonPath('store.selectors.custom_1', '#bylineInfo');

        $this->assertDatabaseHas('stores', ['id' => $store->id]);
    }

    public function test_html_preview_rejects_private_urls(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/stores/preview-html', ['url' => 'http://127.0.0.1:8000/product'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_custom_selector_is_returned_by_static_scanner(): void
    {
        Http::fake([
            'www.amazon.com.mx/*' => Http::response('<html><body><h1 id="productTitle">Book</h1><span class="a-offscreen">$100</span><span id="bylineInfo">Author</span></body></html>'),
        ]);
        $user = User::factory()->create();
        $store = \App\Models\Store::create([
            'name' => 'Amazon',
            'domain' => 'www.amazon.com.mx',
            'selectors' => ['name' => '//*[@id="productTitle"]', 'price' => '//*[contains(@class,"a-offscreen")]', 'image' => '//*[@id="landingImage"]', 'custom_1' => '//*[@id="bylineInfo"]'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/scan', ['url' => 'https://www.amazon.com.mx/book']);

        $response->assertCreated()->assertJsonPath('offer.custom_fields.custom_1', 'Author');
    }

    public function test_liverpool_product_uses_store_specific_selectors(): void
    {
        Http::fake([
            'www.liverpool.com.mx/*' => Http::response(<<<'HTML'
                <html><body>
                <h1>Falda crop a la cintura</h1>
                <span data-testid="discounted">$449.25</span>
                <img data-testid="1200772152-gallery__main__image__image" src="https://sp540.liverpool.com.mx/i/1200772161.jpg">
                </body></html>
                HTML, 200),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/scan', [
            'url' => 'https://www.liverpool.com.mx/tienda/pdp/falda-crop-a-la-cintura/1200772152',
        ]);

        $response->assertCreated()
            ->assertJsonPath('product.name', 'Falda crop a la cintura')
            ->assertJsonPath('offer.price', 449.25)
            ->assertJsonPath('offer.image_url', 'https://sp540.liverpool.com.mx/i/1200772161.jpg');
    }
}
