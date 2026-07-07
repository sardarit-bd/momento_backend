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
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'has_joker')) {
                $table->boolean('has_joker')->default(false)->after('price');
            }

            if (!Schema::hasColumn('order_items', 'addon_amount')) {
                $table->decimal('addon_amount', 10, 2)->default(0)->after('has_joker');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'addon_amount')) {
                $table->dropColumn('addon_amount');
            }

            if (Schema::hasColumn('order_items', 'has_joker')) {
                $table->dropColumn('has_joker');
            }
        });
    }
};
