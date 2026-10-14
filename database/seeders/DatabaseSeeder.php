<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\PrintingSpecification;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMembership;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }
        $legacyAdmin = User::where('email', 'admin@example.test')->where('name', 'Demo Admin')->where('role', 'platform_admin')->first();
        if ($legacyAdmin) {
            $legacyAdmin->tokens()->delete();
            $legacyAdmin->update(['role' => 'customer', 'is_active' => false]);
        }
        $owner = User::firstOrCreate(['email' => 'seller@example.test'], ['name' => 'Demo Seller', 'password' => 'ChangeThisDemoPassword123!', 'role' => 'customer']);
        if ($owner->role === 'customer') {
            $owner->update(['role' => 'vendor']);
        }
        User::firstOrCreate(['email' => 'customer@example.test'], ['name' => 'Demo Customer', 'password' => 'ChangeThisDemoPassword123!', 'role' => 'customer']);
        $vendor = Vendor::firstOrCreate(['slug' => 'paper-co'], ['owner_id' => $owner->id, 'name' => 'Paper & Co.', 'email' => 'seller@example.test', 'description' => 'Thoughtful paper goods for bright ideas.', 'status' => 'active']);
        VendorMembership::firstOrCreate(['vendor_id' => $vendor->id, 'user_id' => $owner->id], ['role' => 'manager']);
        StoreSetting::firstOrCreate(['vendor_id' => $vendor->id], ['brand_color' => '#eaf0ff']);
        $category = Category::firstOrCreate(['vendor_id' => null, 'slug' => 'notebooks'], ['name' => 'Notebooks', 'is_active' => true]);
        foreach ([['Classic Hardcover Notebook', 2365, 42], ['Everyday Graphite Pencil Set', 1460, 120], ['Weekly Desk Planner', 3340, 14]] as [$name,$price,$stock]) {
            $product = Product::firstOrCreate(['vendor_id' => $vendor->id, 'slug' => Str::slug($name)], ['category_id' => $category->id, 'name' => $name, 'price' => $price, 'currency' => 'PKR', 'description' => 'Development demo product.', 'type' => 'supply', 'status' => 'published']);
            Inventory::firstOrCreate(['product_id' => $product->id], ['quantity' => $stock]);
        }
        $printVendor = Vendor::firstOrCreate(['slug' => 'brightprint'], ['owner_id' => $owner->id, 'name' => 'Brightprint Studio', 'email' => 'print@example.test', 'description' => 'Print that makes an impression.', 'status' => 'active']);
        VendorMembership::firstOrCreate(['vendor_id' => $printVendor->id, 'user_id' => $owner->id], ['role' => 'manager']);
        StoreSetting::firstOrCreate(['vendor_id' => $printVendor->id], ['brand_color' => '#f5edff']);
        $printCategory = Category::firstOrCreate(['vendor_id' => null, 'slug' => 'business-printing'], ['name' => 'Business Printing', 'is_active' => true]);
        foreach ([['Business Cards', 8900], ['Event Flyers · A5', 13350], ['Tri-fold Brochures', 24750], ['Custom Vinyl Banner', 18100]] as [$name,$price]) {
            $product = Product::firstOrCreate(['vendor_id' => $printVendor->id, 'slug' => Str::slug($name)], ['category_id' => $printCategory->id, 'name' => $name, 'price' => $price, 'currency' => 'PKR', 'description' => 'Demo print product. Request a quote for custom specifications.', 'type' => 'print', 'status' => 'published']);
            Inventory::firstOrCreate(['product_id' => $product->id], ['quantity' => 999]);
            PrintingSpecification::firstOrCreate(['product_id' => $product->id], ['sizes' => ['A4', 'A5', 'Custom'], 'paper_types' => ['Uncoated', 'Silk', 'Gloss'], 'finishes' => ['Matte', 'Gloss'], 'quantity_tiers' => [100, 250, 500, 1000]]);
        }
        foreach ([
            ['pending-shop', 'Pending Sample Shop', 'pending'],
            ['suspended-shop', 'Suspended Sample Shop', 'suspended'],
        ] as [$slug, $name, $status]) {
            $email = $slug.'@example.test';
            $sampleOwner = User::firstOrCreate(['email' => $email], ['name' => $name.' Owner', 'password' => 'ChangeThisDemoPassword123!', 'role' => 'vendor']);
            $sampleVendor = Vendor::firstOrCreate(['slug' => $slug], ['owner_id' => $sampleOwner->id, 'name' => $name, 'email' => $email, 'description' => 'Development approval workflow sample.', 'status' => $status]);
            VendorMembership::firstOrCreate(['vendor_id' => $sampleVendor->id, 'user_id' => $sampleOwner->id], ['role' => 'manager']);
            StoreSetting::firstOrCreate(['vendor_id' => $sampleVendor->id], ['brand_color' => '#edf2fb']);
        }
    }
}
