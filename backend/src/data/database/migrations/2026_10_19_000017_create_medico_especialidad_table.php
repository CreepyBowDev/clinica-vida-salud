<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medico_especialidad', function (Blueprint $table): void {
            $table->bigInteger('id_medico');
            $table->bigInteger('id_especialidad');
            $table->primary(['id_medico', 'id_especialidad']);
            $table->foreign('id_medico', 'medico_especialidad_medico_fk')->references('id_medico')->on('medicos')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_especialidad', 'medico_especialidad_especialidad_fk')->references('id_especialidad')->on('especialidades')->restrictOnDelete()->restrictOnUpdate();
            $table->index('id_especialidad', 'medico_especialidad_especialidad_idx');
        });

        DB::statement('ALTER TABLE medico_especialidad RENAME CONSTRAINT medico_especialidad_pkey TO medico_especialidad_pk');
    }

    public function down(): void
    {
        Schema::dropIfExists('medico_especialidad');
    }
};
