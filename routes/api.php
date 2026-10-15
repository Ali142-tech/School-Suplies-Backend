<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PrintingController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\VendorApplicationController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VendorPageController;
use App\Http\Controllers\Api\VendorRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1,customer-register');
    Route::post('/auth/vendor/register', [VendorRegistrationController::class, 'store'])->middleware('throttle:4,1,vendor-register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:8,1,login');
    Route::prefix('school')->middleware('store.tenant')->group(function () {
        Route::post('/register', [SchoolController::class, 'register'])->middleware('throttle:6,1,school-register');
        Route::post('/login', [SchoolController::class, 'login'])->middleware('throttle:8,1,school-login');
        Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
            Route::get('/me', [SchoolController::class, 'me']);
            Route::put('/profile', [SchoolController::class, 'updateProfile']);
            Route::post('/logout', [SchoolController::class, 'logout']);
            Route::get('/products', [SchoolController::class, 'products']);
            Route::post('/orders', [SchoolController::class, 'checkout'])->middleware('throttle:10,1,school-checkout');
            Route::get('/orders', [SchoolController::class, 'orders']);
            Route::get('/orders/{order}', [SchoolController::class, 'showOrder']);
        });
    });
    Route::prefix('site')->middleware(['store.tenant', 'throttle:site-public'])->group(function () {
        Route::get('/', [SiteController::class, 'show']);
        Route::get('/pages/{slug}', [SiteController::class, 'page'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    });
    Route::post('/auth/password/forgot', [AuthController::class, 'forgot'])->middleware('throttle:3,1,password-forgot');
    Route::post('/auth/password/reset', [AuthController::class, 'reset'])->middleware('throttle:6,1,password-reset');
    // Shared storefront endpoints. On a vendor domain (X-Store-Host) they are confined to that verified vendor; without it they are the marketplace.
    Route::middleware('store.tenant:optional')->group(function () {
        Route::get('/vendors', [CatalogController::class, 'vendors']);
        Route::get('/vendors/resolve', [CatalogController::class, 'resolve']);
        Route::get('/vendors/{slug}', [CatalogController::class, 'vendor']);
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::get('/products', [CatalogController::class, 'products']);
        Route::get('/products/{product}', [CatalogController::class, 'product']);
        Route::get('/printing/products', [PrintingController::class, 'products']);
        Route::post('/printing/quotes', [PrintingController::class, 'requestQuote'])->middleware(['auth.optional', 'throttle:10,1,print-quote']);
    });

    Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/notifications', [AuthController::class, 'notifications']);
        Route::patch('/notifications/{notification}', [AuthController::class, 'readNotification']);
        Route::post('/vendors/apply', [VendorApplicationController::class, 'store'])->middleware('throttle:5,1,vendor-apply');
        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::put('/addresses/{address}', [AddressController::class, 'update']);
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);
        Route::middleware('store.tenant:optional')->group(function () {
            Route::get('/cart', [CartController::class, 'show']);
            Route::post('/cart/items', [CartController::class, 'add']);
            Route::patch('/cart/items/{item}', [CartController::class, 'update']);
            Route::delete('/cart/items/{item}', [CartController::class, 'remove']);
            Route::post('/checkout', [OrderController::class, 'checkout'])->middleware('throttle:10,1,checkout');
        });
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::get('/printing/quotes/{quotation}', [PrintingController::class, 'quoteStatus']);
        Route::get('/printing/quotes/{quotation}/artwork/{artwork}', [PrintingController::class, 'customerArtwork']);

        Route::prefix('vendor/{vendor}')->middleware('vendor.member')->group(function () {
            Route::get('/application', [VendorController::class, 'application']);
            Route::post('/reapply', [VendorController::class, 'reapply'])->middleware('throttle:4,1,vendor-reapply');
            Route::get('/dashboard', [VendorController::class, 'dashboard']);
            Route::get('/products', [VendorController::class, 'products']);
            Route::get('/categories', [VendorController::class, 'categories']);
            Route::get('/pages', [VendorPageController::class, 'index']);
            Route::get('/pages/{page}', [VendorPageController::class, 'show'])->whereNumber('page');
            Route::get('/pages/{page}/preview', [VendorPageController::class, 'preview'])->whereNumber('page');
            Route::middleware('vendor.approved')->group(function () {
                Route::post('/pages', [VendorPageController::class, 'store'])->middleware('throttle:cms-write');
                Route::put('/pages/{page}', [VendorPageController::class, 'update'])->whereNumber('page')->middleware('throttle:cms-write');
                Route::delete('/pages/{page}', [VendorPageController::class, 'destroy'])->whereNumber('page');
                Route::post('/pages/{page}/publish', [VendorPageController::class, 'publish'])->whereNumber('page')->middleware('throttle:cms-write');
                Route::post('/pages/{page}/unpublish', [VendorPageController::class, 'unpublish'])->whereNumber('page')->middleware('throttle:cms-write');
                Route::post('/media', [VendorPageController::class, 'media'])->middleware('throttle:cms-media');
                Route::post('/products', [VendorController::class, 'storeProduct']);
                Route::put('/products/{product}', [VendorController::class, 'updateProduct']);
                Route::delete('/products/{product}', [VendorController::class, 'deleteProduct']);
                Route::post('/categories', [VendorController::class, 'storeCategory']);
                Route::put('/categories/{category}', [VendorController::class, 'updateCategory'])->whereNumber('category');
                Route::delete('/categories/{category}', [VendorController::class, 'deleteCategory'])->whereNumber('category');
                Route::post('/products/{product}/images', [VendorController::class, 'image']);
                Route::put('/products/{product}/inventory', [VendorController::class, 'inventory']);
                Route::patch('/orders/{order}', [VendorController::class, 'updateOrder']);
                Route::put('/settings', [VendorController::class, 'updateSettings']);
                Route::post('/domains', [VendorController::class, 'addDomain']);
                Route::delete('/domains/{domain}', [VendorController::class, 'removeDomain']);
                Route::post('/members', [VendorController::class, 'addMember']);
                Route::delete('/members/{membership}', [VendorController::class, 'removeMember']);
                Route::patch('/printing/quotes/{quotation}', [PrintingController::class, 'respond']);
                Route::get('/orders', [VendorController::class, 'orders']);
                Route::get('/schools', [VendorController::class, 'schools']);
                Route::patch('/schools/{school}', [VendorController::class, 'updateSchool']);
                Route::get('/printing/quotes', [PrintingController::class, 'vendorQuotes']);
            });
            Route::get('/settings', [VendorController::class, 'settings']);
            Route::get('/domains', [VendorController::class, 'domains']);
            Route::get('/members', [VendorController::class, 'members']);
            Route::get('/printing/quotes/{quotation}/artwork/{artwork}', [PrintingController::class, 'vendorArtwork']);
        });

        Route::prefix('admin')->middleware('platform.admin')->group(function () {
            Route::get('/overview', [AdminController::class, 'overview']);
            Route::get('/settings', [AdminController::class, 'settings']);
            Route::put('/settings', [AdminController::class, 'updateSettings']);
            Route::get('/vendors', [AdminController::class, 'vendors']);
            Route::patch('/vendors/{vendor}', [AdminController::class, 'updateVendor']);
            Route::get('/vendor-requests', [AdminController::class, 'vendorRequests']);
            Route::get('/vendor-requests/{vendor}', [AdminController::class, 'vendorRequest']);
            Route::post('/vendor-requests/{vendor}/approve', [AdminController::class, 'approveVendor']);
            Route::post('/vendor-requests/{vendor}/reject', [AdminController::class, 'rejectVendor']);
            Route::patch('/vendors/{vendor}/suspend', [AdminController::class, 'suspendVendor']);
            Route::patch('/vendors/{vendor}/reactivate', [AdminController::class, 'reactivateVendor']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
            Route::get('/products', [AdminController::class, 'products']);
            Route::patch('/products/{product}', [AdminController::class, 'updateProduct']);
            Route::get('/categories', [AdminController::class, 'categories']);
            Route::post('/categories', [AdminController::class, 'createCategory']);
            Route::patch('/categories/{category}', [AdminController::class, 'updateCategory']);
            Route::get('/orders', [AdminController::class, 'orders']);
            Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
            Route::get('/domains', [AdminController::class, 'domains']);
            Route::patch('/domains/{domain}', [AdminController::class, 'updateDomain']);
            Route::post('/domains/{domain}/activate', [AdminController::class, 'activateDomain']);
        });
    });
});
