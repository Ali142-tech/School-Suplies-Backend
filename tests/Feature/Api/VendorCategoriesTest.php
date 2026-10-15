<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(string $slug, string $status = 'active', ?string $host = null): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $vendor = Vendor::create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
        if ($host) {
            StoreDomain::create(['vendor_id' => $vendor->id, 'hostname' => $host, 'is_verified' => true, 'provisioning_status' => 'active', 'source' => 'platform_subdomain', 'is_primary' => true, 'verified_at' => now()]);
        }

        return [$vendor, $owner];
    }

    private function api(Vendor $vendor, string $path = ''): string
    {
        return "/api/v1/vendor/{$vendor->slug}/categories{$path}";
    }

    private function product(Vendor $vendor, ?int $categoryId = null, string $name = 'Notebook'): Product
    {
        return Product::create(['vendor_id' => $vendor->id, 'category_id' => $categoryId, 'name' => $name, 'slug' => str($name)->slug().'-'.$vendor->id, 'description' => 'd', 'price' => 100, 'currency' => 'PKR', 'status' => 'published', 'type' => 'supply']);
    }

    public function test_vendor_can_create_list_rename_and_delete_categories_and_products_become_uncategorized(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $created = $this->actingAs($owner)->postJson($this->api($vendor), ['name' => 'Art Supplies', 'description' => 'Paints and brushes'])
            ->assertCreated()->assertJsonPath('data.slug', 'art-supplies')->assertJsonPath('data.is_active', true)->json('data');
        $this->assertSame($vendor->id, Category::find($created['id'])->vendor_id);
        $product = $this->product($vendor, $created['id']);

        $this->actingAs($owner)->getJson($this->api($vendor))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Art Supplies')->assertJsonPath('data.0.products_count', 1);

        $this->actingAs($owner)->putJson($this->api($vendor, "/{$created['id']}"), ['name' => 'Craft', 'description' => null, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Craft')->assertJsonPath('data.is_active', false);

        $this->actingAs($owner)->deleteJson($this->api($vendor, "/{$created['id']}"))->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $created['id']]);
        $this->assertNull($product->fresh()->category_id);
        $this->assertNotNull(Product::find($product->id));
    }

    public function test_slug_is_unique_per_vendor_with_numeric_suffix_and_a_client_vendor_id_is_ignored(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        [$b] = $this->vendor('beta');
        $this->actingAs($ownerA)->postJson($this->api($a), ['name' => 'Pens'])->assertCreated()->assertJsonPath('data.slug', 'pens');
        $this->actingAs($ownerA)->postJson($this->api($a), ['name' => 'Pens!'])->assertCreated()->assertJsonPath('data.slug', 'pens-2');
        $this->actingAs($ownerA)->postJson($this->api($a), ['name' => 'pens?', 'vendor_id' => $b->id])->assertCreated()->assertJsonPath('data.slug', 'pens-3')->assertJsonPath('data.vendor_id', $a->id);
    }

    public function test_duplicate_name_is_rejected_within_a_vendor_but_allowed_across_vendors(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        [$b, $ownerB] = $this->vendor('beta');
        $this->actingAs($ownerA)->postJson($this->api($a), ['name' => 'Pens'])->assertCreated();
        $other = $this->actingAs($ownerA)->postJson($this->api($a), ['name' => 'Pencils'])->assertCreated()->json('data');
        $this->actingAs($ownerA)->postJson($this->api($a), ['name' => 'Pens'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($ownerA)->putJson($this->api($a, "/{$other['id']}"), ['name' => 'Pens'])->assertUnprocessable();
        $this->actingAs($ownerA)->putJson($this->api($a, "/{$other['id']}"), ['name' => 'Pencils', 'description' => 'same name as itself'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($ownerB)->postJson($this->api($b), ['name' => 'Pens'])->assertCreated();
    }

    public function test_vendor_is_capped_at_one_hundred_categories(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        for ($i = 1; $i <= 100; $i++) {
            Category::create(['vendor_id' => $vendor->id, 'name' => "Cat $i", 'slug' => "cat-$i"]);
        }
        $this->actingAs($owner)->postJson($this->api($vendor), ['name' => 'One too many'])->assertUnprocessable();
    }

    public function test_vendor_cannot_see_edit_delete_or_assign_another_vendors_category(): void
    {
        [$a, $ownerA] = $this->vendor('alpha');
        [$b, $ownerB] = $this->vendor('beta');
        $theirs = Category::create(['vendor_id' => $b->id, 'name' => 'Beta Only', 'slug' => 'beta-only']);

        $this->actingAs($ownerA)->getJson($this->api($a))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($ownerA)->putJson($this->api($a, "/{$theirs->id}"), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($ownerA)->deleteJson($this->api($a, "/{$theirs->id}"))->assertNotFound();
        $this->assertSame('Beta Only', $theirs->fresh()->name);

        $payload = ['name' => 'Mine', 'slug' => 'mine', 'description' => 'd', 'price' => 10, 'currency' => 'PKR', 'type' => 'supply', 'status' => 'published', 'category_id' => $theirs->id];
        $this->actingAs($ownerA)->postJson("/api/v1/vendor/{$a->slug}/products", $payload)->assertUnprocessable();
        $own = $this->product($a);
        $this->actingAs($ownerA)->putJson("/api/v1/vendor/{$a->slug}/products/{$own->id}", [...$payload, 'name' => 'Notebook'])->assertUnprocessable();

        // The other vendor's owner cannot use vendor A's routes at all.
        $this->app['auth']->forgetGuards();
        $this->actingAs($ownerB)->getJson($this->api($a))->assertForbidden();
    }

    public function test_vendor_cannot_edit_or_delete_a_platform_category(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $platform = Category::create(['vendor_id' => null, 'name' => 'Stationery', 'slug' => 'stationery']);

        $this->actingAs($owner)->getJson($this->api($vendor))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->putJson($this->api($vendor, "/{$platform->id}"), ['name' => 'Renamed'])->assertNotFound();
        $this->actingAs($owner)->deleteJson($this->api($vendor, "/{$platform->id}"))->assertNotFound();
        $this->assertSame('Stationery', $platform->fresh()->name);
    }

    public function test_staff_can_read_but_not_write_and_pending_vendor_cannot_write(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $category = Category::create(['vendor_id' => $vendor->id, 'name' => 'Pens', 'slug' => 'pens']);
        $staff = User::factory()->create(['role' => 'vendor']);
        VendorMembership::create(['vendor_id' => $vendor->id, 'user_id' => $staff->id, 'role' => 'staff']);

        $this->actingAs($staff)->getJson($this->api($vendor))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($staff)->postJson($this->api($vendor), ['name' => 'New'])->assertForbidden();
        $this->actingAs($staff)->putJson($this->api($vendor, "/{$category->id}"), ['name' => 'New'])->assertForbidden();
        $this->actingAs($staff)->deleteJson($this->api($vendor, "/{$category->id}"))->assertForbidden();
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Pens']);

        [$pending, $pendingOwner] = $this->vendor('waiting', 'pending');
        $pendingCategory = Category::create(['vendor_id' => $pending->id, 'name' => 'Old', 'slug' => 'old']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($pendingOwner)->getJson($this->api($pending))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($pendingOwner)->postJson($this->api($pending), ['name' => 'New'])->assertForbidden();
        $this->actingAs($pendingOwner)->putJson($this->api($pending, "/{$pendingCategory->id}"), ['name' => 'New'])->assertForbidden();
        $this->actingAs($pendingOwner)->deleteJson($this->api($pending, "/{$pendingCategory->id}"))->assertForbidden();
    }

    public function test_markup_in_name_or_description_is_rejected(): void
    {
        [$vendor, $owner] = $this->vendor('alpha');
        $this->actingAs($owner)->postJson($this->api($vendor), ['name' => '<script>alert(1)</script>'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($owner)->postJson($this->api($vendor), ['name' => 'Fine', 'description' => '<img src=x onerror=alert(1)>'])->assertUnprocessable()->assertJsonValidationErrors('description');
        $category = Category::create(['vendor_id' => $vendor->id, 'name' => 'Pens', 'slug' => 'pens']);
        $this->actingAs($owner)->putJson($this->api($vendor, "/{$category->id}"), ['name' => '<b>x</b>'])->assertUnprocessable();
        $this->assertSame(1, Category::count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [$vendor] = $this->vendor('alpha');
        $this->getJson($this->api($vendor))->assertUnauthorized();
        $this->postJson($this->api($vendor), ['name' => 'X'])->assertUnauthorized();
    }

    public function test_vendor_categories_show_on_that_storefront_only(): void
    {
        [$a, $ownerA] = $this->vendor('alpha', 'active', 'alpha.example.test');
        [$b] = $this->vendor('beta', 'active', 'beta.example.test');
        Category::create(['vendor_id' => null, 'name' => 'Platform Wide', 'slug' => 'platform-wide']);
        $this->actingAs($ownerA)->postJson($this->api($a), ['name' => 'Alpha Specials'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $names = fn (?string $host) => collect(($host ? $this->withHeader('X-Store-Host', $host) : $this->withoutHeader('X-Store-Host'))->getJson('/api/v1/categories')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('Alpha Specials', $names('alpha.example.test'));
        $this->assertNotContains('Alpha Specials', $names('beta.example.test'));
        $this->assertNotContains('Alpha Specials', $names(null));
        $this->assertSame(['Platform Wide'], $names(null));
    }
}
