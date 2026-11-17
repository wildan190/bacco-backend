<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('assigned_sales_id')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->string('schedule_frequency')->nullable()->default(null)->after('assigned_sales_id'); // 'daily', null
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['assigned_sales_id']);
            $table->dropColumn(['assigned_sales_id', 'schedule_frequency']);
        });
    }
};
