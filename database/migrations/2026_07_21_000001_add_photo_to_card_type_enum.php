<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE order_item_cards MODIFY COLUMN card_type ENUM('trading', 'deck', 'photo') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE order_item_cards MODIFY COLUMN card_type ENUM('trading', 'deck') NOT NULL");
    }
};
