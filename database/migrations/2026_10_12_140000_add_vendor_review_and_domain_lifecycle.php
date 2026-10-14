<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('requested_slug')->nullable()->after('slug')->index();
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->timestamp('approved_at')->nullable()->after('reviewed_at');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });

        Schema::table('store_domains', function (Blueprint $table) {
            $table->string('provisioning_status')->default('manual_pending')->after('is_verified')->index();
            $table->string('source')->default('custom')->after('provisioning_status');
            $table->boolean('is_primary')->default(false)->after('source');
            $table->timestamp('verified_at')->nullable()->after('is_primary');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('store_domains', function (Blueprint $table) {
            $table->dropIndex(['provisioning_status']);
            $table->dropColumn(['provisioning_status', 'source', 'is_primary', 'verified_at']);
        });
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['requested_slug']);
            $table->dropColumn(['requested_slug', 'reviewed_by', 'reviewed_at', 'approved_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
