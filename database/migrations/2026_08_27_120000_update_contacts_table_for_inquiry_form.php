<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contacts MODIFY sub VARCHAR(255) NULL');
        DB::statement('ALTER TABLE contacts MODIFY mes TEXT NULL');

        Schema::table('contacts', function ($table) {
            if (! Schema::hasColumn('contacts', 'inquiry_type')) {
                $table->string('inquiry_type')->nullable()->after('email');
            }
            if (! Schema::hasColumn('contacts', 'category')) {
                $table->string('category')->nullable()->after('inquiry_type');
            }
            if (! Schema::hasColumn('contacts', 'company')) {
                $table->string('company')->nullable()->after('category');
            }
            if (! Schema::hasColumn('contacts', 'event_date')) {
                $table->string('event_date')->nullable()->after('company');
            }
            if (! Schema::hasColumn('contacts', 'quantity')) {
                $table->string('quantity')->nullable()->after('event_date');
            }
            if (! Schema::hasColumn('contacts', 'message')) {
                $table->text('message')->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function ($table) {
            $table->dropColumn([
                'inquiry_type',
                'category',
                'company',
                'event_date',
                'quantity',
                'message',
            ]);
        });

        DB::statement("ALTER TABLE contacts MODIFY sub VARCHAR(255) NOT NULL");
        DB::statement("ALTER TABLE contacts MODIFY mes TEXT NOT NULL");
    }
};