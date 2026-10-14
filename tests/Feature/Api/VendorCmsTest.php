<?php

namespace Tests\Feature\Api;

use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMembership;
use App\Models\VendorPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VendorCmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function vendor(string $slug, string $status = 'active', ?string $host = null): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $vendor = Vendor::create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
        if ($host) {
            StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => $host, 'is_verified' => true, 'provisioning_status' => 'active', 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => now()]);
        }

        return [$vendor, $owner];
    }

    private function member(Vendor $vendor, string $role): User
    {
        $user = User::factory()->create(['role' => 'vendor']);
        VendorMembership::create(['vendor_id' => $vendor->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function api(Vendor $vendor, string $path = ''): string
    {
        return "/api/v1/vendor/{$vendor->slug}/pages{$path}";
    }

    private function upload(User $user, Vendor $vendor): string
    {
        return $this->actingAs($user)->post("/api/v1/vendor/{$vendor->slug}/media", ['image' => UploadedFile::fake()->image('p.png', 200, 100)], ['Accept' => 'application/json'])->assertCreated()->json('data.path');
    }

    private function heroBlock(string $heading = 'Welcome'): array
    {
        return ['type' => 'hero', 'heading' => $heading, 'subheading' => 'Quality supplies', 'cta_label' => 'Shop', 'cta_url' => '/products'];
    }

    private function createPage(User $user, Vendor $vendor, array $overrides = []): array
    {
        return $this->actingAs($user)->postJson($this->api($vendor), [...['title' => 'Our Story', 'slug' => 'our-story', 'blocks' => [$this->heroBlock()]], ...$overrides])->assertCreated()->json('data');
    }

    public function test_owner_can_create_edit_list_and_delete_a_custom_page(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $page = $this->createPage($owner, $vendor);
        $this->assertSame('draft', $page['status']);
        $this->assertSame('custom', $page['kind']);
        $this->assertTrue($page['is_deletable']);

        $this->actingAs($owner)->putJson($this->api($vendor, "/{$page['id']}"), ['title' => 'Our Story 2', 'blocks' => [$this->heroBlock('Hello'), ['type' => 'heading_text', 'heading' => 'About', 'level' => 2, 'body' => "We **care**.\n- Paper\n- Ink\n[Shop](/products)"]]])
            ->assertOk()->assertJsonPath('data.title', 'Our Story 2')->assertJsonCount(2, 'data.blocks');
        $list = $this->actingAs($owner)->getJson($this->api($vendor))->assertOk()->json('data');
        $custom = collect($list)->firstWhere('slug', 'our-story');
        $this->assertSame('draft', $custom['status']);
        $this->assertNotNull($custom['updated_at']);

        $this->actingAs($owner)->deleteJson($this->api($vendor, "/{$page['id']}"))->assertOk();
        $this->assertDatabaseMissing('vendor_pages', ['id' => $page['id']]);
    }

    public function test_default_pages_exist_can_be_edited_but_not_renamed_or_deleted(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $list = $this->actingAs($owner)->getJson($this->api($vendor))->assertOk()->json('data');
        $this->assertSame(['home', 'about-us', 'contact', 'services'], collect($list)->pluck('slug')->all());
        $this->assertSame(4, count($this->actingAs($owner)->getJson($this->api($vendor))->json('data')), 'defaults are not duplicated');
        $about = collect($list)->firstWhere('slug', 'about-us');
        $this->assertFalse($about['is_deletable']);

        $this->actingAs($owner)->putJson($this->api($vendor, "/{$about['id']}"), ['blocks' => [['type' => 'heading_text', 'heading' => 'Who we are', 'body' => 'Family run since 1999.']]])->assertOk();
        $this->actingAs($owner)->putJson($this->api($vendor, "/{$about['id']}"), ['slug' => 'whoami'])->assertStatus(422);
        $this->actingAs($owner)->deleteJson($this->api($vendor, "/{$about['id']}"))->assertForbidden();
        $this->assertDatabaseHas('vendor_pages', ['id' => $about['id'], 'slug' => 'about-us']);
    }

    public function test_platform_owned_and_route_slugs_are_reserved(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        foreach (['privacy-policy', 'terms-of-service', 'refund-policy', 'security', 'products', 'cart', 'school', 'vendor', 'admin', 'home'] as $slug) {
            $this->actingAs($owner)->postJson($this->api($vendor), ['title' => 'X', 'slug' => $slug, 'blocks' => []])->assertStatus(422)->assertJsonValidationErrors('slug');
        }
        $page = $this->createPage($owner, $vendor);
        $this->actingAs($owner)->putJson($this->api($vendor, "/{$page['id']}"), ['slug' => 'privacy'])->assertStatus(422);
        $this->actingAs($owner)->postJson($this->api($vendor), ['title' => 'Bad', 'slug' => 'Not Valid!', 'blocks' => []])->assertStatus(422);
        $this->assertDatabaseMissing('vendor_pages', ['slug' => 'privacy-policy']);
    }

    public function test_slugs_are_unique_per_vendor_only(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        [$b, $ownerB] = $this->vendor('bravo');
        $this->createPage($ownerA, $a);
        $this->actingAs($ownerA)->postJson($this->api($a), ['title' => 'Dup', 'slug' => 'our-story', 'blocks' => []])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->actingAs($ownerA)->postJson($this->api($a), ['title' => 'Dup', 'slug' => 'about-us', 'blocks' => []])->assertStatus(422);
        $this->createPage($ownerB, $b);
        $this->assertSame(2, VendorPage::where('slug', 'our-story')->count());
    }

    public function test_draft_is_hidden_until_published_and_edits_stay_draft_until_republished(): void
    {
        [$vendor, $owner] = $this->vendor('alpha', 'active', 'alpha.example.test');
        $page = $this->createPage($owner, $vendor, ['title' => 'First']);
        $site = fn (string $slug) => $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson("/api/v1/site/pages/{$slug}");

        $site('our-story')->assertNotFound();
        $this->actingAs($owner)->postJson($this->api($vendor, "/{$page['id']}/publish"))->assertOk()->assertJsonPath('data.status', 'published');
        $site('our-story')->assertOk()->assertJsonPath('data.title', 'First')->assertJsonPath('data.blocks.0.heading', 'Welcome');

        $this->actingAs($owner)->putJson($this->api($vendor, "/{$page['id']}"), ['title' => 'Second', 'blocks' => [$this->heroBlock('Changed')]])->assertOk()->assertJsonPath('data.has_unpublished_changes', true);
        $site('our-story')->assertOk()->assertJsonPath('data.title', 'First')->assertJsonPath('data.blocks.0.heading', 'Welcome');

        $this->actingAs($owner)->postJson($this->api($vendor, "/{$page['id']}/publish"))->assertOk()->assertJsonPath('data.has_unpublished_changes', false);
        $site('our-story')->assertJsonPath('data.blocks.0.heading', 'Changed');

        $this->actingAs($owner)->postJson($this->api($vendor, "/{$page['id']}/unpublish"))->assertOk()->assertJsonPath('data.status', 'unpublished');
        $site('our-story')->assertNotFound();
        $this->actingAs($owner)->postJson($this->api($vendor, "/{$page['id']}/unpublish"))->assertStatus(409);
    }

    public function test_publishing_an_empty_page_is_rejected_and_public_payload_has_no_internal_fields(): void
    {
        [$vendor, $owner] = $this->vendor('alpha', 'active', 'alpha.example.test');
        $empty = $this->createPage($owner, $vendor, ['slug' => 'empty', 'title' => 'Empty', 'blocks' => []]);
        $this->actingAs($owner)->postJson($this->api($vendor, "/{$empty['id']}/publish"))->assertStatus(422);
        $page = $this->createPage($owner, $vendor);
        $this->actingAs($owner)->postJson($this->api($vendor, "/{$page['id']}/publish"))->assertOk();
        $body = $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site/pages/our-story')->assertOk()->json('data');
        $this->assertSame([], array_intersect(['id', 'vendor_id', 'updated_by', 'draft_blocks', 'status'], array_keys($body)));
    }

    public function test_hostname_resolves_only_that_vendors_published_content(): void
    {
        [$a, $ownerA] = $this->vendor('alpha', 'active', 'alpha.example.test');
        [$b, $ownerB] = $this->vendor('bravo', 'active', 'bravo.example.test');
        foreach ([[$a, $ownerA, 'Alpha heading'], [$b, $ownerB, 'Bravo heading']] as [$v, $o, $h]) {
            $page = $this->createPage($o, $v, ['title' => $h, 'blocks' => [$this->heroBlock($h)]]);
            $this->actingAs($o)->postJson($this->api($v, "/{$page['id']}/publish"))->assertOk();
        }
        $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site/pages/our-story')->assertJsonPath('data.blocks.0.heading', 'Alpha heading');
        $this->withHeader('X-Store-Host', 'bravo.example.test')->getJson('/api/v1/site/pages/our-story')->assertJsonPath('data.blocks.0.heading', 'Bravo heading');
        $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.name', 'Alpha')->assertJsonCount(1, 'data.pages');
        $this->withHeader('X-Store-Host', 'unknown.example.test')->getJson('/api/v1/site/pages/our-story')->assertNotFound();
        $this->withHeader('X-Store-Host', '127.0.0.1')->getJson('/api/v1/site')->assertNotFound();

        StoreDomain::where('hostname', 'bravo.example.test')->update(['is_verified' => false]);
        $this->withHeader('X-Store-Host', 'bravo.example.test')->getJson('/api/v1/site/pages/our-story')->assertNotFound();
        $a->update(['status' => 'suspended']);
        $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site/pages/our-story')->assertNotFound();
    }

    public function test_cross_vendor_access_is_blocked_everywhere(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        [$b, $ownerB] = $this->vendor('bravo');
        $pageA = $this->createPage($ownerA, $a);

        $this->actingAs($ownerB)->getJson($this->api($a))->assertForbidden();
        $this->actingAs($ownerB)->postJson($this->api($a), ['title' => 'Hack', 'slug' => 'hack', 'blocks' => []])->assertForbidden();
        $this->actingAs($ownerB)->putJson($this->api($a, "/{$pageA['id']}"), ['title' => 'Hack'])->assertForbidden();
        // Own vendor route with the other vendor's page id: not found, nothing changed.
        foreach ([['getJson', ''], ['getJson', '/preview'], ['putJson', ''], ['deleteJson', ''], ['postJson', '/publish'], ['postJson', '/unpublish']] as [$method, $suffix]) {
            $this->actingAs($ownerB)->{$method}($this->api($b, "/{$pageA['id']}{$suffix}"), $method === 'putJson' ? ['title' => 'Hack'] : [])->assertNotFound();
        }
        $this->assertSame('Our Story', VendorPage::find($pageA['id'])->title);
        // A vendor_id supplied by the browser is ignored.
        $created = $this->actingAs($ownerB)->postJson($this->api($b), ['title' => 'Mine', 'slug' => 'mine', 'vendor_id' => $a->id, 'kind' => 'home', 'status' => 'published', 'blocks' => []])->assertCreated()->json('data');
        $this->assertSame($b->id, VendorPage::find($created['id'])->vendor_id);
        $this->assertSame('custom', $created['kind']);
        $this->assertSame('draft', $created['status']);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->api($a))->assertUnauthorized();
    }

    public function test_staff_can_view_and_preview_but_not_modify(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $page = $this->createPage($owner, $vendor);
        $staff = $this->member($vendor, 'staff');
        $manager = $this->member($vendor, 'manager');

        $this->actingAs($staff)->getJson($this->api($vendor))->assertOk();
        $this->actingAs($staff)->getJson($this->api($vendor, "/{$page['id']}"))->assertOk();
        $this->actingAs($staff)->getJson($this->api($vendor, "/{$page['id']}/preview"))->assertOk()->assertJsonPath('data.preview', true);
        $this->actingAs($staff)->postJson($this->api($vendor), ['title' => 'N', 'slug' => 'n', 'blocks' => []])->assertForbidden();
        $this->actingAs($staff)->putJson($this->api($vendor, "/{$page['id']}"), ['title' => 'N'])->assertForbidden();
        $this->actingAs($staff)->postJson($this->api($vendor, "/{$page['id']}/publish"))->assertForbidden();
        $this->actingAs($staff)->postJson($this->api($vendor, "/{$page['id']}/unpublish"))->assertForbidden();
        $this->actingAs($staff)->deleteJson($this->api($vendor, "/{$page['id']}"))->assertForbidden();
        $this->actingAs($staff)->post('/api/v1/vendor/alpha/media', ['image' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($manager)->putJson($this->api($vendor, "/{$page['id']}"), ['title' => 'By manager'])->assertOk();
        $this->actingAs($manager)->postJson($this->api($vendor, "/{$page['id']}/publish"))->assertOk();
    }

    public function test_pending_vendors_cannot_create_or_edit_pages(): void
    {
        [$vendor, $owner] = $this->vendor('pendy', 'pending');
        $this->actingAs($owner)->getJson($this->api($vendor))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->postJson($this->api($vendor), ['title' => 'X', 'slug' => 'x', 'blocks' => []])->assertForbidden();
        $this->assertSame(0, VendorPage::count());
    }

    public function test_preview_requires_authentication_and_membership(): void
    {
        [$vendor, $owner] = $this->vendor('alpha', 'active', 'alpha.example.test');
        [, $stranger] = $this->vendor('bravo');
        $page = $this->createPage($owner, $vendor, ['blocks' => [$this->heroBlock('Secret draft')]]);

        $this->app['auth']->forgetGuards();
        $this->getJson($this->api($vendor, "/{$page['id']}/preview"))->assertUnauthorized();
        $this->actingAs($stranger)->getJson($this->api($vendor, "/{$page['id']}/preview"))->assertForbidden();
        $this->actingAs($owner)->getJson($this->api($vendor, "/{$page['id']}/preview"))->assertOk()->assertJsonPath('data.blocks.0.heading', 'Secret draft');
        $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site/pages/our-story')->assertNotFound();
        $this->assertStringNotContainsString('Secret draft', $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site')->getContent());
    }

    public function test_unsafe_content_is_rejected_and_unknown_keys_are_dropped(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $path = $this->upload($owner, $vendor);
        $bad = [
            'js url in cta' => ['type' => 'cta', 'heading' => 'H', 'button_label' => 'Go', 'button_url' => 'javascript:alert(1)'],
            'data url' => ['type' => 'cta', 'heading' => 'H', 'button_label' => 'Go', 'button_url' => 'data:text/html;base64,PHNjcmlwdD4='],
            'protocol relative' => ['type' => 'cta', 'heading' => 'H', 'button_label' => 'Go', 'button_url' => '//evil.test/x'],
            'userinfo url' => ['type' => 'cta', 'heading' => 'H', 'button_label' => 'Go', 'button_url' => 'https://user:pw@evil.test'],
            'script in heading' => ['type' => 'hero', 'heading' => '<script>alert(1)</script>'],
            'img onerror in body' => ['type' => 'heading_text', 'body' => 'x <img src=x onerror=alert(1)>'],
            'js link in markup' => ['type' => 'heading_text', 'body' => 'click [here](javascript:alert(1))'],
            'unknown type' => ['type' => 'script', 'src' => 'https://evil.test/x.js'],
            'external image' => ['type' => 'image', 'image' => 'https://evil.test/x.png', 'alt' => 'a'],
            'traversal image' => ['type' => 'image', 'image' => 'cms/1/../../.env', 'alt' => 'a'],
            'image without alt' => ['type' => 'image', 'image' => $path],
            'too many features' => ['type' => 'features', 'items' => array_fill(0, 9, ['title' => 'T'])],
            'empty contact' => ['type' => 'contact'],
            'bad icon' => ['type' => 'features', 'items' => [['title' => 'T', 'icon' => 'x" onload="y']]],
        ];
        foreach ($bad as $label => $block) {
            $this->actingAs($owner)->postJson($this->api($vendor), ['title' => 'T', 'slug' => 'unsafe', 'blocks' => [$block]])->assertStatus(422, "expected rejection: $label");
        }
        $this->assertDatabaseMissing('vendor_pages', ['slug' => 'unsafe']);
        $this->actingAs($owner)->postJson($this->api($vendor), ['title' => 'T', 'slug' => 'toomany', 'blocks' => array_fill(0, 41, $this->heroBlock())])->assertStatus(422);

        $ok = $this->actingAs($owner)->postJson($this->api($vendor), ['title' => 'Safe', 'slug' => 'safe', 'blocks' => [
            ['type' => 'hero', 'heading' => 'Hi', 'onclick' => 'alert(1)', 'html' => '<b>x</b>', 'image' => $path, 'cta_label' => 'Mail', 'cta_url' => 'mailto:hi@example.test'],
            ['type' => 'image_text', 'image' => $path, 'alt' => 'Shop', 'body' => 'Visit [us](https://example.test/a?b=1) *today*', 'image_position' => 'right'],
            ['type' => 'features', 'heading' => 'Why us', 'items' => [['title' => 'Fast', 'description' => 'Quick', 'icon' => 'truck']]],
            ['type' => 'contact', 'email' => 'hi@example.test', 'phone' => '+92 300 1234567', 'address' => '1 Main Rd'],
        ]])->assertCreated()->json('data.blocks');
        $this->assertCount(4, $ok);
        $this->assertArrayNotHasKey('onclick', $ok[0]);
        $this->assertArrayNotHasKey('html', $ok[0]);
        $this->assertStringContainsString('/storage/', $ok[0]['image_url']);
        $this->assertNotEmpty($ok[0]['id']);
    }

    public function test_media_upload_validates_type_size_and_vendor_scope(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        [$b, $ownerB] = $this->vendor('bravo');
        $post = fn (User $u, Vendor $v, UploadedFile $f) => $this->actingAs($u)->post("/api/v1/vendor/{$v->slug}/media", ['image' => $f], ['Accept' => 'application/json']);

        $post($ownerA, $a, UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml'))->assertStatus(422);
        $post($ownerA, $a, UploadedFile::fake()->create('x.php', 5, 'application/x-php'))->assertStatus(422);
        $post($ownerA, $a, UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'))->assertStatus(422);
        $post($ownerA, $a, UploadedFile::fake()->image('big.png')->size(6000))->assertStatus(422);
        $post($ownerB, $a, UploadedFile::fake()->image('a.png'))->assertForbidden();
        $path = $post($ownerA, $a, UploadedFile::fake()->image('a.jpg', 100, 100))->assertCreated()->json('data.path');
        $this->assertStringStartsWith("cms/{$a->id}/", $path);
        Storage::disk('public')->assertExists($path);
        // Vendor B cannot reference vendor A's file in a block.
        $this->actingAs($ownerB)->postJson($this->api($b), ['title' => 'T', 'slug' => 't', 'blocks' => [['type' => 'image', 'image' => $path, 'alt' => 'x']]])->assertStatus(422);
        $this->actingAs($ownerA)->postJson($this->api($a), ['title' => 'T', 'slug' => 't', 'blocks' => [['type' => 'image', 'image' => $path, 'alt' => 'x']]])->assertCreated();
    }

    public function test_site_settings_are_validated_merged_and_scoped(): void
    {
        [$a, $ownerA] = $this->vendor('alpha', 'active', 'alpha.example.test');
        [$b, $ownerB] = $this->vendor('bravo');
        $url = '/api/v1/vendor/alpha/settings';
        $this->actingAs($ownerA)->putJson($url, ['brand_color' => '#112233', 'settings' => [
            'accent_color' => '#abcdef', 'description' => 'Hello',
            'navigation' => [['label' => 'About', 'url' => '/about-us'], ['label' => 'Shop', 'url' => '/products']],
            'footer' => ['text' => '© Alpha', 'links' => [['label' => 'Mail', 'url' => 'mailto:a@example.test']]],
            'seo' => ['title' => 'Alpha Supplies', 'description' => 'Best supplies'],
        ]])->assertOk();
        // A partial update must not wipe the other keys.
        $this->actingAs($ownerA)->putJson($url, ['settings' => ['description' => 'Updated']])->assertOk();
        $site = $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site')->assertOk()->json('data');
        $this->assertSame('Updated', $site['description']);
        $this->assertSame('#112233', $site['brand_color']);
        $this->assertSame('#abcdef', $site['accent_color']);
        $this->assertCount(2, $site['navigation']);
        $this->assertSame('Alpha Supplies', $site['seo']['title']);
        $this->assertSame('© Alpha', $site['footer']['text']);

        foreach ([
            ['brand_color' => 'red; background:url(x)'],
            ['settings' => ['navigation' => [['label' => 'X', 'url' => 'javascript:alert(1)']]]],
            ['settings' => ['footer' => ['text' => '<script>x</script>']]],
            ['settings' => ['evil_key' => 'x']],
            ['settings' => ['navigation' => array_fill(0, 11, ['label' => 'X', 'url' => '/x'])]],
            ['settings' => ['seo' => ['title' => str_repeat('a', 71)]]],
        ] as $payload) {
            $this->actingAs($ownerA)->putJson($url, $payload)->assertStatus(422);
        }
        $this->actingAs($ownerB)->putJson($url, ['brand_color' => '#000000'])->assertForbidden();
        $this->actingAs($ownerA)->put($url, ['logo' => UploadedFile::fake()->image('logo.png', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringStartsWith("cms/{$a->id}/", $a->fresh()->logo_path);
        $this->actingAs($ownerA)->put($url, ['logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertNull($b->fresh()->logo_path);
    }

    public function test_settings_responses_include_logo_and_banner_urls_for_the_dashboard(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        $this->actingAs($ownerA)->getJson('/api/v1/vendor/alpha/settings')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->actingAs($ownerA)->put('/api/v1/vendor/alpha/settings', ['logo' => UploadedFile::fake()->image('l.png', 80, 80)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.logo_url', Storage::disk('public')->url($a->fresh()->logo_path));
        $this->actingAs($ownerA)->getJson('/api/v1/vendor/alpha/settings')->assertOk()->assertJsonStructure(['data' => ['brand_color', 'settings', 'logo_url', 'banner_url']]);
    }

    public function test_public_site_and_cms_traffic_does_not_consume_the_login_rate_limit(): void
    {
        [$vendor, $owner] = $this->vendor('alpha', 'active', 'alpha.example.test');
        for ($i = 0; $i < 12; $i++) {
            $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site')->assertOk();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertStatus(200);
        $this->actingAs($owner);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($this->api($vendor), ['title' => "P$i", 'slug' => "p-$i", 'blocks' => []])->assertCreated();
        }
        $this->postJson('/api/v1/vendor/alpha/reapply', [])->assertForbidden(); // handler-level 403, not a 429 from a polluted bucket
    }

    public function test_existing_vendor_settings_form_payload_still_works(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        $this->actingAs($ownerA)->put('/api/v1/vendor/alpha/settings', ['brand_color' => '#eaf0ff', 'settings' => ['description' => 'Our store'], 'banner' => UploadedFile::fake()->image('b.png', 300, 100)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('#eaf0ff', $a->settings()->first()->brand_color);
        $this->assertNotNull($a->settings()->first()->banner_path);
    }
}
