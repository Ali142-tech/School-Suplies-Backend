<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant ownership of storefront customer accounts. Purely additive: the column is nullable and existing rows are left untouched
 * (NULL = a central marketplace account, or a vendor/school/admin account whose tenant is defined elsewhere). No data is backfilled.
 * Deleting a vendor never deletes its customers' accounts (nullOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('role')->constrained('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
