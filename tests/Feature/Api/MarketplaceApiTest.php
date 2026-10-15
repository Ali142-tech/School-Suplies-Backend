<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MarketplaceApiTest extends TestCase
{
    use RefreshDatabase;

    private function store(User $owner, string $slug, string $status = 'active'): Vendor
    {
        return Vendor::create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
    }

    private function schoolDomain(Vendor $vendor, string $hostname, bool $active = true): StoreDomain
    {
        return StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => $hostname, 'is_verified' => $active, 'provisioning_status' => $active ? 'active' : 'manual_pending', 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => $active ? now() : null]);
    }

    private function registerSchool(string $hostname, string $email = 'school@example.test'): TestResponse
    {
        return $this->withHeader('X-Store-Host', $hostname)->postJson('/api/v1/school/register', [
            'name' => 'School Contact', 'email' => $email, 'password' => 'SecureSchool123', 'password_confirmation' => 'SecureSchool123',
            'school_name' => 'North School', 'phone' => '03001234567', 'school_phone' => '03007654321', 'address' => 'Main Road',
        ]);
    }

    public function test_customer_can_register_and_get_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Customer', 'email' => 'buyer@example.test', 'password' => 'SecurePass1234', 'password_confirmation' => 'SecurePass1234']);
        $response->assertCreated()->assertJsonStructure(['data' => ['id', 'email'], 'token']);
        $this->assertDatabaseHas('users', ['email' => 'buyer@example.test', 'role' => 'customer']);
    }

    public function test_personal_access_token_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'test']);
    }

    public function test_public_catalog_only_returns_published_products_from_active_vendors(): void
    {
        $owner = User::factory()->create();
        $vendor = $this->store($owner, 'active-shop');
        $draft = $this->store($owner, 'draft-shop', 'pending');
        foreach ([[$vendor, 'Visible', 'published'], [$vendor, 'Hidden', 'draft'], [$draft, 'Pending shop item', 'published']] as [$store,$name,$status]) {
            Product::create(['vendor_id' => $store->id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'price' => 100, 'currency' => 'PKR', 'status' => $status]);
        } $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Visible');
    }

    public function test_vendor_member_cannot_manage_another_vendors_product(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownStore = $this->store($owner, 'my-shop');
        $otherStore = $this->store($other, 'other-shop');
        $product = Product::create(['vendor_id' => $otherStore->id, 'name' => 'Other item', 'slug' => 'other-item', 'price' => 100, 'currency' => 'PKR', 'status' => 'published']);
        $this->actingAs($owner)->deleteJson("/api/v1/vendor/{$ownStore->slug}/products/{$product->id}")->assertNotFound();
        $this->actingAs(User::factory()->create())->withHeader('Host', 'other-shop.platform.test')->getJson("/api/v1/vendor/{$ownStore->slug}/products")->assertForbidden();
    }

    public function test_vendor_cannot_update_another_stores_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownStore = $this->store($owner, 'order-own-store');
        $otherStore = $this->store($other, 'order-other-store');
        $customer = User::factory()->create();
        $order = Order::create([
            'number' => 'PT-ORDER-TEST', 'customer_id' => $customer->id, 'status' => 'pending', 'payment_status' => 'unpaid',
            'subtotal' => 100, 'total' => 100, 'currency' => 'PKR', 'shipping_address' => ['recipient' => 'Buyer', 'line1' => 'Street', 'city' => 'Lahore', 'country' => 'PK'],
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'vendor_id' => $otherStore->id, 'product_name' => 'Other store item',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'status' => 'pending',
        ]);

        $this->actingAs($owner)->patchJson("/api/v1/vendor/{$ownStore->slug}/orders/{$order->id}", ['status' => 'shipped'])->assertNotFound();
    }

    public function test_checkout_calculates_prices_and_decrements_stock_transactionally(): void
    {
        $customer = User::factory()->create();
        $owner = User::factory()->create();
        $vendor = $this->store($owner, 'checkout-shop');
        $product = Product::create(['vendor_id' => $vendor->id, 'name' => 'Paper Pack', 'slug' => 'paper-pack', 'price' => 1250, 'currency' => 'PKR', 'status' => 'published']);
        Inventory::create(['product_id' => $product->id, 'quantity' => 5]);
        $this->actingAs($customer)->postJson('/api/v1/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'shipping_address' => ['recipient' => 'Buyer', 'line1' => '1 Main St', 'city' => 'Lahore', 'country' => 'PK']])->assertCreated()->assertJsonPath('data.total', '2500.00')->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 3]);
    }

    public function test_platform_management_is_reserved_for_platform_admins(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/overview')->assertForbidden();
        $admin = User::factory()->create(['role' => 'platform_admin']);
        $this->actingAs($admin)->getJson('/api/v1/admin/overview')->assertOk()->assertJsonStructure(['data' => ['vendors', 'customers', 'products', 'orders', 'revenue']]);
    }

    public function test_vendor_application_is_created_as_pending_without_client_controlled_owner(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/vendors/apply', ['name' => 'My Shop', 'slug' => 'my-shop', 'owner_id' => 999]);
        $response->assertCreated()->assertJsonPath('data.owner_id', $user->id)->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('vendors', ['slug' => 'my-shop', 'owner_id' => $user->id]);
    }

    public function test_address_records_are_scoped_to_the_authenticated_customer(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $address = $customer->addresses()->create(['recipient' => 'Customer', 'line1' => '1 Main St', 'city' => 'Lahore', 'country' => 'PK']);
        $this->actingAs($other)->putJson("/api/v1/addresses/{$address->id}", ['recipient' => 'Other', 'line1' => '2 Main St', 'city' => 'Karachi', 'country' => 'PK'])->assertNotFound();
    }

    public function test_print_quote_is_linked_to_signed_in_customer_and_status_is_private(): void
    {
        $customer = User::factory()->create();
        $response = $this->actingAs($customer)->postJson('/api/v1/printing/quotes', ['contact_name' => 'Customer', 'email' => $customer->email, 'product_type' => 'Flyers', 'quantity' => 250]);
        $response->assertCreated();
        $quoteId = $response->json('data.id');
        $this->getJson("/api/v1/printing/quotes/{$quoteId}")->assertOk();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/printing/quotes/{$quoteId}")->assertNotFound();
    }

    public function test_development_seeder_provides_supply_and_printing_catalogs(): void
    {
        $this->seed();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 7);
        $this->getJson('/api/v1/printing/products')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertDatabaseHas('vendors', ['slug' => 'pending-shop', 'status' => 'pending']);
        $this->assertDatabaseHas('vendors', ['slug' => 'suspended-shop', 'status' => 'suspended']);
        $this->assertDatabaseMissing('users', ['role' => 'platform_admin']);
    }

    public function test_vendor_registration_assigns_owner_and_pending_status_on_server(): void
    {
        $category = Category::create(['name' => 'Stationery', 'slug' => 'stationery', 'is_active' => true]);
        $response = $this->postJson('/api/v1/auth/vendor/register', [
            'name' => 'New Seller', 'business_name' => 'New Paper Shop', 'email' => 'new-seller@example.test',
            'store_slug' => 'new-paper-shop',
            'phone' => '+923001234567', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123',
            'business_address' => '1 Main Road, Lahore', 'category_id' => $category->id, 'terms_accepted' => true,
            'role' => 'platform_admin', 'status' => 'active', 'owner_id' => 999,
        ]);
        $response->assertCreated()->assertJsonPath('data.vendor.status', 'pending')->assertJsonPath('data.user.role', 'vendor')->assertJsonStructure(['token']);
        $this->assertDatabaseHas('vendors', ['slug' => 'new-paper-shop', 'owner_id' => $response->json('data.user.id'), 'status' => 'pending']);
        $this->assertDatabaseMissing('store_domains', ['vendor_id' => $response->json('data.vendor.id')]);
    }

    public function test_vendor_can_register_with_only_name_email_and_password(): void
    {
        $this->postJson('/api/v1/auth/vendor/register', ['name' => 'Quick Seller', 'email' => 'quick@example.test', 'password' => 'StrongPassword123'])
            ->assertCreated()->assertJsonPath('data.vendor.status', 'pending')->assertJsonPath('data.user.role', 'vendor')->assertJsonStructure(['token']);
        $this->assertDatabaseHas('vendors', ['name' => 'Quick Seller', 'email' => 'quick@example.test', 'category_id' => null, 'business_address' => null, 'status' => 'pending']);
    }

    public function test_vendor_registration_rejects_an_invalid_category_but_accepts_an_active_platform_one(): void
    {
        $payload = ['name' => 'Cat Seller', 'email' => 'cat@example.test', 'password' => 'StrongPassword123'];
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = Vendor::create(['owner_id' => $owner->id, 'name' => 'Other', 'slug' => 'other-shop', 'status' => 'active']);
        $vendorOwned = Category::create(['vendor_id' => $other->id, 'name' => 'Private', 'slug' => 'private', 'is_active' => true]);
        $inactive = Category::create(['name' => 'Retired', 'slug' => 'retired', 'is_active' => false]);
        $active = Category::create(['name' => 'Paper', 'slug' => 'paper', 'is_active' => true]);

        foreach ([999999, $vendorOwned->id, $inactive->id] as $bad) {
            $this->postJson('/api/v1/auth/vendor/register', [...$payload, 'category_id' => $bad])->assertUnprocessable();
        }
        $this->assertDatabaseMissing('users', ['email' => 'cat@example.test']);
        $this->postJson('/api/v1/auth/vendor/register', [...$payload, 'category_id' => $active->id])->assertCreated();
        $this->assertDatabaseHas('vendors', ['email' => 'cat@example.test', 'category_id' => $active->id]);
    }

    public function test_vendor_can_sign_in_while_pending_and_gets_its_own_store_status(): void
    {
        $category = Category::create(['name' => 'Paper', 'slug' => 'paper', 'is_active' => true]);
        $this->postJson('/api/v1/auth/vendor/register', [
            'name' => 'Waiting Seller', 'business_name' => 'Waiting Store', 'email' => 'waiting@example.test',
            'store_slug' => 'waiting-store',
            'phone' => '03001234567', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123',
            'business_address' => 'Main Road', 'category_id' => $category->id, 'terms_accepted' => true,
        ])->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'waiting@example.test', 'password' => 'StrongPassword123']);
        $login->assertOk()->assertJsonPath('data.role', 'vendor')->assertJsonPath('data.vendor_memberships.0.vendor.status', 'pending')->assertJsonStructure(['token']);
    }

    public function test_cors_preflight_allows_local_next_origin(): void
    {
        $this->call('OPTIONS', '/api/v1/categories', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'accept,content-type',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');

        $this->call('OPTIONS', '/api/v1/categories', [], [], [], [
            'HTTP_ORIGIN' => 'http://paper-shop.localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'accept,content-type',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://paper-shop.localhost:3000');
    }

    public function test_pending_and_suspended_vendors_cannot_change_products(): void
    {
        foreach (['pending', 'suspended'] as $status) {
            $owner = User::factory()->create();
            $vendor = $this->store($owner, $status.'-store', $status);
            $this->actingAs($owner)->postJson("/api/v1/vendor/{$vendor->slug}/products", [
                'name' => 'Blocked product', 'price' => 100, 'quantity' => 2,
            ])->assertForbidden();
            $this->actingAs($owner)->getJson("/api/v1/vendor/{$vendor->slug}/orders")->assertForbidden();
        }
    }

    public function test_active_vendor_can_create_its_own_product(): void
    {
        config(['platform.domain' => 'platform.test']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $vendor = $this->store($owner, 'approved-store', 'active');
        $this->actingAs($owner)->postJson("/api/v1/vendor/{$vendor->slug}/products", [
            'name' => 'Approved listing', 'price' => 250, 'quantity' => 8, 'type' => 'supply', 'status' => 'published',
        ])->assertCreated()->assertJsonPath('data.vendor_id', $vendor->id);
        $this->assertDatabaseHas('products', ['vendor_id' => $vendor->id, 'slug' => 'approved-listing']);
        $this->actingAs($owner)->postJson("/api/v1/vendor/{$vendor->slug}/domains", ['hostname' => 'admin.platform.test'])->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/v1/vendor/{$vendor->slug}/domains", ['hostname' => 'another.platform.test'])->assertUnprocessable();
    }

    public function test_only_platform_admin_can_approve_vendors_and_user_updates_cannot_grant_admin_role(): void
    {
        $owner = User::factory()->create();
        $vendor = $this->store($owner, 'approval-store', 'pending');
        $customer = User::factory()->create();
        $this->actingAs($customer)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'approval-store'])->assertForbidden();
        $this->actingAs($customer)->getJson('/api/v1/admin/vendor-requests')->assertForbidden();
        $admin = User::factory()->create(['role' => 'platform_admin']);
        $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$customer->id}", ['is_active' => true, 'role' => 'platform_admin'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'role' => 'customer']);
        config(['platform.domain' => 'platform.test']);
        $this->actingAs($admin)->getJson('/api/v1/admin/vendor-requests?status=pending')->assertOk()->assertJsonPath('data.0.id', $vendor->id);
        $this->actingAs($admin)->getJson("/api/v1/admin/vendor-requests/{$vendor->id}")->assertOk()->assertJsonPath('data.id', $vendor->id);
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'approval-store'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.domains.0.hostname', 'approval-store.platform.test');
    }

    public function test_registration_rejects_reserved_or_duplicate_requested_slugs(): void
    {
        $category = Category::create(['name' => 'Stationery', 'slug' => 'stationery', 'is_active' => true]);
        $payload = ['name' => 'Seller', 'business_name' => 'My Store', 'email' => 'slug-test@example.test', 'phone' => '03001234567', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123', 'business_address' => 'Main Road', 'category_id' => $category->id, 'terms_accepted' => true];
        $this->postJson('/api/v1/auth/vendor/register', [...$payload, 'store_slug' => 'admin'])->assertUnprocessable();
        Vendor::create(['owner_id' => User::factory()->create()->id, 'name' => 'Taken', 'slug' => 'taken-store', 'status' => 'pending']);
        $this->postJson('/api/v1/auth/vendor/register', [...$payload, 'store_slug' => 'taken-store'])->assertUnprocessable();
    }

    public function test_approved_hostname_resolves_only_after_manual_activation_and_stops_when_suspended(): void
    {
        config(['platform.domain' => 'platform.test']);
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'platform_admin']);
        $vendor = $this->store($owner, 'approved-domain', 'pending');
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'approved-domain'])->assertOk();
        $domain = $vendor->domains()->firstOrFail();
        $this->getJson('/api/v1/vendors/resolve?host=approved-domain.platform.test')->assertNotFound();
        $this->actingAs($admin)->postJson("/api/v1/admin/domains/{$domain->id}/activate", ['dns_configured' => true])->assertOk();
        $this->getJson('/api/v1/vendors/resolve?host=approved-domain.platform.test')->assertOk()->assertJsonPath('data.slug', 'approved-domain');
        $this->actingAs($admin)->patchJson("/api/v1/admin/vendors/{$vendor->id}/suspend")->assertOk();
        $this->getJson('/api/v1/vendors/resolve?host=approved-domain.platform.test')->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/v1/admin/vendors/{$vendor->id}/reactivate")->assertOk();
        $this->getJson('/api/v1/vendors/resolve?host=approved-domain.platform.test')->assertNotFound();
    }

    public function test_rejected_application_is_not_resolvable_and_owner_can_resubmit(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'platform_admin']);
        $vendor = $this->store($owner, 'resubmit-shop', 'pending');
        $vendor->memberships()->create(['user_id' => $owner->id, 'role' => 'manager']);
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/reject", ['reason' => 'Please provide a valid business address.'])->assertOk();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id]);
        $this->assertDatabaseMissing('store_domains', ['vendor_id' => $vendor->id]);
        $notice = $this->actingAs($owner)->getJson('/api/v1/notifications')->assertOk()->json('data.data.0');
        $this->actingAs($owner)->patchJson('/api/v1/notifications/'.$notice['id'])->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/vendor/resubmit-shop/application')->assertOk()->assertJsonPath('data.vendor.rejection_reason', 'Please provide a valid business address.');
        $this->getJson('/api/v1/vendors/resolve?slug=resubmit-shop')->assertNotFound();
        $this->actingAs($owner)->postJson('/api/v1/vendor/resubmit-shop/reapply', ['store_slug' => 'resubmit-shop-new'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'status' => 'pending', 'rejection_reason' => null]);
    }

    public function test_reserved_slug_cannot_be_approved_and_admin_update_cannot_bypass_workflow(): void
    {
        config(['platform.domain' => 'platform.test']);
        $admin = User::factory()->create(['role' => 'platform_admin']);
        $vendor = $this->store(User::factory()->create(), 'valid-request', 'pending');
        $this->actingAs($admin)->postJson("/api/v1/admin/vendor-requests/{$vendor->id}/approve", ['slug' => 'api'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/v1/admin/vendors/{$vendor->id}", ['status' => 'active'])->assertStatus(405);
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'status' => 'pending']);
    }

    public function test_school_registration_assigns_vendor_from_active_hostname_and_never_from_request_body(): void
    {
        $vendor = $this->store(User::factory()->create(), 'school-shop');
        $this->schoolDomain($vendor, 'school-shop.platform.test');

        $response = $this->withHeader('X-Store-Host', 'school-shop.platform.test')->postJson('/api/v1/school/register', [
            'name' => 'School Contact', 'email' => 'school@example.test', 'password' => 'SecureSchool123', 'password_confirmation' => 'SecureSchool123',
            'school_name' => 'North School', 'vendor_id' => 999,
        ]);

        $response->assertCreated()->assertJsonPath('data.role', 'school')->assertJsonPath('data.school.vendor_id', $vendor->id)->assertJsonStructure(['token']);
        $this->assertDatabaseHas('schools', ['vendor_id' => $vendor->id, 'name' => 'North School']);
    }

    public function test_school_registration_rejects_unknown_inactive_and_unapproved_vendor_domains(): void
    {
        $pending = $this->store(User::factory()->create(), 'pending-school-shop', 'pending');
        $inactive = $this->store(User::factory()->create(), 'inactive-school-shop');
        $this->schoolDomain($pending, 'pending-school.platform.test');
        $this->schoolDomain($inactive, 'inactive-school.platform.test', false);

        foreach (['unknown.platform.test', 'inactive-school.platform.test', 'pending-school.platform.test'] as $host) {
            $this->registerSchool($host, str_replace('.', '-', $host).'@example.test')->assertNotFound();
        }

        $this->withHeader('X-Store-Host', 'pending-school.platform.test')->postJson('/api/v1/school/login', ['email' => 'someone@example.test', 'password' => 'SecureSchool123'])->assertNotFound();
    }

    public function test_school_can_login_on_own_domain_but_cross_vendor_login_is_rejected(): void
    {
        $vendorA = $this->store(User::factory()->create(), 'school-a-shop');
        $vendorB = $this->store(User::factory()->create(), 'school-b-shop');
        $this->schoolDomain($vendorA, 'school-a.platform.test');
        $this->schoolDomain($vendorB, 'school-b.platform.test');
        $this->registerSchool('school-a.platform.test')->assertCreated();
        $credentials = ['email' => 'school@example.test', 'password' => 'SecureSchool123'];

        $this->withHeader('X-Store-Host', 'school-a.platform.test')->postJson('/api/v1/school/login', $credentials)->assertOk()->assertJsonPath('data.school.vendor_id', $vendorA->id);
        $this->withHeader('X-Store-Host', 'school-b.platform.test')->postJson('/api/v1/school/login', $credentials)->assertUnprocessable();
    }

    public function test_school_cannot_read_another_schools_orders_or_use_another_vendor_domain(): void
    {
        $vendorA = $this->store(User::factory()->create(), 'private-school-a');
        $vendorB = $this->store(User::factory()->create(), 'private-school-b');
        $this->schoolDomain($vendorA, 'private-a.platform.test');
        $this->schoolDomain($vendorB, 'private-b.platform.test');
        $registered = $this->registerSchool('private-a.platform.test', 'private-a@example.test')->assertCreated();
        $schoolA = User::where('email', 'private-a@example.test')->firstOrFail();
        $schoolB = User::factory()->create(['role' => 'school']);
        $schoolB->school()->create(['vendor_id' => $vendorB->id, 'name' => 'Private B']);
        $product = Product::create(['vendor_id' => $vendorB->id, 'name' => 'B only', 'slug' => 'b-only', 'price' => 100, 'currency' => 'PKR', 'status' => 'published']);
        $order = Order::create(['number' => 'PT-SCHOOL-B', 'customer_id' => $schoolB->id, 'status' => 'pending', 'payment_status' => 'unpaid', 'subtotal' => 100, 'total' => 100, 'currency' => 'PKR', 'shipping_address' => ['recipient' => 'B', 'line1' => '1 Main', 'city' => 'Lahore', 'country' => 'PK']]);
        OrderItem::create(['order_id' => $order->id, 'vendor_id' => $vendorB->id, 'product_id' => $product->id, 'product_name' => 'B only', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);

        $token = $registered->json('token');
        $this->withToken($token)->withHeader('X-Store-Host', 'private-a.platform.test')->getJson('/api/v1/school/orders/'.$order->id)->assertNotFound();
        $this->withToken($token)->withHeader('X-Store-Host', 'private-b.platform.test')->getJson('/api/v1/school/me')->assertForbidden();
        $this->withToken($token)->withHeader('X-Store-Host', 'private-a.platform.test')->getJson('/api/v1/school/products')->assertOk();
        $this->actingAs($schoolB)->withHeader('X-Store-Host', 'private-b.platform.test')->getJson('/api/v1/school/orders')->assertOk();
        $this->actingAs($schoolB)->withHeader('X-Store-Host', 'private-b.platform.test')->getJson('/api/v1/school/orders/'.$order->id)->assertOk();
    }

    public function test_school_products_and_checkout_are_limited_to_resolved_vendor_and_stock(): void
    {
        $vendorA = $this->store(User::factory()->create(), 'catalog-school-a');
        $vendorB = $this->store(User::factory()->create(), 'catalog-school-b');
        $this->schoolDomain($vendorA, 'catalog-a.platform.test');
        $school = $this->registerSchool('catalog-a.platform.test')->assertCreated();
        $user = User::where('email', 'school@example.test')->firstOrFail();
        $own = Product::create(['vendor_id' => $vendorA->id, 'name' => 'Own book', 'slug' => 'own-book', 'price' => 50, 'currency' => 'PKR', 'status' => 'published']);
        $other = Product::create(['vendor_id' => $vendorB->id, 'name' => 'Other book', 'slug' => 'other-book', 'price' => 75, 'currency' => 'PKR', 'status' => 'published']);
        Inventory::create(['product_id' => $own->id, 'quantity' => 3]);
        Inventory::create(['product_id' => $other->id, 'quantity' => 3]);
        $token = $school->json('token');
        $this->withToken($token)->withHeader('X-Store-Host', 'catalog-a.platform.test')->getJson('/api/v1/school/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Own book');
        $address = ['recipient' => 'School Contact', 'line1' => 'Main Road', 'city' => 'Lahore', 'country' => 'PK'];
        $this->withToken($token)->withHeader('X-Store-Host', 'catalog-a.platform.test')->postJson('/api/v1/school/orders', ['items' => [['product_id' => $other->id, 'quantity' => 1]], 'shipping_address' => $address])->assertNotFound();
        $this->withToken($token)->withHeader('X-Store-Host', 'catalog-a.platform.test')->postJson('/api/v1/school/orders', ['vendor_id' => $vendorB->id, 'items' => [['product_id' => $own->id, 'quantity' => 2]], 'shipping_address' => $address])->assertCreated()->assertJsonPath('data.total', '100.00');
        $this->assertDatabaseHas('orders', ['customer_id' => $user->id]);
    }

    public function test_school_and_vendor_management_endpoints_reject_unauthenticated_and_cross_vendor_users(): void
    {
        $vendorAOwner = User::factory()->create();
        $vendorBOwner = User::factory()->create();
        $vendorA = $this->store($vendorAOwner, 'vendor-school-a');
        $vendorB = $this->store($vendorBOwner, 'vendor-school-b');
        $this->schoolDomain($vendorA, 'vendor-school-a.platform.test');
        $this->getJson('/api/v1/school/me', ['X-Store-Host' => 'vendor-school-a.platform.test'])->assertUnauthorized();
        $school = $this->registerSchool('vendor-school-a.platform.test', 'one@school.test')->assertCreated();
        $schoolUser = User::where('email', 'one@school.test')->firstOrFail();
        $this->actingAs($schoolUser)->getJson("/api/v1/vendor/{$vendorB->slug}/schools")->assertForbidden();
        $this->actingAs($vendorBOwner)->getJson("/api/v1/vendor/{$vendorA->slug}/schools")->assertForbidden();
        $this->actingAs($vendorAOwner)->getJson("/api/v1/vendor/{$vendorA->slug}/schools")->assertOk()->assertJsonPath('data.0.id', $school->json('data.school.id'));
        $this->actingAs($vendorAOwner)->getJson("/api/v1/vendor/{$vendorA->slug}/products")->assertOk();
        $this->actingAs($vendorAOwner)->getJson("/api/v1/vendor/{$vendorA->slug}/orders")->assertOk();
        $otherProduct = Product::create(['vendor_id' => $vendorB->id, 'name' => 'Vendor B product', 'slug' => 'vendor-b-product', 'price' => 30, 'currency' => 'PKR', 'status' => 'published']);
        $this->actingAs($vendorAOwner)->deleteJson("/api/v1/vendor/{$vendorA->slug}/products/{$otherProduct->id}")->assertNotFound();
        $this->actingAs($vendorAOwner)->patchJson("/api/v1/vendor/{$vendorA->slug}/orders/99999", ['status' => 'shipped'])->assertNotFound();
        $this->actingAs($vendorAOwner)->patchJson("/api/v1/vendor/{$vendorA->slug}/schools/{$schoolUser->school->id}", ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $schoolUser->id, 'is_active' => false]);
    }
}
