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
            $table->string('slot_name')->nullable()->after('rank');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_cards', function (Blueprint $table) {
            $table->dropColumn('slot_name');
        });
    }
};
