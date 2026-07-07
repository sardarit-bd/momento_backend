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
         Schema::create('trading_card_packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); 
            $table->string('name'); 
            $table->string('tag')->nullable(); 
            $table->string('subtitle')->nullable();
            $table->unsignedInteger('card_count'); 
            $table->unsignedInteger('price_cents');
            $table->json('features')->nullable();
            $table->boolean('recommended')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_card_packages');
    }
};
