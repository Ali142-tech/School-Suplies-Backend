<?php

namespace Tests\Feature\Api;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Configurable storefront routing: path mode (default, /vendor/{slug}) and domain mode (hostnames), tenant identity kept separate from URL shape. */
class StorefrontRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['recipient' => 'Buyer', 'line1' => '1 Main St', 'city' => 'Lahore', 'country' => 'PK'];

    private function vendor(string $slug, string $status = 'active', ?string $host = null, bool $verified = true): Vendor
    {
        $vendor = Vendor::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
        if ($host) {
            StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => $host, 'is_verified' => $verified, 'provisioning_status' => $verified ? 'active' : 'manual_pending', 'source' => 'custom', 'is_primary' => true, 'verified_at' => $verified ? now() : null]);
        }

        return $vendor;
    }

    private function product(Vendor $vendor, string $name): Product
    {
        $product = Product::create(['vendor_id' => $vendor->id, 'name' => $name, 'slug' => str($name)->slug().'-'.$vendor->id, 'description' => $name, 'price' => 100, 'currency' => 'PKR', 'status' => 'published', 'type' => 'supply']);
        Inventory::create(['product_id' => $product->id, 'quantity' => 10]);

        return $product;
    }

    private function at(string $slug)
    {
        return $this->withoutHeader('X-Store-Host')->withHeader('X-Store-Slug', $slug);
    }

    private function host(string $host)
    {
        return $this->withoutHeader('X-Store-Slug')->withHeader('X-Store-Host', $host);
    }

    private function mode(string $mode, ?string $platformDomain = null, ?string $base = 'https://school-suplies.vercel.app'): void
    {
        config(['platform.storefront.mode' => $mode, 'platform.domain' => $platformDomain, 'platform.storefront.base_url' => $base]);
    }

    private function pending(string $slug = 'applicant'): array
    {
        return [User::factory()->create(['role' => 'platform_admin']), $this->vendor($slug, 'pending')];
    }

    // ---- approval -----------------------------------------------------------------------------------------------------

    public function test_path_mode_is_the_default(): void
    {
        $this->assertSame('path', config('platform.storefront.mode'));
    }

    public function test_vendor_approval_works_in_path_mode_without_a_platform_domain(): void
    {
        $this->mode('path', null);
        [$admin, $vendor] = $this->pending();

        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'acme'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('storefront_url', 'https://school-suplies.vercel.app/vendor/acme')
            ->assertJsonCount(0, 'data.domains');
        $this->assertSame(0, StoreDomain::count());

        // live at once: path-based storefronts need no DNS and no manual activation
        $this->at('acme')->getJson('/api/v1/site')->assertOk();
        $this->getJson('/api/v1/vendors/resolve?slug=acme')->assertOk()->assertJsonPath('data.storefront_url', 'https://school-suplies.vercel.app/vendor/acme');
    }

    public function test_path_mode_still_validates_the_slug_and_prepares_a_pending_mapping_when_a_platform_domain_exists(): void
    {
        $this->mode('path', 'platform.test');
        [$admin, $vendor] = $this->pending();
        foreach (['api', 'login', 'dashboard', 'Bad Slug'] as $bad) {
            $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => $bad])->assertUnprocessable();
        }
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'acme'])->assertOk()->assertJsonPath('data.domains.0.hostname', 'acme.platform.test');
        $domain = $vendor->domains()->firstOrFail();
        $this->assertFalse((bool) $domain->is_verified);
        $this->assertSame('manual_pending', $domain->provisioning_status);
        $this->getJson('/api/v1/vendors/resolve?host=acme.platform.test')->assertNotFound();   // not activated, so not a tenant by host
        $this->at('acme')->getJson('/api/v1/site')->assertOk();                                   // yet reachable by path
    }

    public function test_domain_mode_requires_a_platform_domain_to_approve(): void
    {
        $this->mode('domain', null);
        [$admin, $vendor] = $this->pending();
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'acme'])->assertStatus(503);
        $this->assertSame('pending', $vendor->fresh()->status);

        $this->mode('domain', 'platform.test');
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'acme'])
            ->assertOk()->assertJsonPath('data.domains.0.hostname', 'acme.platform.test')->assertJsonPath('storefront_url', null);

        // activation publishes the hostname
        $domain = $vendor->domains()->firstOrFail();
        $this->actingAs($admin)->postJson("/api/v1/admin/domains/{$domain->id}/activate", ['dns_configured' => true])->assertOk();
        $this->getJson('/api/v1/vendors/resolve?slug=acme')->assertOk()->assertJsonPath('data.storefront_url', 'https://acme.platform.test');
    }

    public function test_an_invalid_mode_value_falls_back_to_path_mode(): void
    {
        $this->mode('bogus', null);
        [$admin, $vendor] = $this->pending();
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'acme'])->assertOk();
    }

    // ---- path mode tenant resolution ----------------------------------------------------------------------------------

    public function test_path_mode_scopes_catalog_cart_and_checkout_to_the_slug_vendor(): void
    {
        $this->mode('path');
        $a = $this->vendor('ali');
        $b = $this->vendor('bilal');
        $pa = $this->product($a, 'Ali Notebook');
        $pb = $this->product($b, 'Bilal Notebook');
        $buyer = User::factory()->create();

        $this->at('ali')->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Ali Notebook');
        $this->at('ali')->getJson('/api/v1/products?vendor=bilal&per_page=100')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Ali Notebook');
        $this->at('ali')->getJson("/api/v1/products/{$pb->id}")->assertNotFound();
        $this->at('ali')->getJson('/api/v1/vendors/bilal')->assertNotFound();
        $this->at('ali')->getJson('/api/v1/vendors/resolve?slug=bilal')->assertNotFound();
        $this->at('ali')->getJson('/api/v1/vendors')->assertOk()->assertJsonPath('meta.total', 1);
        $this->withoutHeader('X-Store-Slug')->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonPath('meta.total', 2);   // marketplace unchanged

        $this->actingAs($buyer)->at('ali')->postJson('/api/v1/cart/items', ['product_id' => $pb->id, 'quantity' => 1])->assertStatus(422);
        $this->actingAs($buyer)->at('ali')->postJson('/api/v1/cart/items', ['product_id' => $pa->id, 'quantity' => 1, 'vendor_id' => $b->id])->assertCreated();
        $this->actingAs($buyer)->at('ali')->postJson('/api/v1/checkout', ['items' => [['product_id' => $pa->id, 'quantity' => 1], ['product_id' => $pb->id, 'quantity' => 1]], 'shipping_address' => self::ADDRESS])->assertStatus(422);
        $this->assertSame(0, Order::count());
        $order = $this->actingAs($buyer)->at('ali')->postJson('/api/v1/checkout', ['vendor_id' => $b->id, 'items' => [['product_id' => $pa->id, 'quantity' => 1]], 'shipping_address' => self::ADDRESS])->assertCreated()->json('data');
        $this->assertSame([$a->id], \App\Models\OrderItem::where('order_id', $order['id'])->pluck('vendor_id')->unique()->all());
    }

    public function test_path_mode_rejects_unknown_inactive_and_malformed_slugs_without_marketplace_fallback(): void
    {
        $this->mode('path');
        $this->vendor('ali');
        $this->vendor('waiting', 'pending');
        $this->vendor('closed', 'suspended');
        foreach (['nobody', 'waiting', 'closed', 'Bad Slug', '-x', 'a--b', str_repeat('a', 64), '../admin'] as $slug) {
            foreach (['/api/v1/products', '/api/v1/categories', '/api/v1/vendors', '/api/v1/printing/products', '/api/v1/site'] as $url) {
                $this->at($slug)->getJson($url)->assertNotFound();
            }
            $this->actingAs(User::factory()->create())->at($slug)->postJson('/api/v1/checkout', ['items' => [], 'shipping_address' => self::ADDRESS])->assertNotFound();
        }
    }

    public function test_the_slug_is_a_claim_that_must_agree_with_a_verified_host_when_both_are_sent(): void
    {
        $this->mode('path');
        $this->vendor('ali', 'active', 'ali.example.test');
        $this->vendor('bilal', 'active', 'bilal.example.test');
        $this->withHeaders(['X-Store-Slug' => 'ali', 'X-Store-Host' => 'ali.example.test'])->getJson('/api/v1/site')->assertOk();
        $this->withHeaders(['X-Store-Slug' => 'ali', 'X-Store-Host' => 'bilal.example.test'])->getJson('/api/v1/site')->assertNotFound();
        $this->withHeaders(['X-Store-Slug' => 'ali', 'X-Store-Host' => 'unknown.example.test'])->getJson('/api/v1/products')->assertNotFound();
    }

    public function test_verified_domains_keep_working_in_path_mode_but_unverified_ones_do_not(): void
    {
        $this->mode('path');
        $this->vendor('ali', 'active', 'ali.example.test');
        $this->vendor('draft', 'active', 'draft.example.test', false);
        $this->host('ali.example.test')->getJson('/api/v1/site')->assertOk();
        $this->host('draft.example.test')->getJson('/api/v1/site')->assertNotFound();
        // while the slug of the same vendor does not need a domain at all
        $this->at('draft')->getJson('/api/v1/site')->assertOk();
    }

    public function test_school_accounts_stay_bound_to_their_vendor_in_path_mode(): void
    {
        $this->mode('path');
        $a = $this->vendor('ali');
        $b = $this->vendor('bilal');
        $pa = $this->product($a, 'Ali Notebook');
        $pb = $this->product($b, 'Bilal Notebook');
        $r = $this->at('ali')->postJson('/api/v1/school/register', ['name' => 'Contact', 'email' => 'school@example.test', 'password' => 'SecureSchool123', 'password_confirmation' => 'SecureSchool123', 'school_name' => 'North School', 'phone' => '03001234567', 'school_phone' => '03007654321', 'address' => 'Main Road'])->assertCreated();
        $token = $r->json('token');
        $lines = fn ($p) => ['items' => [['product_id' => $p->id, 'quantity' => 1]], 'shipping_address' => self::ADDRESS];

        $this->withToken($token)->at('bilal')->postJson('/api/v1/checkout', $lines($pb))->assertForbidden();
        $this->withToken($token)->at('bilal')->getJson('/api/v1/cart')->assertForbidden();
        $this->withToken($token)->withoutHeader('X-Store-Slug')->postJson('/api/v1/checkout', $lines($pb))->assertStatus(422);
        $this->withToken($token)->at('ali')->postJson('/api/v1/checkout', $lines($pa))->assertCreated();
        $this->withToken($token)->at('ali')->getJson('/api/v1/school/products')->assertOk();
        $this->at('bilal')->postJson('/api/v1/school/login', ['email' => 'school@example.test', 'password' => 'SecureSchool123'])->assertStatus(422);   // not a school of that store
    }

    public function test_public_site_is_served_per_slug(): void
    {
        $this->mode('path');
        $this->vendor('ali');
        $this->vendor('bilal');
        $this->at('ali')->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.name', 'Ali');
        $this->at('bilal')->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.name', 'Bilal');
        $this->at('ali')->getJson('/api/v1/site/pages/unpublished')->assertNotFound();
    }

    // ---- domain mode --------------------------------------------------------------------------------------------------

    public function test_domain_mode_ignores_the_path_slug_and_resolves_only_verified_hosts(): void
    {
        $this->mode('domain', 'platform.test');
        $a = $this->vendor('ali', 'active', 'ali.platform.test');
        $this->vendor('draft', 'active', 'draft.platform.test', false);
        $this->product($a, 'Ali Notebook');

        $this->at('ali')->getJson('/api/v1/products')->assertNotFound();            // slug claims are not tenant identity in domain mode
        $this->at('ali')->getJson('/api/v1/site')->assertNotFound();
        $this->host('ali.platform.test')->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
        $this->host('draft.platform.test')->getJson('/api/v1/products')->assertNotFound();
        $this->host('ali.platform.test')->getJson('/api/v1/site')->assertOk();
        $this->withoutHeader('X-Store-Slug')->withoutHeader('X-Store-Host')->getJson('/api/v1/products')->assertOk();   // marketplace
    }

    public function test_storefront_url_follows_the_mode(): void
    {
        $vendor = $this->vendor('ali', 'active', 'shop.example.com');
        $this->mode('path', null, 'https://school-suplies.vercel.app/');
        $this->assertSame('https://school-suplies.vercel.app/vendor/ali', \App\Support\Storefront::url($vendor));
        $this->mode('path', null, null);
        $this->assertSame('/vendor/ali', \App\Support\Storefront::url($vendor));
        $this->mode('domain', 'platform.test');
        $this->assertSame('https://shop.example.com', \App\Support\Storefront::url($vendor));
        $vendor->domains()->update(['is_verified' => false, 'provisioning_status' => 'manual_pending']);
        $this->assertNull(\App\Support\Storefront::url($vendor->fresh()));
    }
}
