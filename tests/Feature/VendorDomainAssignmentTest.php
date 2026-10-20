<?php

namespace Tests\Feature;

use App\Filament\Resources\Vendors\Pages\ViewVendor;
use App\Filament\Resources\Vendors\RelationManagers\DomainsRelationManager;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Super Admin domain assignment: the one-subdomain-per-vendor model plus custom domains, on the existing lifecycle (inactive until activated). */
class VendorDomainAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'platform.test']);
    }

    private function vendor(string $slug, string $status = 'active'): Vendor
    {
        return Vendor::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'platform_admin']);
    }

    private function page(Vendor $vendor)
    {
        return Livewire::actingAs($this->admin())->test(ViewVendor::class, ['record' => $vendor->getKey()]);
    }

    private function domains(Vendor $vendor)
    {
        return Livewire::actingAs($this->admin())->test(DomainsRelationManager::class, ['ownerRecord' => $vendor, 'pageClass' => ViewVendor::class]);
    }

    public function test_an_approved_vendor_without_domains_can_be_given_its_platform_subdomain(): void
    {
        $vendor = $this->vendor('007-butt');
        $other = $this->vendor('other');
        $this->assertSame(0, $vendor->domains()->count());                 // approved in path mode earlier: no mapping exists

        $this->page($vendor)->assertActionVisible('assignPlatformSubdomain')->mountAction('assignPlatformSubdomain')->assertActionMounted('assignPlatformSubdomain')
            ->callMountedAction()->assertNotified('Subdomain assigned (pending activation)');

        $domain = $vendor->domains()->firstOrFail();
        $this->assertSame('007-butt.platform.test', $domain->hostname);
        $this->assertSame($vendor->id, $domain->vendor_id);
        $this->assertSame('platform_subdomain', $domain->source);
        $this->assertTrue($domain->is_primary);
        $this->assertFalse($domain->is_verified);                           // never live on creation
        $this->assertSame('manual_pending', $domain->provisioning_status);
        $this->assertSame(0, $other->domains()->count());

        // Inactive until an admin activates it; then it resolves to this vendor and to nobody else.
        $this->withHeader('X-Store-Host', '007-butt.platform.test')->getJson('/api/v1/products')->assertNotFound();
        $this->domains($vendor)->callTableAction('activateDomain', $domain, ['dns_configured' => true])->assertNotified('Domain activated');
        $this->flushHeaders();
        $this->withHeader('X-Store-Host', '007-butt.platform.test')->getJson('/api/v1/vendors/resolve?slug=007-butt')->assertOk();
        $this->withHeader('X-Store-Host', '007-butt.platform.test')->getJson('/api/v1/vendors/resolve?slug=other')->assertNotFound();
        $this->page($vendor)->assertActionHidden('assignPlatformSubdomain');   // one subdomain per vendor
    }

    public function test_the_domains_table_offers_the_assignment_actions_in_its_empty_state(): void
    {
        $vendor = $this->vendor('007-butt');
        $this->domains($vendor)->assertSee('No store domains')->assertTableActionExists('assignPlatformSubdomain')->assertTableActionExists('addCustomDomain')
            ->callTableAction('assignPlatformSubdomain')->assertNotified('Subdomain assigned (pending activation)');
        $this->assertSame(['007-butt.platform.test'], $vendor->domains()->pluck('hostname')->all());
    }

    public function test_the_platform_subdomain_cannot_be_assigned_without_a_platform_domain_or_for_inactive_or_duplicate_cases(): void
    {
        $vendor = $this->vendor('007-butt');
        config(['platform.domain' => null]);
        $this->page($vendor)->callAction('assignPlatformSubdomain')->assertNotified('Action not allowed');
        $this->assertSame(0, StoreDomain::count());

        config(['platform.domain' => 'platform.test']);
        StoreDomain::create(['vendor_id' => $this->vendor('squatter')->id, 'hostname' => '007-butt.platform.test', 'is_verified' => false, 'provisioning_status' => 'manual_pending', 'source' => 'custom']);
        $this->page($vendor)->callAction('assignPlatformSubdomain')->assertNotified('Action not allowed');   // taken by another vendor
        $this->assertSame(0, $vendor->domains()->count());

        $pending = $this->vendor('waiting', 'pending');
        $this->page($pending)->assertActionHidden('assignPlatformSubdomain')->assertActionHidden('addCustomDomain');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(VendorReviewService::class)->assignPlatformSubdomain($pending);
    }

    public function test_the_custom_domain_form_validates_the_hostname_and_saves_it_against_the_right_vendor(): void
    {
        $vendor = $this->vendor('007-butt');
        $other = $this->vendor('other');
        StoreDomain::create(['vendor_id' => $other->id, 'hostname' => 'taken.example.com', 'is_verified' => true, 'provisioning_status' => 'active', 'source' => 'custom']);

        foreach (['not a host', 'localhost', '-bad.example.com', 'a'] as $bad) {
            $this->page($vendor)->callAction('addCustomDomain', ['hostname' => $bad])->assertHasActionErrors(['hostname']);
        }
        $this->page($vendor)->callAction('addCustomDomain', ['hostname' => 'taken.example.com'])->assertHasActionErrors(['hostname']);
        $this->page($vendor)->callAction('addCustomDomain', ['hostname' => 'sub.platform.test'])->assertNotified('Action not allowed');   // platform subdomains have their own action
        $this->page($vendor)->callAction('addCustomDomain', ['hostname' => ''])->assertHasActionErrors(['hostname' => 'required']);
        $this->assertSame(0, $vendor->domains()->count());

        $this->domains($vendor)->callTableAction('addCustomDomain', null, ['hostname' => 'shop.example.com'])->assertNotified('Domain assigned (pending activation)');
        $domain = $vendor->domains()->firstOrFail();
        $this->assertSame('shop.example.com', $domain->hostname);
        $this->assertSame('custom', $domain->source);
        $this->assertFalse($domain->is_verified);
        $this->assertSame('manual_pending', $domain->provisioning_status);
        $this->assertSame(1, StoreDomain::where('hostname', 'shop.example.com')->count());
        $this->assertSame(0, $other->domains()->where('hostname', 'shop.example.com')->count());
        $this->withHeader('X-Store-Host', 'shop.example.com')->getJson('/api/v1/products')->assertNotFound();   // not live until activated
    }

    public function test_pasted_urls_are_normalised_and_the_service_rules_still_apply(): void
    {
        $this->assertSame('shop.example.com', VendorReviewService::normalizeHostname('  https://Shop.Example.com:443/path?x=1#y '));
        $this->assertSame('shop.example.com', VendorReviewService::normalizeHostname('shop.example.com.'));
        $vendor = $this->vendor('007-butt');
        $domain = app(VendorReviewService::class)->addCustomDomain($vendor, 'HTTPS://Shop.Example.com/');
        $this->assertSame('shop.example.com', $domain->hostname);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(VendorReviewService::class)->addCustomDomain($this->vendor('rival'), 'shop.example.com');
    }

    public function test_only_platform_admins_can_use_the_panel_actions(): void
    {
        $vendor = $this->vendor('007-butt');
        $owner = $vendor->owner;
        $this->actingAs($owner)->get('/superadmin/vendors/'.$vendor->id)->assertForbidden();   // the panel itself refuses non-admins before any action exists
        $this->actingAs($this->admin())->get('/superadmin/vendors/'.$vendor->id)->assertOk();
        $this->assertSame(0, StoreDomain::count());
    }
}
