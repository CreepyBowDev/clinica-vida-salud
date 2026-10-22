<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultorios', function (Blueprint $table): void {
            $table->timestampTz('actualizado_en', 6)->default(DB::raw('CURRENT_TIMESTAMP'));
        });
    }

    public function down(): void
    {
        Schema::table('consultorios', function (Blueprint $table): void {
            $table->dropColumn('actualizado_en');
        });
    }
};
