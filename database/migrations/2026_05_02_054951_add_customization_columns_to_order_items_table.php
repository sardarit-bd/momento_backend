<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->longText('tuckbox_image_blob')->nullable()->after('customization_images');
            $table->string('tuckbox_image_mime', 50)->nullable()->after('tuckbox_image_blob');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['tuckbox_image_blob', 'tuckbox_image_mime']);
        });
    }
};
