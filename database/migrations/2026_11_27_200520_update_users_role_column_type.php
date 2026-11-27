<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Mengubah kolom role menjadi VARCHAR(50) agar aman untuk data existing di production
        DB::statement("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'sales'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback ke ENUM semula jika diperlukan
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('sales', 'admin') NOT NULL DEFAULT 'sales'");
    }
};