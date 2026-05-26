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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('trading_box_pack_title', 50)->nullable()->after('customized_file');
            $table->string('trading_box_created_for', 50)->nullable()->after('trading_box_pack_title');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['trading_box_pack_title', 'trading_box_created_for']);
        });
    }
};
