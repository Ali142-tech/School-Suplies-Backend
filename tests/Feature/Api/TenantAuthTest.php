<?php

namespace Tests\Feature\Api;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Strict tenant authentication: accounts and tokens belong to exactly one storefront (or to the central platform) and nowhere else. */
class TenantAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'SecurePass1234';

    private const ADDRESS = ['recipient' => 'Buyer', 'line1' => '1 Main St', 'city' => 'Lahore', 'country' => 'PK'];

    private Vendor $ali;

    private Vendor $bilal;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.storefront.mode' => 'path', 'platform.domain' => null]);
        $this->ali = $this->vendor('ali-butt');
        $this->bilal = $this->vendor('bilal');
    }

    private function vendor(string $slug, string $status = 'active'): Vendor
    {
        $owner = User::factory()->create(['role' => 'vendor', 'password' => self::PASSWORD]);
        $vendor = Vendor::create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
        $vendor->memberships()->create(['user_id' => $owner->id, 'role' => 'manager']);

        return $vendor;
    }

    /** A request with a clean header set: optionally bearer-authenticated, optionally inside a storefront (path mode slug). */
    private function req(?string $token = null, ?string $slug = null, ?string $host = null)
    {
        $this->flushHeaders();
        Cache::flush();   // rate-limit buckets are not what these tests are about
        $this->app['auth']->forgetGuards();   // every request resolves its own user, as in production
        $t = $this;
        if ($token) {
            $t = $t->withToken($token);
        }
        if ($slug) {
            $t = $t->withHeader('X-Store-Slug', $slug);
        }
        if ($host) {
            $t = $t->withHeader('X-Store-Host', $host);
        }

        return $t;
    }

    private function customer(?Vendor $vendor = null, string $email = null): User
    {
        return User::factory()->create(['role' => 'customer', 'vendor_id' => $vendor?->id, 'email' => $email ?? fake()->unique()->safeEmail(), 'password' => self::PASSWORD]);
    }

    private function login(User $user, ?string $slug = null)
    {
        return $this->req(null, $slug)->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    private function register(string $email, ?string $slug, array $extra = [])
    {
        return $this->req(null, $slug)->postJson('/api/v1/auth/register', ['name' => 'Buyer', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, ...$extra]);
    }

    private function abilitiesOf(string $plain): array
    {
        return PersonalAccessToken::findToken($plain)->abilities;
    }

    private function product(Vendor $vendor): Product
    {
        $p = Product::create(['vendor_id' => $vendor->id, 'name' => "{$vendor->slug} item", 'slug' => "{$vendor->slug}-item", 'description' => 'x', 'price' => 100, 'currency' => 'PKR', 'status' => 'published', 'type' => 'supply']);
        Inventory::create(['product_id' => $p->id, 'quantity' => 10]);

        return $p;
    }

    // ---- registration -------------------------------------------------------------------------------------------------

    public function test_customer_registration_binds_the_account_to_the_storefront_vendor_on_the_server(): void
    {
        $r = $this->register('a@example.test', 'ali-butt', ['vendor_id' => $this->bilal->id, 'vendor_slug' => 'bilal', 'role' => 'platform_admin'])->assertCreated();
        $user = User::where('email', 'a@example.test')->firstOrFail();
        $this->assertSame($this->ali->id, $user->vendor_id);               // forged vendor id / slug ignored
        $this->assertSame('customer', $user->role);                          // forged role ignored
        $this->assertSame(['tenant:'.$this->ali->id], $this->abilitiesOf($r->json('token')));

        $this->register('b@example.test', 'bilal')->assertCreated();
        $this->assertSame($this->bilal->id, User::where('email', 'b@example.test')->value('vendor_id'));

        $this->register('c@example.test', null)->assertCreated();            // central marketplace account
        $this->assertNull(User::where('email', 'c@example.test')->value('vendor_id'));
    }

    public function test_registration_on_an_unknown_inactive_or_malformed_store_fails_closed(): void
    {
        $this->vendor('waiting', 'pending');
        foreach (['nobody', 'waiting', 'Bad Slug'] as $slug) {
            $this->register('x@example.test', $slug)->assertNotFound();
        }
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_an_existing_email_cannot_join_another_vendor_and_the_error_is_uniform(): void
    {
        $this->register('dup@example.test', 'ali-butt')->assertCreated();
        $same = $this->register('dup@example.test', 'ali-butt')->assertStatus(422);
        $other = $this->register('dup@example.test', 'bilal')->assertStatus(422);
        $central = $this->register('dup@example.test', null)->assertStatus(422);
        $this->assertSame($same->json('errors.email'), $other->json('errors.email'));   // nothing reveals which tenant owns the account
        $this->assertSame($same->json('errors.email'), $central->json('errors.email'));
        $this->assertSame(1, User::where('email', 'dup@example.test')->count());
        $this->assertSame($this->ali->id, User::where('email', 'dup@example.test')->value('vendor_id'));
    }

    public function test_school_registration_records_the_vendor_and_cannot_reuse_an_email_of_another_store(): void
    {
        $body = ['name' => 'Contact', 'email' => 'school@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'school_name' => 'North', 'vendor_id' => $this->bilal->id];
        $r = $this->req(null, 'ali-butt')->postJson('/api/v1/school/register', $body)->assertCreated();
        $user = User::where('email', 'school@example.test')->firstOrFail();
        $this->assertSame($this->ali->id, $user->vendor_id);
        $this->assertSame($this->ali->id, $user->school->vendor_id);
        $this->assertSame(['tenant:'.$this->ali->id], $this->abilitiesOf($r->json('token')));
        $this->req(null, 'bilal')->postJson('/api/v1/school/register', $body)->assertStatus(422);
        $this->req(null, 'bilal')->postJson('/api/v1/school/login', ['email' => 'school@example.test', 'password' => self::PASSWORD])->assertStatus(422);
        $this->assertSame(1, PersonalAccessToken::count());
    }

    // ---- login --------------------------------------------------------------------------------------------------------

    public function test_a_customer_can_sign_in_only_on_their_own_storefront(): void
    {
        $user = $this->customer($this->ali, 'cust@example.test');
        $ok = $this->login($user, 'ali-butt')->assertOk();
        $this->assertSame(['tenant:'.$this->ali->id], $this->abilitiesOf($ok->json('token')));
        $before = PersonalAccessToken::count();

        $generic = $this->login($user, 'bilal')->assertStatus(422)->json('message');
        $this->assertSame($generic, $this->login($user, null)->assertStatus(422)->json('message'));      // central sign-in is refused too
        $wrongPassword = $this->req(null, 'ali-butt')->postJson('/api/v1/auth/login', ['email' => 'cust@example.test', 'password' => 'nope-nope-nope'])->assertStatus(422)->json('message');
        $unknown = $this->req(null, 'ali-butt')->postJson('/api/v1/auth/login', ['email' => 'ghost@example.test', 'password' => 'nope-nope-nope'])->assertStatus(422)->json('message');
        $this->assertSame($generic, $wrongPassword);
        $this->assertSame($generic, $unknown);                                                          // existence is not revealed
        $this->assertSame($before, PersonalAccessToken::count());                                       // no token for any refusal
    }

    public function test_existing_central_customers_keep_working_centrally_but_not_on_storefronts(): void
    {
        $legacy = $this->customer(null, 'legacy@example.test');                  // an account created before users.vendor_id existed
        $token = $this->login($legacy)->assertOk()->json('token');
        $this->assertSame(['central'], $this->abilitiesOf($token));
        $this->login($legacy, 'ali-butt')->assertStatus(422);
        $this->req($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->req($token, 'ali-butt')->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_vendor_owner_signs_in_only_inside_their_own_vendor(): void
    {
        $ali = $this->ali->owner;
        $own = $this->login($ali, 'ali-butt')->assertOk();
        $this->assertSame(['tenant:'.$this->ali->id], $this->abilitiesOf($own->json('token')));
        $before = PersonalAccessToken::count();
        $this->login($ali, 'bilal')->assertStatus(422);
        $this->assertSame($before, PersonalAccessToken::count());                // no cross-tenant session was created
        $this->assertSame(['central'], $this->abilitiesOf($this->login($ali)->assertOk()->json('token')));   // the central vendor portal sign-in

        // a staff member of Bilal's store may sign in there, not on Ali's
        $staff = $this->customer(null, 'staff@example.test');
        $this->bilal->memberships()->create(['user_id' => $staff->id, 'role' => 'staff']);
        $this->login($staff, 'bilal')->assertOk();
        $this->login($staff, 'ali-butt')->assertStatus(422);
    }

    public function test_super_admin_authenticates_centrally_only(): void
    {
        $admin = User::factory()->create(['role' => 'platform_admin', 'password' => self::PASSWORD]);
        $token = $this->login($admin)->assertOk()->json('token');
        $this->assertSame(['central'], $this->abilitiesOf($token));
        $this->req($token)->getJson('/api/v1/admin/overview')->assertOk();
        $this->login($admin, 'ali-butt')->assertStatus(422);
        $this->req($token, 'ali-butt')->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->req($token, 'ali-butt')->getJson('/api/v1/auth/me')->assertForbidden();
    }

    // ---- tokens, spoofing, API access ---------------------------------------------------------------------------------

    public function test_a_tenant_token_works_only_on_its_own_storefront(): void
    {
        $token = $this->login($this->customer($this->ali), 'ali-butt')->assertOk()->json('token');
        $this->req($token, 'ali-butt')->getJson('/api/v1/auth/me')->assertOk();
        $this->req($token, 'bilal')->getJson('/api/v1/auth/me')->assertForbidden();            // another tenant
        $this->req($token)->getJson('/api/v1/auth/me')->assertForbidden();                      // central: no store header
        $this->req($token)->getJson('/api/v1/orders')->assertForbidden();
        $this->req($token, 'nobody')->getJson('/api/v1/auth/me')->assertNotFound();             // spoofed unknown tenant fails closed
        $this->req($token, 'ali-butt', 'unknown.example.test')->getJson('/api/v1/auth/me')->assertNotFound();
        $this->req($token, 'bilal', 'unknown.example.test')->getJson('/api/v1/addresses')->assertNotFound();
        $this->req($token, 'bilal')->getJson('/api/v1/addresses')->assertForbidden();
        $this->req($token, 'bilal')->postJson('/api/v1/auth/logout')->assertForbidden();
    }

    public function test_legacy_wildcard_tokens_count_as_central_tokens(): void
    {
        $user = $this->customer($this->ali);
        $legacy = $user->createToken('old')->plainTextToken;                                     // abilities ['*'], as issued before this change
        $this->req($legacy, 'ali-butt')->getJson('/api/v1/auth/me')->assertForbidden();
        $this->req($legacy, null)->getJson('/api/v1/auth/me')->assertForbidden();                // and the account itself is a storefront account
        $central = $this->customer();
        $this->req($central->createToken('old')->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_cross_tenant_purchases_are_refused_even_with_forged_ids_and_headers(): void
    {
        $pa = $this->product($this->ali);
        $pb = $this->product($this->bilal);
        $user = $this->customer($this->ali);
        $token = $this->login($user, 'ali-butt')->json('token');
        $lines = fn ($p) => ['vendor_id' => $this->bilal->id, 'items' => [['product_id' => $p->id, 'quantity' => 1, 'vendor_id' => $this->bilal->id]], 'shipping_address' => self::ADDRESS];

        $this->req($token, 'bilal')->postJson('/api/v1/checkout', $lines($pb))->assertForbidden();
        $this->req($token, 'ali-butt')->postJson('/api/v1/checkout', $lines($pb))->assertStatus(422);
        $this->req($token, 'ali-butt')->postJson('/api/v1/cart/items', ['product_id' => $pb->id, 'quantity' => 1])->assertStatus(422);
        $order = $this->req($token, 'ali-butt')->postJson('/api/v1/checkout', $lines($pa))->assertCreated()->json('data');
        $this->assertSame($this->ali->id, \App\Models\OrderItem::where('order_id', $order['id'])->value('vendor_id'));

        // orders are visible only inside their own storefront, and only to their owner
        $this->req($token, 'ali-butt')->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data.data');
        $this->req($token, 'ali-butt')->getJson("/api/v1/orders/{$order['id']}")->assertOk();
        $bilalCustomer = $this->customer($this->bilal);
        $bilalToken = $this->login($bilalCustomer, 'bilal')->json('token');
        $this->req($bilalToken, 'bilal')->getJson("/api/v1/orders/{$order['id']}")->assertNotFound();
        $this->req($bilalToken, 'bilal')->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data.data');
        $this->req($bilalToken, 'ali-butt')->getJson("/api/v1/orders/{$order['id']}")->assertForbidden();
    }

    public function test_vendor_dashboard_apis_are_bound_to_the_same_vendor_in_every_context(): void
    {
        $owner = $this->ali->owner;
        $tenantToken = $this->login($owner, 'ali-butt')->json('token');
        $centralToken = $this->login($owner)->json('token');

        $this->req($tenantToken, 'ali-butt')->getJson('/api/v1/vendor/ali-butt/dashboard')->assertOk();
        $this->req($tenantToken, 'ali-butt')->getJson('/api/v1/vendor/bilal/dashboard')->assertForbidden();   // not a member, and not this tenant
        $this->req($tenantToken)->getJson('/api/v1/vendor/ali-butt/dashboard')->assertForbidden();             // a store session is not a portal session
        $this->req($tenantToken, 'bilal')->getJson('/api/v1/vendor/ali-butt/dashboard')->assertForbidden();
        $this->req($centralToken)->getJson('/api/v1/vendor/ali-butt/dashboard')->assertOk();
        $this->req($centralToken)->getJson('/api/v1/vendor/bilal/dashboard')->assertForbidden();               // membership still decides
        $this->req($centralToken, 'ali-butt')->getJson('/api/v1/vendor/ali-butt/dashboard')->assertForbidden();   // a central token never works on a storefront
    }

    public function test_school_sessions_are_confined_to_their_store(): void
    {
        $r = $this->req(null, 'ali-butt')->postJson('/api/v1/school/register', ['name' => 'C', 'email' => 's@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'school_name' => 'North'])->assertCreated();
        $token = $r->json('token');
        $this->req($token, 'ali-butt')->getJson('/api/v1/school/me')->assertOk();
        $this->req($token, 'bilal')->getJson('/api/v1/school/me')->assertForbidden();
        $this->req($token, 'ali-butt')->getJson('/api/v1/auth/me')->assertOk();
        $this->req($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_the_optional_auth_route_ignores_a_token_from_another_context(): void
    {
        $token = $this->login($this->customer($this->ali), 'ali-butt')->json('token');
        $quote = ['contact_name' => 'Sam', 'email' => 'sam@example.test', 'product_type' => 'Flyers', 'quantity' => 100];
        $id = $this->req($token, 'bilal')->postJson('/api/v1/printing/quotes', $quote)->assertCreated()->json('data.id');
        $this->assertNull(\App\Models\QuotationRequest::find($id)->user_id);                     // treated as a guest, never attributed to the Ali account
    }

    // ---- sessions: logout and password reset ----------------------------------------------------------------------------

    public function test_logout_revokes_only_the_presented_tenant_token(): void
    {
        $user = $this->customer($this->ali);
        $one = $this->login($user, 'ali-butt')->json('token');
        $two = $this->login($user, 'ali-butt')->json('token');
        $this->req($one, 'ali-butt')->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->req($one, 'ali-butt')->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->req($two, 'ali-butt')->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_password_reset_links_are_only_sent_in_the_accounts_own_context_and_point_at_that_store(): void
    {
        Notification::fake();
        $user = $this->customer($this->ali, 'reset@example.test');
        config(['platform.storefront.base_url' => 'https://school-suplies.vercel.app']);

        $msg = $this->req(null, 'ali-butt')->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.test'])->assertOk()->json('message');
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
            $url = (new \ReflectionClass(ResetPassword::class))->getMethod('resetUrl');
            $this->assertStringStartsWith('https://school-suplies.vercel.app/vendor/ali-butt/reset-password?', $url->invoke($n, $user));

            return true;
        });
        // another store and the central site answer identically but send nothing
        Notification::fake();
        $this->assertSame($msg, $this->req(null, 'bilal')->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.test'])->json('message'));
        $this->assertSame($msg, $this->req()->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.test'])->json('message'));
        $this->assertSame($msg, $this->req(null, 'ali-butt')->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.test'])->json('message'));
        Notification::assertNothingSent();
    }

    public function test_password_reset_works_in_the_right_context_revokes_tokens_and_is_refused_elsewhere(): void
    {
        $user = $this->customer($this->ali, 'reset@example.test');
        $token = $this->login($user, 'ali-butt')->json('token');
        $reset = Password::createToken($user);
        $body = ['token' => $reset, 'email' => 'reset@example.test', 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'];

        $wrong = $this->req(null, 'bilal')->postJson('/api/v1/auth/password/reset', $body)->assertStatus(422)->json('message');
        $this->req()->postJson('/api/v1/auth/password/reset', $body)->assertStatus(422);
        $bad = $this->req(null, 'ali-butt')->postJson('/api/v1/auth/password/reset', [...$body, 'token' => 'wrong'])->assertStatus(422)->json('message');
        $this->assertSame($bad, $wrong);                                                         // indistinguishable from an invalid token
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));

        $this->req(null, 'ali-butt')->postJson('/api/v1/auth/password/reset', $body)->assertOk();
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->count());   // every session was revoked
        $this->assertSame($this->ali->id, $user->fresh()->vendor_id);                            // ownership unchanged
        $this->app['auth']->forgetGuards();
        $this->req($token, 'ali-butt')->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->req(null, 'ali-butt')->postJson('/api/v1/auth/login', ['email' => 'reset@example.test', 'password' => 'BrandNewPass99'])->assertOk();
    }

    // ---- membership, domains, path mode, data safety ------------------------------------------------------------------

    public function test_store_managers_cannot_pull_another_stores_accounts_into_their_team(): void
    {
        $aliCustomer = $this->customer($this->ali, 'ali-customer@example.test');
        $managerToken = $this->login($this->bilal->owner, 'bilal')->json('token');
        $this->req($managerToken, 'bilal')->postJson('/api/v1/vendor/bilal/members', ['email' => 'ali-customer@example.test', 'role' => 'staff'])->assertStatus(422);
        $this->assertSame(0, $this->bilal->memberships()->where('user_id', $aliCustomer->id)->count());
        $admin = User::factory()->create(['role' => 'platform_admin']);
        $this->req($managerToken, 'bilal')->postJson('/api/v1/vendor/bilal/members', ['email' => $admin->email, 'role' => 'staff'])->assertStatus(422);
    }

    public function test_domain_mode_binds_tokens_to_the_verified_host_and_still_fails_closed(): void
    {
        config(['platform.storefront.mode' => 'domain', 'platform.domain' => 'platform.test']);
        StoreDomain::create(['vendor_id' => $this->ali->id, 'hostname' => 'ali.platform.test', 'is_verified' => true, 'provisioning_status' => 'active', 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => now()]);
        StoreDomain::create(['vendor_id' => $this->bilal->id, 'hostname' => 'bilal.platform.test', 'is_verified' => false, 'provisioning_status' => 'manual_pending', 'source' => 'platform_subdomain', 'is_primary' => true]);

        $r = $this->req(null, null, 'ali.platform.test')->postJson('/api/v1/auth/register', ['name' => 'B', 'email' => 'h@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertCreated();
        $this->assertSame($this->ali->id, User::where('email', 'h@example.test')->value('vendor_id'));
        $this->req($r->json('token'), null, 'ali.platform.test')->getJson('/api/v1/auth/me')->assertOk();
        $this->req($r->json('token'), null, 'bilal.platform.test')->getJson('/api/v1/auth/me')->assertNotFound();   // unverified mapping: no tenant
        $this->req($r->json('token'), 'ali-butt')->getJson('/api/v1/auth/me')->assertNotFound();                      // slug claims are not accepted in domain mode
        $this->req($r->json('token'))->getJson('/api/v1/auth/me')->assertForbidden();
        $this->req(null, null, 'bilal.platform.test')->postJson('/api/v1/auth/login', ['email' => 'h@example.test', 'password' => self::PASSWORD])->assertNotFound();
    }

    public function test_path_mode_ali_butt_storefront_keeps_working_end_to_end(): void
    {
        $p = $this->product($this->ali);
        $user = $this->customer($this->ali);
        $token = $this->login($user, 'ali-butt')->assertOk()->json('token');
        $this->req(null, 'ali-butt')->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
        $this->req($token, 'ali-butt')->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => 2])->assertCreated();
        $this->req($token, 'ali-butt')->postJson('/api/v1/checkout', ['items' => [['product_id' => $p->id, 'quantity' => 2]], 'shipping_address' => self::ADDRESS])->assertCreated();
        $this->req(null, 'ali-butt')->getJson('/api/v1/vendors/resolve?slug=ali-butt')->assertOk()->assertJsonPath('data.slug', 'ali-butt');
    }

    public function test_the_migration_is_additive_nullable_and_reversible_without_touching_existing_rows(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'vendor_id'));
        $existing = User::factory()->create(['role' => 'customer', 'email' => 'old@example.test']);
        $this->assertNull($existing->fresh()->vendor_id);                                        // existing rows are never assigned a vendor

        // deleting a vendor never deletes its customers
        $this->customer($this->bilal, 'keep@example.test');
        $this->bilal->forceDelete();
        $this->assertNull(User::where('email', 'keep@example.test')->firstOrFail()->vendor_id);

        // rollback removes only the column
        $migration = require database_path('migrations/2026_10_20_100000_add_vendor_id_to_users_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'vendor_id'));
        $this->assertSame('old@example.test', User::where('email', 'old@example.test')->value('email'));
        $this->assertSame('keep@example.test', User::where('email', 'keep@example.test')->value('email'));
    }
}
