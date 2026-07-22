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
        Schema::create('card_customizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->onDelete('cascade');
            $table->enum('card_side', ['ace', 'king', 'queen', 'jack', 'joker', 'front', 'back']);
            $table->string('composite_image_path');
            $table->string('composite_image_url')->nullable();
            $table->unsignedInteger('width')->default(750);
            $table->unsignedInteger('height')->default(1050);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_customizations');
    }
};
