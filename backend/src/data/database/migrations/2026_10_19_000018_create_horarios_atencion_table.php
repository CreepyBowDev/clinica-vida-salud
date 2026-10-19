<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios_atencion', function (Blueprint $table): void {
            $table->bigIncrements('id_horario');
            $table->bigInteger('id_medico');
            $table->integer('dia_semana');
            $table->time('hora_inicio', 0);
            $table->time('hora_fin', 0);
            $table->boolean('activo')->default(true);
            $table->foreign('id_medico', 'horarios_atencion_medico_fk')->references('id_medico')->on('medicos')->restrictOnDelete()->restrictOnUpdate();
            $table->index(['id_medico', 'dia_semana'], 'horarios_atencion_medico_dia_idx');
        });

        DB::statement('ALTER TABLE horarios_atencion RENAME CONSTRAINT horarios_atencion_pkey TO horarios_atencion_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE horarios_atencion
            ADD CONSTRAINT horarios_atencion_dia_ck CHECK (dia_semana BETWEEN 1 AND 7),
            ADD CONSTRAINT horarios_atencion_intervalo_ck CHECK (hora_fin > hora_inicio)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios_atencion');
    }
};
