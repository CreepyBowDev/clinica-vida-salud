<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infrastructure_probe', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('valor')->unique();
            $table->timestampTz('registrado_en', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_probe');
    }
};
