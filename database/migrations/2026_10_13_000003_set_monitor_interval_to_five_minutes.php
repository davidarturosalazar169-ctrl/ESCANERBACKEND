<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_monitors', function (Blueprint $table): void {
            $table->unsignedInteger('interval_minutes')->default(5)->change();
        });

        DB::table('product_monitors')
            ->where('interval_minutes', 60)
            ->update(['interval_minutes' => 5]);
    }

    public function down(): void
    {
        Schema::table('product_monitors', function (Blueprint $table): void {
            $table->unsignedInteger('interval_minutes')->default(60)->change();
        });
    }
};
