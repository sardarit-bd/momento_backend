<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('address', 'address1');
            $table->string('address2')->nullable()->after('address1');
            $table->string('state')->nullable()->after('city');
            $table->string('country')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('address1', 'address');
            $table->dropColumn(['address2', 'state', 'country']);
        });
    }
};