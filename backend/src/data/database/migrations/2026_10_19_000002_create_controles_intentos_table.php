<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controles_intentos', function (Blueprint $table): void {
            $table->bigIncrements('id_control');
            $table->string('clave_ambito_hash', 255);
            $table->timestampTz('inicio_ventana', 6);
            $table->integer('cantidad_intentos')->default(0);
            $table->timestampTz('bloqueado_hasta', 6)->nullable();
            $table->timestampTz('actualizado_en', 6)->useCurrent();
            $table->unique('clave_ambito_hash', 'controles_intentos_clave_ambito_hash_uq');
        });

        DB::statement('ALTER TABLE controles_intentos RENAME CONSTRAINT controles_intentos_pkey TO controles_intentos_pk');
        DB::statement('ALTER TABLE controles_intentos ADD CONSTRAINT controles_intentos_cantidad_ck CHECK (cantidad_intentos >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('controles_intentos');
    }
};
