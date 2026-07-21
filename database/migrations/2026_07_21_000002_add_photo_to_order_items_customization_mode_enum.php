<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE order_items MODIFY COLUMN customization_mode ENUM('none', 'trading', 'deck', 'photo') NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE order_items MODIFY COLUMN customization_mode ENUM('none', 'trading', 'deck') NOT NULL DEFAULT 'none'");
    }
};
