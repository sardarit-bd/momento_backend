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
        Schema::create('tgc_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tgc_webhook_event_id')->nullable();
            $table->string('type')->nullable(); // subscribe | unsubscribe | data | test
            $table->string('event')->nullable(); // e.g. ReceiptShipped
            $table->boolean('signature_valid')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['event', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tgc_webhook_logs');
    }
};
