<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QuotationRequest;
use App\Models\School;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each vendor domain behaves as an independent store; the marketplace keeps its shared catalog and checkout. */
class StorefrontIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['recipient' => 'Buyer', 'line1' => '1 Main St', 'city' => 'Lahore', 'country' => 'PK'];

    private function vendor(string $slug, ?string $host = null, string $status = 'active', bool $verified = true): Vendor
    {
        $vendor = Vendor::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
        if ($host) {
            StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => $host, 'is_verified' => $verified, 'provisioning_status' => $verified ? 'active' : 'manual_pending', 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => $verified ? now() : null]);
        }

        return $vendor;
    }

    private function product(Vendor $vendor, string $name, array $extra = [], int $stock = 10): Product
    {
        $product = Product::create(['vendor_id' => $vendor->id, 'name' => $name, 'slug' => str($name)->slug()->toString().'-'.$vendor->id, 'description' => "$name description", 'price' => 100, 'currency' => 'PKR', 'status' => 'published', 'type' => 'supply', ...$extra]);
        Inventory::create(['product_id' => $product->id, 'quantity' => $stock]);

        return $product;
    }

    private function on(string $host)
    {
        return $this->withHeader('X-Store-Host', $host);
    }

    /** A request with no store header, i.e. the shared marketplace (withHeader() would otherwise persist from earlier calls). */
    private function market()
    {
        return $this->withoutHeader('X-Store-Host');
    }

    /** Two independent stores plus the shared marketplace. */
    private function stores(): array
    {
        $a = $this->vendor('ali', 'ali.example.test');
        $b = $this->vendor('bilal', 'bilal.example.test');
        $shared = Category::create(['name' => 'Notebooks', 'slug' => 'notebooks', 'is_active' => true]);
        $onlyB = Category::create(['name' => 'Binding', 'slug' => 'binding', 'is_active' => true]);
        $ownA = Category::create(['name' => 'Ali specials', 'slug' => 'ali-specials', 'is_active' => true, 'vendor_id' => $a->id]);
        $ownB = Category::create(['name' => 'Bilal specials', 'slug' => 'bilal-specials', 'is_active' => true, 'vendor_id' => $b->id]);
        $p = [
            'a1' => $this->product($a, 'Ali Notebook', ['category_id' => $shared->id]),
            'a2' => $this->product($a, 'Ali Pencil', ['category_id' => $ownA->id]),
            'a3' => $this->product($a, 'Ali Flyers', ['type' => 'print']),
            'b1' => $this->product($b, 'Bilal Notebook', ['category_id' => $shared->id]),
            'b2' => $this->product($b, 'Bilal Binder', ['category_id' => $onlyB->id]),
            'b3' => $this->product($b, 'Bilal Banners', ['type' => 'print']),
        ];

        return [$a, $b, $p, compact('shared', 'onlyB', 'ownA', 'ownB')];
    }

    // ---- products ------------------------------------------------------------------------------------------------------

    public function test_a_vendor_domain_lists_searches_filters_and_paginates_only_its_own_products(): void
    {
        [$a, $b, $p, $cats] = $this->stores();

        $this->market()->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonPath('meta.total', 6);   // marketplace unchanged
        $names = fn ($r) => collect($r->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Ali Flyers', 'Ali Notebook', 'Ali Pencil'], $names($this->on('ali.example.test')->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonPath('meta.total', 3)));
        $this->assertSame(['Bilal Banners', 'Bilal Binder', 'Bilal Notebook'], $names($this->on('bilal.example.test')->getJson('/api/v1/products?per_page=100')));

        $this->on('ali.example.test')->getJson('/api/v1/products?q=Bilal')->assertOk()->assertJsonPath('meta.total', 0);
        $this->on('ali.example.test')->getJson('/api/v1/products?q=Notebook')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Ali Notebook');
        $this->on('ali.example.test')->getJson('/api/v1/products?category=binding')->assertOk()->assertJsonPath('meta.total', 0);        // a category only Bilal uses
        $this->on('ali.example.test')->getJson('/api/v1/products?category=notebooks')->assertOk()->assertJsonPath('meta.total', 1);      // a shared category: Ali's items only
        $this->on('ali.example.test')->getJson('/api/v1/products?available=1&sort=price&direction=asc&min_price=1&max_price=1000')->assertOk()->assertJsonPath('meta.total', 3);
        $page = $this->on('ali.example.test')->getJson('/api/v1/products?per_page=2&page=2')->assertOk();
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
        $this->assertCount(1, $page->json('data'));

        // Tampering: a vendor slug, vendor id or similar parameter can never widen the scope.
        $this->on('ali.example.test')->getJson('/api/v1/products?vendor=bilal&vendor_id='.$b->id.'&vendor_slug=bilal&per_page=100')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$a->id], collect($this->on('ali.example.test')->getJson('/api/v1/products?vendor=bilal&per_page=100')->json('data'))->pluck('vendor.id')->unique()->values()->all());
        // On the marketplace the existing ?vendor= filter still works.
        $this->market()->getJson('/api/v1/products?vendor=bilal&per_page=100')->assertOk()->assertJsonPath('meta.total', 3);
        $this->market()->getJson('/api/v1/products?per_page=100')->assertOk()->assertJsonPath('meta.total', 6);   // and the unfiltered shared catalog is still everything
    }

    public function test_product_details_never_expose_another_vendors_product(): void
    {
        [$a, $b, $p] = $this->stores();
        $this->on('ali.example.test')->getJson("/api/v1/products/{$p['a1']->id}")->assertOk()->assertJsonPath('data.name', 'Ali Notebook');
        $this->on('ali.example.test')->getJson("/api/v1/products/{$p['b1']->id}")->assertNotFound();
        $this->on('ali.example.test')->getJson("/api/v1/products/{$p['b1']->id}?vendor=bilal&vendor_id={$b->id}")->assertNotFound();
        $this->on('bilal.example.test')->getJson("/api/v1/products/{$p['b1']->id}")->assertOk();
        $this->market()->getJson("/api/v1/products/{$p['b1']->id}")->assertOk();                      // marketplace: any active vendor's product

        $draft = $this->product($a, 'Ali Draft', ['status' => 'draft']);
        $this->on('ali.example.test')->getJson("/api/v1/products/{$draft->id}")->assertNotFound();
    }

    public function test_categories_follow_the_storefront(): void
    {
        $this->stores();
        $market = collect($this->market()->getJson('/api/v1/categories')->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['binding', 'notebooks'], $market);   // shared categories only (unchanged)
        $ali = collect($this->on('ali.example.test')->getJson('/api/v1/categories')->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['ali-specials', 'notebooks'], $ali);   // own category + shared ones holding Ali's products; not Bilal's
        $bilal = collect($this->on('bilal.example.test')->getJson('/api/v1/categories')->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['bilal-specials', 'binding', 'notebooks'], $bilal);   // its own category + the shared ones it uses
    }

    public function test_vendor_listing_and_profile_endpoints_are_scoped_on_a_vendor_domain(): void
    {
        $this->stores();
        $this->market()->getJson('/api/v1/vendors')->assertOk()->assertJsonPath('meta.total', 2);
        $this->on('ali.example.test')->getJson('/api/v1/vendors')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'ali');
        $this->on('ali.example.test')->getJson('/api/v1/vendors/ali')->assertOk();
        $this->on('ali.example.test')->getJson('/api/v1/vendors/bilal')->assertNotFound();
        $this->on('ali.example.test')->getJson('/api/v1/vendors/resolve?slug=bilal')->assertNotFound();
        $this->on('ali.example.test')->getJson('/api/v1/vendors/resolve?host=bilal.example.test')->assertNotFound();
        $this->market()->getJson('/api/v1/vendors/bilal')->assertOk();   // marketplace unchanged
    }

    // ---- printing ------------------------------------------------------------------------------------------------------

    public function test_printing_services_are_scoped_to_the_storefront(): void
    {
        $this->stores();
        $this->market()->getJson('/api/v1/printing/products')->assertOk()->assertJsonPath('meta.total', 2);
        $this->on('ali.example.test')->getJson('/api/v1/printing/products')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Ali Flyers');
        $this->on('bilal.example.test')->getJson('/api/v1/printing/products?vendor=ali')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Bilal Banners');
    }

    public function test_quote_requests_always_go_to_the_storefront_vendor_and_slugs_cannot_bypass_it(): void
    {
        [$a, $b] = $this->stores();
        $inactive = $this->vendor('closed', null, 'suspended');
        $quote = ['contact_name' => 'Sam', 'email' => 'sam@example.test', 'product_type' => 'Flyers', 'quantity' => 100];

        $id = $this->on('ali.example.test')->postJson('/api/v1/printing/quotes', $quote)->assertCreated()->json('data.id');
        $this->assertSame($a->id, QuotationRequest::find($id)->vendor_id);                       // assigned from the domain, not from the request
        $this->on('ali.example.test')->postJson('/api/v1/printing/quotes', [...$quote, 'vendor_slug' => 'ali'])->assertCreated();
        $this->on('ali.example.test')->postJson('/api/v1/printing/quotes', [...$quote, 'vendor_slug' => 'bilal'])->assertStatus(422)->assertJsonValidationErrors('vendor_slug');
        $this->on('ali.example.test')->postJson('/api/v1/printing/quotes', [...$quote, 'vendor_id' => $b->id])->assertCreated();   // unknown field ignored
        $this->assertSame(0, QuotationRequest::where('vendor_id', $b->id)->count());

        // Marketplace: slug routing to an active vendor still works; inactive or unknown vendors are rejected; no slug = unassigned (unchanged).
        $this->market()->postJson('/api/v1/printing/quotes', [...$quote, 'vendor_slug' => 'bilal'])->assertCreated();
        $this->assertSame(1, QuotationRequest::where('vendor_id', $b->id)->count());
        $this->market()->postJson('/api/v1/printing/quotes', [...$quote, 'vendor_slug' => 'closed'])->assertStatus(422);
        $this->market()->postJson('/api/v1/printing/quotes', [...$quote, 'vendor_slug' => 'nobody'])->assertStatus(422);
        $this->market()->postJson('/api/v1/printing/quotes', $quote)->assertCreated();
        $this->assertSame(0, QuotationRequest::where('vendor_id', $inactive->id)->count());

        // A vendor only ever sees its own quotes.
        $owner = User::find($a->owner_id);
        $seen = collect($this->actingAs($owner)->getJson('/api/v1/vendor/ali/printing/quotes')->assertOk()->json('data'))->pluck('vendor_id')->unique()->all();
        $this->assertSame([$a->id], $seen);
    }

    // ---- cart ----------------------------------------------------------------------------------------------------------

    public function test_cart_rejects_other_vendors_products_on_a_vendor_domain_and_shows_only_its_own_items(): void
    {
        [$a, $b, $p] = $this->stores();
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->on('ali.example.test')->postJson('/api/v1/cart/items', ['product_id' => $p['b1']->id, 'quantity' => 1])->assertStatus(422);
        $this->assertSame(0, Cart::where('user_id', $buyer->id)->first()?->items()->count() ?? 0);
        $this->actingAs($buyer)->on('ali.example.test')->postJson('/api/v1/cart/items', ['product_id' => $p['a1']->id, 'quantity' => 2, 'vendor_id' => $b->id])->assertCreated();

        // The marketplace cart is multi-vendor (unchanged) ...
        $this->actingAs($buyer)->market()->postJson('/api/v1/cart/items', ['product_id' => $p['b1']->id, 'quantity' => 1])->assertCreated();
        $this->assertCount(2, $this->actingAs($buyer)->market()->getJson('/api/v1/cart')->assertOk()->json('data.items'));
        // ... but a vendor storefront only ever sees and touches its own vendor's lines.
        $this->assertSame(['Ali Notebook'], collect($this->actingAs($buyer)->on('ali.example.test')->getJson('/api/v1/cart')->json('data.items'))->pluck('product.name')->all());
        $foreign = Cart::where('user_id', $buyer->id)->first()->items()->where('product_id', $p['b1']->id)->first();
        $this->actingAs($buyer)->on('ali.example.test')->patchJson("/api/v1/cart/items/{$foreign->id}", ['quantity' => 9])->assertNotFound();
        $this->actingAs($buyer)->on('ali.example.test')->deleteJson("/api/v1/cart/items/{$foreign->id}")->assertNotFound();
        $this->assertSame(1, $foreign->fresh()->quantity);
    }

    public function test_products_of_inactive_vendors_cannot_be_added_to_a_cart(): void
    {
        $closed = $this->vendor('closed', null, 'suspended');
        $hidden = $this->product($closed, 'Hidden item');
        $this->actingAs(User::factory()->create())->market()->postJson('/api/v1/cart/items', ['product_id' => $hidden->id, 'quantity' => 1])->assertNotFound();
    }

    // ---- checkout ------------------------------------------------------------------------------------------------------

    public function test_checkout_on_a_vendor_domain_rejects_foreign_products_atomically_and_belongs_to_that_vendor(): void
    {
        [$a, $b, $p] = $this->stores();
        $buyer = User::factory()->create();
        $lines = fn (array ...$ids) => collect($ids)->map(fn ($i) => ['product_id' => $i[0]->id, 'quantity' => $i[1]])->all();

        // Mixed basket: the whole order is refused, nothing is created and no stock moves.
        $this->actingAs($buyer)->on('ali.example.test')->postJson('/api/v1/checkout', ['items' => $lines([$p['a1'], 1], [$p['b1'], 1]), 'shipping_address' => self::ADDRESS])->assertStatus(422);
        $this->assertSame(0, Order::count());
        $this->assertSame(10, $p['a1']->inventory->fresh()->quantity);
        $this->assertSame(10, $p['b1']->inventory->fresh()->quantity);
        $this->actingAs($buyer)->on('ali.example.test')->postJson('/api/v1/checkout', ['items' => $lines([$p['b1'], 1]), 'shipping_address' => self::ADDRESS])->assertStatus(422);

        // Own products succeed; every order line belongs to the storefront vendor, whatever the client claims.
        $order = $this->actingAs($buyer)->on('ali.example.test')->postJson('/api/v1/checkout', [
            'vendor_id' => $b->id, 'vendor_slug' => 'bilal', 'total' => 1, 'price' => 1, 'shipping_address' => self::ADDRESS,
            'items' => [['product_id' => $p['a1']->id, 'quantity' => 2, 'vendor_id' => $b->id, 'price' => 1, 'unit_price' => 1, 'line_total' => 1]],
        ])->assertCreated()->json('data');
        $this->assertSame('200.00', $order['total']);                                    // 2 x the database price, not the tampered 1
        $this->assertSame([$a->id], OrderItem::where('order_id', $order['id'])->pluck('vendor_id')->unique()->all());
        $this->assertSame(8, $p['a1']->inventory->fresh()->quantity);
    }

    public function test_checkout_revalidates_price_stock_and_quantity_on_every_attempt(): void
    {
        [$a, , $p] = $this->stores();
        $buyer = User::factory()->create();
        $buy = fn (int $qty, ?int $id = null) => $this->actingAs($buyer)->on('ali.example.test')->postJson('/api/v1/checkout', ['items' => [['product_id' => $id ?? $p['a1']->id, 'quantity' => $qty]], 'shipping_address' => self::ADDRESS]);

        $p['a1']->update(['price' => 250]);
        $buy(1)->assertCreated()->assertJsonPath('data.total', '250.00');           // the price at checkout time, not at add-to-cart time
        $buy(10)->assertStatus(422);                                                // only 9 left
        $buy(0)->assertStatus(422);
        $buy(-3)->assertStatus(422);
        $buy(101)->assertStatus(422);
        $this->assertSame(9, $p['a1']->inventory->fresh()->quantity);
        $p['a1']->update(['status' => 'draft']);
        $buy(1)->assertNotFound();                                                   // unpublished since: no longer purchasable
        $p['a1']->update(['status' => 'published']);
        $a->update(['status' => 'suspended']);
        $buy(1)->assertNotFound();                                                   // the whole domain stops resolving
    }

    public function test_marketplace_checkout_keeps_its_shared_multi_vendor_behaviour(): void
    {
        [$a, $b, $p] = $this->stores();
        $buyer = User::factory()->create();
        $order = $this->actingAs($buyer)->market()->postJson('/api/v1/checkout', ['items' => [['product_id' => $p['a1']->id, 'quantity' => 1], ['product_id' => $p['b1']->id, 'quantity' => 2]], 'shipping_address' => self::ADDRESS])->assertCreated()->json('data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], OrderItem::where('order_id', $order['id'])->pluck('vendor_id')->all());
        // each vendor only sees its own lines of the shared order
        $seenByA = $this->actingAs(User::find($a->owner_id))->getJson('/api/v1/vendor/ali/orders')->assertOk()->json('data.0.items');
        $this->assertCount(1, $seenByA);
        $this->assertSame($a->id, $seenByA[0]['vendor_id']);
    }

    // ---- inactive vendors and unverified domains -----------------------------------------------------------------------

    public function test_unverified_inactive_unknown_and_malformed_store_hosts_never_fall_back_to_the_marketplace(): void
    {
        $this->stores();
        $this->vendor('draftco', 'unverified.example.test', 'active', false);
        $this->vendor('pending', 'pending.example.test', 'pending');
        $this->vendor('susp', 'susp.example.test', 'suspended');
        $buyer = User::factory()->create();
        $good = Product::first();

        foreach (['unverified.example.test', 'pending.example.test', 'susp.example.test', 'unknown.example.test', '127.0.0.1', 'localhost', 'a'] as $host) {
            foreach (['/api/v1/products', "/api/v1/products/{$good->id}", '/api/v1/categories', '/api/v1/printing/products', '/api/v1/vendors'] as $url) {
                $this->on($host)->getJson($url)->assertNotFound();
            }
            $this->actingAs($buyer)->on($host)->postJson('/api/v1/cart/items', ['product_id' => $good->id, 'quantity' => 1])->assertNotFound();
            $this->actingAs($buyer)->on($host)->postJson('/api/v1/checkout', ['items' => [['product_id' => $good->id, 'quantity' => 1]], 'shipping_address' => self::ADDRESS])->assertNotFound();
            $this->on($host)->postJson('/api/v1/printing/quotes', ['contact_name' => 'S', 'email' => 's@example.test', 'product_type' => 'x', 'quantity' => 1])->assertNotFound();
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, QuotationRequest::count());
        $this->withHeader('X-Store-Host', '')->getJson('/api/v1/products')->assertOk();   // no store header = marketplace
    }

    // ---- schools -------------------------------------------------------------------------------------------------------

    private function school(string $host, string $email): array
    {
        $r = $this->on($host)->postJson('/api/v1/school/register', ['name' => 'Contact', 'email' => $email, 'password' => 'SecureSchool123', 'password_confirmation' => 'SecureSchool123', 'school_name' => 'North School', 'phone' => '03001234567', 'school_phone' => '03007654321', 'address' => 'Main Road'])->assertCreated();

        return [User::where('email', $email)->firstOrFail(), $r->json('token')];
    }

    public function test_a_school_can_only_buy_from_its_own_vendor_even_through_the_generic_endpoints(): void
    {
        [$a, $b, $p] = $this->stores();
        [$user, $token] = $this->school('ali.example.test', 'school@example.test');
        $lines = fn ($product) => ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'shipping_address' => self::ADDRESS];

        // generic marketplace endpoints (no store header): confined to the school's own vendor
        $this->market();
        $this->withToken($token)->market()->postJson('/api/v1/checkout', $lines($p['b1']))->assertStatus(422);
        $this->withToken($token)->market()->postJson('/api/v1/cart/items', ['product_id' => $p['b1']->id, 'quantity' => 1])->assertStatus(422);
        $this->withToken($token)->market()->postJson('/api/v1/cart/items', ['product_id' => $p['a1']->id, 'quantity' => 1])->assertCreated();
        $this->assertSame(0, Order::count());
        // another vendor's storefront: refused outright (it cannot be used to bypass the school's association)
        foreach (['bilal.example.test'] as $host) {
            $this->withToken($token)->on($host)->postJson('/api/v1/checkout', $lines($p['b1']))->assertForbidden();
            $this->withToken($token)->on($host)->postJson('/api/v1/checkout', $lines($p['a1']))->assertForbidden();
            $this->withToken($token)->on($host)->postJson('/api/v1/cart/items', ['product_id' => $p['b1']->id, 'quantity' => 1])->assertForbidden();
            $this->withToken($token)->on($host)->getJson('/api/v1/cart')->assertForbidden();
        }
        // own storefront works, and its orders belong to that vendor
        $order = $this->withToken($token)->on('ali.example.test')->postJson('/api/v1/checkout', $lines($p['a1']))->assertCreated()->json('data');
        $this->assertSame([$a->id], OrderItem::where('order_id', $order['id'])->pluck('vendor_id')->unique()->all());
        // browsing remains public and scoped per domain
        $this->withToken($token)->on('bilal.example.test')->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 3);
        // the dedicated school endpoints keep working
        $this->withToken($token)->on('ali.example.test')->getJson('/api/v1/school/products')->assertOk();
    }

    public function test_an_inactive_school_account_cannot_use_any_checkout_path(): void
    {
        [$a, , $p] = $this->stores();
        [$user, $token] = $this->school('ali.example.test', 'school@example.test');
        School::where('user_id', $user->id)->update(['is_active' => false]);
        $body = ['items' => [['product_id' => $p['a1']->id, 'quantity' => 1]], 'shipping_address' => self::ADDRESS];
        $this->withToken($token)->market()->postJson('/api/v1/checkout', $body)->assertForbidden();
        $this->withToken($token)->on('ali.example.test')->postJson('/api/v1/cart/items', ['product_id' => $p['a1']->id, 'quantity' => 1])->assertForbidden();
        $this->assertSame(0, Order::count());
    }

    public function test_customers_only_see_their_own_orders_and_other_vendors_cannot_touch_them(): void
    {
        [$a, $b, $p] = $this->stores();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $order = $this->actingAs($one)->on('ali.example.test')->postJson('/api/v1/checkout', ['items' => [['product_id' => $p['a1']->id, 'quantity' => 1]], 'shipping_address' => self::ADDRESS])->assertCreated()->json('data');
        $this->actingAs($two)->market()->getJson("/api/v1/orders/{$order['id']}")->assertNotFound();
        $this->actingAs($two)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs(User::find($b->owner_id))->patchJson("/api/v1/vendor/bilal/orders/{$order['id']}", ['status' => 'shipped'])->assertNotFound();
        $this->actingAs(User::find($b->owner_id))->getJson('/api/v1/vendor/bilal/orders')->assertOk()->assertJsonCount(0, 'data');
    }
}
