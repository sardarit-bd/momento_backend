<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE order_item_cards MODIFY character_blob LONGBLOB NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_item_cards MODIFY character_blob LONGTEXT NULL');
    }
};