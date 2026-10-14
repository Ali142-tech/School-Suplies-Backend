<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\UseRequestUrlForLocalStorage;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMembership;
use App\Models\VendorPage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/** Milestone 5 regressions: every test here corresponds to a confirmed weakness that was fixed. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function vendor(string $slug, string $status = 'active'): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $vendor = Vendor::create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);

        return [$vendor, $owner];
    }

    // ---- image fixtures -------------------------------------------------------------------------------------------

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private function pngBytes(int $w = 40, int $h = 20, array $extraChunks = [], ?string $idat = null, string $trailer = ''): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0".str_repeat("\x2f\x62\xd3\xff", $w);
        }
        $ihdr = pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', $ihdr).implode('', $extraChunks).$this->chunk('IDAT', $idat ?? gzcompress($raw)).$this->chunk('IEND', '').$trailer;
    }

    private function file(string $bytes, string $name = 'a.png', string $mime = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function upload(User $user, string $url, array $data)
    {
        return $this->actingAs($user)->post($url, $data, ['Accept' => 'application/json']);
    }

    // ---- 1. rate limits -------------------------------------------------------------------------------------------

    public function test_throttled_auth_routes_have_separate_buckets_and_still_limit_themselves(): void
    {
        [, $owner] = $this->vendor('alpha');
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/password/reset', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/password/reset', [])->assertStatus(429);   // its own limit still works
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/register', [])->assertStatus(422);
        }
        // 12 throttled hits from this IP so far: with the old shared bucket this login would already be blocked (limit 8).
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
    }

    // ---- 2. image integrity ---------------------------------------------------------------------------------------

    public function test_corrupt_images_with_valid_headers_are_rejected_everywhere(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $corrupt = $this->pngBytes(idat: random_bytes(60));
        $this->upload($owner, '/api/v1/vendor/alpha/media', ['image' => $this->file($corrupt)])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->upload($owner, '/api/v1/vendor/alpha/settings', ['_method' => 'PUT', 'logo' => $this->file($corrupt)])->assertStatus(422)->assertJsonValidationErrors('logo');
        $this->upload($owner, '/api/v1/vendor/alpha/settings', ['_method' => 'PUT', 'banner' => $this->file($corrupt)])->assertStatus(422)->assertJsonValidationErrors('banner');
        $this->assertNull($vendor->fresh()->logo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_truncated_jpeg_is_rejected_and_a_good_one_is_accepted(): void
    {
        [, $owner] = $this->vendor('alpha');
        $gd = imagecreatetruecolor(300, 200);
        imagefilledrectangle($gd, 0, 0, 300, 200, imagecolorallocate($gd, 200, 60, 40));
        ob_start();
        imagejpeg($gd, null, 90);
        $jpeg = ob_get_clean();
        $this->upload($owner, '/api/v1/vendor/alpha/media', ['image' => $this->file(substr($jpeg, 0, (int) (strlen($jpeg) * 0.5)), 'a.jpg', 'image/jpeg')])->assertStatus(422);
        $this->upload($owner, '/api/v1/vendor/alpha/media', ['image' => $this->file($jpeg, 'a.jpg', 'image/jpeg')])->assertCreated();
    }

    public function test_stored_images_are_reencoded_stripping_metadata_and_appended_payloads(): void
    {
        [, $owner] = $this->vendor('alpha');
        $dirty = $this->pngBytes(extraChunks: [$this->chunk('tEXt', "Comment\0SECRET-GPS-METADATA")], trailer: "\n<?php system(\$_GET['c']); ?>");
        $this->assertStringContainsString('SECRET-GPS-METADATA', $dirty);

        $path = $this->upload($owner, '/api/v1/vendor/alpha/media', ['image' => $this->file($dirty, 'photo.php.png')])->assertCreated()->json('data.path');
        $stored = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('SECRET-GPS-METADATA', $stored);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertNotFalse(imagecreatefromstring($stored), 'the stored file is a valid image');
        $this->assertMatchesRegularExpression('#^cms/\d+/[A-Za-z0-9]{40}\.png$#', $path);
    }

    public function test_media_library_has_a_per_vendor_cap(): void
    {
        [, $owner] = $this->vendor('alpha');
        config(['platform.max_media_per_vendor' => 2]);
        $upload = fn () => $this->upload($owner, '/api/v1/vendor/alpha/media', ['image' => $this->file($this->pngBytes())]);
        $upload()->assertCreated();
        $upload()->assertCreated();
        $upload()->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_banner_upload_works_through_the_post_with_method_override_the_frontend_uses(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $this->upload($owner, '/api/v1/vendor/alpha/settings', ['_method' => 'PUT', 'brand_color' => '#112233', 'banner' => $this->file($this->pngBytes(300, 100))])->assertOk();
        $settings = $vendor->settings()->first();
        $this->assertSame('#112233', $settings->brand_color);
        $this->assertStringStartsWith('store-banners/', $settings->banner_path);
        Storage::disk('public')->assertExists($settings->banner_path);
    }

    // ---- 3. inactive vendors and users ----------------------------------------------------------------------------

    public function test_suspended_and_rejected_vendors_cannot_write_anything_in_the_cms(): void
    {
        foreach (['suspended', 'rejected'] as $status) {
            [$vendor, $owner] = $this->vendor("v-$status", $status);
            $page = new VendorPage(['title' => 'P', 'slug' => 'p', 'draft_blocks' => [['id' => 'a', 'type' => 'hero', 'heading' => 'H']]]);
            $page->vendor_id = $vendor->id;
            $page->kind = 'custom';
            $page->status = 'draft';
            $page->save();
            $base = "/api/v1/vendor/v-$status";

            $this->actingAs($owner)->postJson("$base/pages", ['title' => 'N', 'slug' => 'n', 'blocks' => []])->assertForbidden();
            $this->actingAs($owner)->putJson("$base/pages/{$page->id}", ['title' => 'X'])->assertForbidden();
            $this->actingAs($owner)->postJson("$base/pages/{$page->id}/publish")->assertForbidden();
            $this->actingAs($owner)->postJson("$base/pages/{$page->id}/unpublish")->assertForbidden();
            $this->actingAs($owner)->deleteJson("$base/pages/{$page->id}")->assertForbidden();
            $this->upload($owner, "$base/media", ['image' => $this->file($this->pngBytes())])->assertForbidden();
            $this->actingAs($owner)->putJson("$base/settings", ['brand_color' => '#000000'])->assertForbidden();
            $this->assertSame('P', $page->fresh()->title);
        }
    }

    public function test_a_deactivated_account_loses_cms_access_even_with_an_existing_token(): void
    {
        [, $owner] = $this->vendor('alpha');
        $token = $owner->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/vendor/alpha/pages')->assertOk();
        $owner->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/vendor/alpha/pages')->assertForbidden();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertStatus(422);
    }

    public function test_a_soft_deleted_vendors_domain_serves_nothing(): void
    {
        [$vendor] = $this->vendor('alpha');
        StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => 'alpha.example.test', 'is_verified' => true, 'provisioning_status' => 'active', 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => now()]);
        $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site')->assertOk();
        $vendor->delete();
        $this->withHeader('X-Store-Host', 'alpha.example.test')->getJson('/api/v1/site')->assertNotFound();
    }

    public function test_a_membership_removed_while_logged_in_loses_access_immediately(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $staff = User::factory()->create(['role' => 'vendor']);
        $m = VendorMembership::create(['vendor_id' => $vendor->id, 'user_id' => $staff->id, 'role' => 'manager']);
        $this->actingAs($staff)->getJson('/api/v1/vendor/alpha/pages')->assertOk();
        $m->delete();
        $this->actingAs($staff)->getJson('/api/v1/vendor/alpha/pages')->assertForbidden();
    }

    // ---- 4. data exposure -----------------------------------------------------------------------------------------

    public function test_auth_responses_never_contain_password_hashes_or_remember_tokens(): void
    {
        [, $owner] = $this->vendor('alpha');
        $login = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk()->getContent();
        $me = $this->actingAs($owner)->getJson('/api/v1/auth/me')->assertOk()->getContent();
        foreach ([$login, $me] as $body) {
            $this->assertStringNotContainsString('remember_token', $body);
            $this->assertStringNotContainsString('"password"', $body);
            $this->assertStringNotContainsString('$2y$', $body);
        }
    }

    public function test_database_errors_are_logged_without_bound_values(): void
    {
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);
        $exception = new QueryException('mysql', 'insert into users (email, password) values (?, ?)', ['victim@example.test', '$2y$10$SECRETHASHVALUE'], new \PDOException("SQLSTATE[23000]: Duplicate entry 'victim@example.test' for key 'users_email_unique'"));
        report($exception);

        $logged = json_encode(array_map(fn ($r) => $r->message.json_encode($r->context), $handler->getRecords()));
        $this->assertNotEmpty($handler->getRecords());
        $this->assertStringNotContainsString('SECRETHASHVALUE', $logged);
        $this->assertStringNotContainsString('victim@example.test', $logged);
        $this->assertStringContainsString('insert into users', $logged);   // the placeholder SQL stays useful for debugging
    }

    // ---- 5. local file URLs ---------------------------------------------------------------------------------------

    public function test_local_environment_builds_file_urls_from_the_real_request_address(): void
    {
        $handle = fn () => (new UseRequestUrlForLocalStorage)->handle(Request::create('http://127.0.0.1:8001/api/v1/x'), fn () => response('ok'));

        $this->app['env'] = 'local';
        Storage::forgetDisk('public');
        $handle();
        $this->assertSame('http://127.0.0.1:8001/storage/cms/1/a.png', Storage::disk('public')->url('cms/1/a.png'));
    }

    public function test_non_local_environments_ignore_the_request_host_for_file_urls(): void
    {
        config(['filesystems.disks.public.url' => 'https://api.example.com/storage']);
        foreach (['production', 'staging', 'testing'] as $env) {
            $this->app['env'] = $env;
            Storage::forgetDisk('public');
            (new UseRequestUrlForLocalStorage)->handle(Request::create('http://evil.test:9/api/v1/x'), fn () => response('ok'));
            $this->assertSame('https://api.example.com/storage/a.png', Storage::disk('public')->url('a.png'), $env);
        }
    }

    // ---- 6. races -------------------------------------------------------------------------------------------------

    public function test_a_concurrent_duplicate_slug_is_a_validation_error_not_a_server_error(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $fired = false;
        VendorPage::creating(function () use ($vendor, &$fired) {
            if (! $fired) {   // another request wins the race and inserts the same slug just before this one saves
                $fired = true;
                DB::table('vendor_pages')->insert(['vendor_id' => $vendor->id, 'slug' => 'race', 'kind' => 'custom', 'title' => 'Other', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->actingAs($owner)->postJson('/api/v1/vendor/alpha/pages', ['title' => 'Race', 'slug' => 'race', 'blocks' => []])->assertStatus(422)->assertJsonValidationErrors('slug');
    }
}
