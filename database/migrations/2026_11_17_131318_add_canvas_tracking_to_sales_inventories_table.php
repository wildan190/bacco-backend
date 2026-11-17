<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_inventories', function (Blueprint $table) {
            $table->string('status')->default('active')->after('notes'); // active, settled
            $table->timestamp('settled_at')->nullable()->after('status');
        });

        Schema::table('sales_inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_sold')->default(0)->after('quantity_carried');
            $table->unsignedInteger('quantity_returned')->default(0)->after('quantity_sold');
        });
    }

    public function down(): void
    {
        Schema::table('sales_inventories', function (Blueprint $table) {
            $table->dropColumn(['status', 'settled_at']);
        });

        Schema::table('sales_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['quantity_sold', 'quantity_returned']);
        });
    }
};
