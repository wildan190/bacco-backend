<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('assigned_sales_id')->constrained('users')->cascadeOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        Schema::table('sales_targets', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('users')->cascadeOnDelete();
        });

        // ── Data Safe-Guard: Migrate all existing production records to the primary Admin ──
        $primaryAdminId = DB::table('users')->where('role', 'admin')->oldest('id')->value('id');

        if ($primaryAdminId) {
            DB::table('users')->where('role', 'sales')->whereNull('admin_id')->update(['admin_id' => $primaryAdminId]);
            DB::table('stores')->whereNull('admin_id')->update(['admin_id' => $primaryAdminId]);
            DB::table('products')->whereNull('admin_id')->update(['admin_id' => $primaryAdminId]);
            DB::table('sales_targets')->whereNull('admin_id')->update(['admin_id' => $primaryAdminId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });

        Schema::table('sales_targets', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });
    }
};
