<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas_medicas', function (Blueprint $table): void {
            $table->bigIncrements('id_consulta');
            $table->string('codigo_consulta', 50);
            $table->bigInteger('id_cita');
            $table->timestampTz('iniciada_en', 6)->nullable();
            $table->timestampTz('creada_en', 6)->useCurrent();
            $table->unique('codigo_consulta', 'consultas_medicas_codigo_uq');
            $table->unique('id_cita', 'consultas_medicas_cita_uq');
            $table->foreign('id_cita', 'consultas_medicas_cita_fk')->references('id_cita')->on('citas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE consultas_medicas RENAME CONSTRAINT consultas_medicas_pkey TO consultas_medicas_pk');
        DB::statement('ALTER TABLE consultas_medicas ADD CONSTRAINT consultas_medicas_codigo_ck CHECK (char_length(btrim(codigo_consulta)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas_medicas');
    }
};
