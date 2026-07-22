<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a JSON column to order_items that stores the photo portrait box
     * source images together with their user-adjusted drag/zoom positions.
     * This lets the TGC publish job regenerate the box on the photo portrait
     * template instead of relying solely on the pre-composited PNG.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'photo_box_images')) {
                $table->json('photo_box_images')->nullable()->after('tuckbox_image_mime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'photo_box_images')) {
                $table->dropColumn('photo_box_images');
            }
        });
    }
};
