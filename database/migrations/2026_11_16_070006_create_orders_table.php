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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('cash_paid', 12, 2)->default(0);
            $table->decimal('change_due', 12, 2)->default(0);
            $table->string('payment_method')->default('cash');
            $table->text('notes')->nullable();
            $table->enum('status', ['completed', 'cancelled'])->default('completed');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
