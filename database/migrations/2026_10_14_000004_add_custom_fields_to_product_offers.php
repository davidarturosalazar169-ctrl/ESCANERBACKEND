<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_offers', function (Blueprint $table): void {
            $table->json('custom_fields')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table): void {
            $table->dropColumn('custom_fields');
        });
    }
};
