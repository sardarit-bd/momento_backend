<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'price')) {
                $table->decimal('price', 10, 2)->default(0)->after('quantity');
            }

            if (!Schema::hasColumn('order_items', 'customization_mode')) {
                $table->enum('customization_mode', ['none', 'trading', 'deck'])->default('none')->after('price');
            }

            if (!Schema::hasColumn('order_items', 'card_design_count')) {
                $table->unsignedTinyInteger('card_design_count')->default(0)->after('customization_mode');
            }

            if (!Schema::hasColumn('order_items', 'customization_images')) {
                $table->json('customization_images')->nullable()->after('card_design_count');
            }
        });

        Schema::create('order_item_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();

            // Same value for both front/back of one trading card.
            $table->uuid('card_pair_key')->nullable();

            $table->enum('card_type', ['trading', 'deck']);
            $table->enum('side', ['front', 'back', 'single']);
            $table->enum('rank', ['ace', 'king', 'queen', 'jack', 'joker'])->nullable();
            $table->unsignedTinyInteger('position')->default(1);

            // Store decoded binary image in DB, not base64 string.
            $table->binary('image_blob')->nullable();
            $table->string('image_mime', 64)->nullable();
            $table->unsignedInteger('image_size_bytes')->nullable();
            $table->char('image_sha256', 64)->nullable();

            $table->timestamps();

            $table->index(['order_item_id', 'position']);
            $table->index(['card_type', 'rank', 'side']);
            $table->index('card_pair_key');
        });

        // MySQL/MariaDB: upgrade default BLOB to LONGBLOB for large card renders.
        DB::statement('ALTER TABLE order_item_cards MODIFY image_blob LONGBLOB NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_cards');

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'customization_images')) {
                $table->dropColumn('customization_images');
            }

            if (Schema::hasColumn('order_items', 'card_design_count')) {
                $table->dropColumn('card_design_count');
            }

            if (Schema::hasColumn('order_items', 'customization_mode')) {
                $table->dropColumn('customization_mode');
            }

            if (Schema::hasColumn('order_items', 'price')) {
                $table->dropColumn('price');
            }
        });
    }
};
