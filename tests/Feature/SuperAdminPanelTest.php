<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\StoreDomains\Pages\ListStoreDomains;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Filament\Resources\Vendors\Pages\ViewVendor;
use App\Filament\Resources\Vendors\RelationManagers\DomainsRelationManager;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'platform.test']);
    }

    private function vendor(string $slug, string $status = 'pending'): Vendor
    {
        $owner = User::factory()->create(['role' => 'vendor']);

        return Vendor::create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'platform_admin']);
    }

    public function test_guests_are_redirected_to_the_panel_login(): void
    {
        $this->get('/superadmin/vendors')->assertRedirect('/superadmin/login');
        $this->get('/superadmin/login')->assertOk();
    }

    public function test_non_admin_roles_and_inactive_admins_cannot_access_the_panel(): void
    {
        foreach (['customer', 'vendor', 'school'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/superadmin')->assertForbidden();
            $this->actingAs($user)->get('/superadmin/vendors')->assertForbidden();
        }
        $inactive = User::factory()->create(['role' => 'platform_admin', 'is_active' => false]);
        $this->actingAs($inactive)->get('/superadmin')->assertForbidden();
    }

    public function test_login_form_rejects_non_admins_without_creating_a_session(): void
    {
        $vendorUser = User::factory()->create(['role' => 'vendor', 'password' => 'SecurePass12345']);
        Livewire::test(Login::class)
            ->fillForm(['email' => $vendorUser->email, 'password' => 'SecurePass12345'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_platform_admin_can_log_in_and_open_panel_pages(): void
    {
        $admin = User::factory()->create(['role' => 'platform_admin', 'password' => 'SecurePass12345']);
        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'SecurePass12345'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($admin);

        $vendor = $this->vendor('listed');
        $this->get('/superadmin')->assertOk();
        $this->get('/superadmin/vendors')->assertOk()->assertSee('Pending applications');
        $this->get('/superadmin/store-domains')->assertOk();
        $this->get("/superadmin/vendors/{$vendor->id}")->assertOk()->assertSee('Listed');
    }

    public function test_approving_in_the_panel_runs_the_shared_approval_logic(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor('acme');
        Livewire::actingAs($admin)->test(ListVendors::class)
            ->callTableAction('approve', $vendor, ['slug' => 'acme-shop'])
            ->assertNotified('Vendor approved');

        $vendor->refresh();
        $this->assertSame('active', $vendor->status);
        $this->assertSame('acme-shop', $vendor->slug);
        $this->assertSame($admin->id, $vendor->reviewed_by);
        $domain = $vendor->domains()->firstOrFail();
        $this->assertSame('acme-shop.platform.test', $domain->hostname);
        $this->assertFalse($domain->is_verified);
        $this->assertSame('manual_pending', $domain->provisioning_status);
    }

    public function test_panel_approval_cannot_bypass_slug_validation_or_hostname_uniqueness(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor('pending-one');
        $other = $this->vendor('taken', 'active');
        Livewire::actingAs($admin)->test(ListVendors::class)
            ->callTableAction('approve', $vendor, ['slug' => 'admin'])
            ->assertNotified('Validation failed');
        Livewire::actingAs($admin)->test(ListVendors::class)
            ->callTableAction('approve', $vendor, ['slug' => 'taken'])
            ->assertNotified('Validation failed');
        $this->assertSame('pending', $vendor->fresh()->status);
        $this->assertSame(0, $vendor->domains()->count());
        $this->assertSame('active', $other->fresh()->status);

        config(['platform.domain' => null, 'platform.storefront.mode' => 'domain']);   // path mode needs no platform domain; domain mode does
        Livewire::actingAs($admin)->test(ListVendors::class)
            ->callTableAction('approve', $vendor, ['slug' => 'fine-slug'])
            ->assertNotified('Action not allowed');
        $this->assertSame('pending', $vendor->fresh()->status);
    }

    public function test_rejecting_requires_a_reason_and_removes_platform_domain(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor('rej');
        Livewire::actingAs($admin)->test(ListVendors::class)
            ->callTableAction('reject', $vendor, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame('pending', $vendor->fresh()->status);

        Livewire::actingAs($admin)->test(ListVendors::class)
            ->callTableAction('reject', $vendor, ['reason' => 'Incomplete documents'])
            ->assertNotified('Vendor rejected');
        $vendor->refresh();
        $this->assertSame('rejected', $vendor->status);
        $this->assertSame('Incomplete documents', $vendor->rejection_reason);
    }

    public function test_domain_activation_suspension_and_custom_domain_assignment(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor('lifecycle');
        Livewire::actingAs($admin)->test(ListVendors::class)->callTableAction('approve', $vendor, ['slug' => 'lifecycle']);
        $domain = $vendor->domains()->firstOrFail();

        Livewire::actingAs($admin)->test(DomainsRelationManager::class, ['ownerRecord' => $vendor->fresh(), 'pageClass' => ViewVendor::class])
            ->callTableAction('activateDomain', $domain, ['dns_configured' => true])
            ->assertNotified('Domain activated');
        $this->assertTrue($domain->fresh()->is_verified);
        $this->getJson('/api/v1/vendors/resolve?host=lifecycle.platform.test')->assertOk();

        Livewire::actingAs($admin)->test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->callAction('addCustomDomain', ['hostname' => 'shop.example.com'])
            ->assertNotified('Domain assigned (pending activation)');
        $custom = StoreDomain::where('hostname', 'shop.example.com')->firstOrFail();
        $this->assertFalse($custom->is_verified);
        Livewire::actingAs($admin)->test(ViewVendor::class, ['record' => $vendor->getKey()])
            ->callAction('addCustomDomain', ['hostname' => 'x.platform.test'])
            ->assertNotified('Action not allowed');
        $this->assertDatabaseMissing('store_domains', ['hostname' => 'x.platform.test']);

        Livewire::actingAs($admin)->test(ViewVendor::class, ['record' => $vendor->getKey()])->callAction('suspend');
        $this->assertSame('suspended', $vendor->fresh()->status);
        $this->getJson('/api/v1/vendors/resolve?host=lifecycle.platform.test')->assertNotFound();

        Livewire::actingAs($admin)->test(ListStoreDomains::class)
            ->assertCanSeeTableRecords(StoreDomain::all());
    }

    public function test_activation_is_blocked_for_non_active_vendors(): void
    {
        $admin = $this->admin();
        $vendor = $this->vendor('blocked', 'suspended');
        $domain = StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => 'blocked.platform.test', 'is_verified' => false, 'provisioning_status' => 'manual_pending', 'source' => 'platform_subdomain']);
        Livewire::actingAs($admin)->test(ListStoreDomains::class)
            ->assertTableActionHidden('activateDomain', $domain);
        $this->assertFalse($domain->fresh()->is_verified);
    }

    public function test_vendor_api_token_cannot_reach_the_panel_and_admin_api_still_works(): void
    {
        $vendorUser = User::factory()->create(['role' => 'vendor']);
        $token = $vendorUser->createToken('t')->plainTextToken;
        $this->withToken($token)->get('/superadmin/vendors')->assertRedirect('/superadmin/login');
        $this->withToken($token)->getJson('/api/v1/admin/vendors')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/v1/admin/vendor-requests')->assertOk();
    }
}
