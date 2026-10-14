<?php

namespace Tests\Feature\Api;

use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Milestone 4: what a visitor on a vendor's own hostname can and cannot see through the public site API. */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function vendor(string $slug, string $host, string $status = 'active', bool $verified = true, string $provisioning = 'active'): Vendor
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $vendor = Vendor::create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
        StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => $host, 'is_verified' => $verified, 'provisioning_status' => $provisioning, 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => $verified ? now() : null]);

        return $vendor;
    }

    private function page(Vendor $vendor, string $slug, string $status = 'published', string $kind = 'custom', string $heading = 'Hello'): VendorPage
    {
        $page = new VendorPage(['title' => ucfirst($slug), 'slug' => $slug, 'seo_title' => "SEO $slug", 'meta_description' => "Meta $slug", 'draft_blocks' => [['id' => 'a1', 'type' => 'hero', 'heading' => $heading]]]);
        $page->vendor_id = $vendor->id;
        $page->kind = $kind;
        $page->status = $status;
        if ($status !== 'draft') {
            $page->published_snapshot = $page->draftContent();
            $page->published_at = now();
        }
        $page->save();

        return $page;
    }

    private function site(string $host, string $path = '')
    {
        return $this->withHeader('X-Store-Host', $host)->getJson('/api/v1/site'.$path);
    }

    public function test_homepage_and_default_pages_are_served_by_hostname(): void
    {
        $vendor = $this->vendor('alpha', 'alpha.example.test');
        $this->page($vendor, 'home', 'published', 'home', 'Welcome home');
        $this->page($vendor, 'about-us', 'published', 'about', 'About alpha');
        $this->page($vendor, 'services', 'draft', 'services', 'Draft services');

        $this->site('alpha.example.test', '/pages/home')->assertOk()->assertJsonPath('data.kind', 'home')->assertJsonPath('data.blocks.0.heading', 'Welcome home')->assertJsonPath('data.seo_title', 'SEO home')->assertJsonPath('data.meta_description', 'Meta home');
        $this->site('alpha.example.test', '/pages/about-us')->assertOk()->assertJsonPath('data.blocks.0.heading', 'About alpha');
        $this->site('alpha.example.test', '/pages/services')->assertNotFound();
        $paths = collect($this->site('alpha.example.test')->assertOk()->json('data.pages'))->pluck('path')->all();
        $this->assertSame(['/', '/about-us'], $paths);
    }

    public function test_an_unpublished_homepage_is_not_served_so_the_storefront_can_fall_back(): void
    {
        $vendor = $this->vendor('alpha', 'alpha.example.test');
        $this->page($vendor, 'home', 'unpublished', 'home');
        $this->site('alpha.example.test', '/pages/home')->assertNotFound();
        $this->site('alpha.example.test')->assertOk()->assertJsonPath('data.pages', []);
    }

    public function test_reserved_application_and_legal_addresses_are_never_served_as_cms_pages(): void
    {
        $vendor = $this->vendor('alpha', 'alpha.example.test');
        foreach (['privacy-policy', 'terms-of-service', 'products', 'cart', 'checkout', 'school', 'vendor', 'admin', 'printing', 'login'] as $slug) {
            // Such rows cannot be created through the API; even if one exists the public API refuses to serve it.
            $this->page($vendor, $slug);
            $this->site('alpha.example.test', "/pages/$slug")->assertNotFound();
        }
        $this->assertSame([], array_filter(collect($this->site('alpha.example.test')->json('data.pages'))->pluck('slug')->all(), fn ($s) => $s === 'home'));
    }

    public function test_unknown_and_malformed_slugs_fail_safely(): void
    {
        $this->vendor('alpha', 'alpha.example.test');
        foreach (['nope', 'Foo', 'a_b', 'a.b', '-x', 'x-', 'a--b', str_repeat('a', 200)] as $slug) {
            $this->site('alpha.example.test', "/pages/$slug")->assertNotFound();
        }
        $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site/pages/a/b')->assertNotFound();
    }

    public function test_inactive_vendors_and_unverified_or_unprovisioned_domains_expose_nothing(): void
    {
        $this->page($this->vendor('pending', 'pending.example.test', 'pending'), 'about-us');
        $this->page($this->vendor('susp', 'susp.example.test', 'suspended'), 'about-us');
        $this->page($this->vendor('rej', 'rej.example.test', 'rejected'), 'about-us');
        $this->page($this->vendor('unver', 'unver.example.test', 'active', false, 'manual_pending'), 'about-us');
        $this->page($this->vendor('noprov', 'noprov.example.test', 'active', true, 'manual_pending'), 'about-us');
        $this->page($this->vendor('ok', 'ok.example.test'), 'about-us');
        foreach (['pending', 'susp', 'rej', 'unver', 'noprov'] as $slug) {
            $this->site("$slug.example.test")->assertNotFound();
            $this->site("$slug.example.test", '/pages/about-us')->assertNotFound();
        }
        $this->site('ok.example.test', '/pages/about-us')->assertOk();
        $this->site('missing.example.test')->assertNotFound();
        $this->site('127.0.0.1')->assertNotFound();
        $this->site('localhost')->assertNotFound();
    }

    public function test_each_hostname_only_sees_its_own_vendors_content(): void
    {
        $a = $this->vendor('alpha', 'alpha.example.test');
        $b = $this->vendor('bravo', 'bravo.example.test');
        $this->page($a, 'about-us', 'published', 'about', 'Alpha about');
        $this->page($b, 'about-us', 'published', 'about', 'Bravo about');
        $this->page($a, 'only-alpha', 'published', 'custom', 'Alpha only');

        $this->site('alpha.example.test', '/pages/about-us')->assertJsonPath('data.blocks.0.heading', 'Alpha about');
        $this->site('bravo.example.test', '/pages/about-us')->assertJsonPath('data.blocks.0.heading', 'Bravo about');
        $this->site('bravo.example.test', '/pages/only-alpha')->assertNotFound();
        $this->site('bravo.example.test')->assertJsonPath('data.name', 'Bravo');
        $this->assertStringNotContainsString('Alpha', $this->site('bravo.example.test')->getContent());
        // A vendor id or slug in the query string cannot redirect the lookup to another tenant.
        $this->withHeader('X-Store-Host', 'bravo.example.test')->getJson('/api/v1/site/pages/only-alpha?vendor_id='.$a->id.'&vendor=alpha')->assertNotFound();
    }

    public function test_drafts_and_unpublished_changes_never_reach_the_public_payload(): void
    {
        $vendor = $this->vendor('alpha', 'alpha.example.test');
        $page = $this->page($vendor, 'about-us', 'published', 'about', 'Published text');
        $page->draft_blocks = [['id' => 'a1', 'type' => 'hero', 'heading' => 'SECRET DRAFT']];
        $page->title = 'Secret title';
        $page->save();
        $this->page($vendor, 'hidden', 'draft', 'custom', 'SECRET HIDDEN');

        $body = $this->site('alpha.example.test', '/pages/about-us')->assertOk()->assertJsonPath('data.blocks.0.heading', 'Published text')->getContent();
        $all = $body.$this->site('alpha.example.test')->getContent();
        $this->assertStringNotContainsString('SECRET', $all);
        $this->assertStringNotContainsString('Secret title', $all);
        $this->assertStringNotContainsString('draft_blocks', $all);
    }

    public function test_the_public_site_limiter_is_keyed_by_hostname_not_by_the_shared_server_ip(): void
    {
        $limiter = RateLimiter::limiter('site-public');
        $make = function (string $host) use ($limiter): Limit {
            $r = Request::create('/api/v1/site', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);
            $r->headers->set('X-Store-Host', $host);

            return $limiter($r);
        };
        $this->assertNotSame($make('a.example.test')->key, $make('b.example.test')->key);
        $this->assertSame($make('a.example.test')->key, $make('A.Example.Test')->key);
        $this->assertGreaterThanOrEqual(600, $make('a.example.test')->maxAttempts);
    }
}
