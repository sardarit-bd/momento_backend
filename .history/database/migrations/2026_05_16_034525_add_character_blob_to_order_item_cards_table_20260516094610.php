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
        Schema::table('order_item_cards', function (Blueprint $table) {
            $table->longText('character_blob')->nullable()->after('image_blob');
            $table->string('character_mime', 50)->nullable()->after('character_blob');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_cards', function (Blueprint $table) {
            $table->dropColumn(['character_blob', 'character_mime']);
        });
    }
};
