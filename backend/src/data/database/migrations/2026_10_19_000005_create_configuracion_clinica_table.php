<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_clinica', function (Blueprint $table): void {
            $table->integer('id_configuracion')->default(1);
            $table->string('nombre_sede', 120);
            $table->text('direccion_sede')->nullable();
            $table->string('zona_horaria', 64)->default('America/La_Paz');
            $table->integer('duracion_turno_minutos')->default(15);
            $table->timestampTz('actualizado_en', 6)->useCurrent();
            $table->primary('id_configuracion');
        });

        DB::statement('ALTER TABLE configuracion_clinica RENAME CONSTRAINT configuracion_clinica_pkey TO configuracion_clinica_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE configuracion_clinica
            ADD CONSTRAINT configuracion_clinica_unica_ck CHECK (id_configuracion = 1),
            ADD CONSTRAINT configuracion_clinica_zona_ck CHECK (zona_horaria = 'America/La_Paz'),
            ADD CONSTRAINT configuracion_clinica_duracion_ck CHECK (duracion_turno_minutos > 0),
            ADD CONSTRAINT configuracion_clinica_nombre_ck CHECK (char_length(btrim(nombre_sede)) > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_clinica');
    }
};
