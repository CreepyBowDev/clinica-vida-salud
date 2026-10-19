<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicos', function (Blueprint $table): void {
            $table->bigIncrements('id_medico');
            $table->bigInteger('id_usuario')->nullable();
            $table->bigInteger('id_consultorio')->nullable();
            $table->string('codigo_medico', 50);
            $table->string('registro_profesional', 80);
            $table->string('nombres', 120);
            $table->string('apellidos', 120);
            $table->string('telefono', 40);
            $table->string('correo_contacto', 254);
            $table->boolean('activo')->default(true);
            $table->foreign('id_usuario', 'medicos_usuario_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_consultorio', 'medicos_consultorio_fk')->references('id_consultorio')->on('consultorios')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('id_usuario', 'medicos_usuario_uq');
            $table->unique('codigo_medico', 'medicos_codigo_uq');
            $table->index('id_consultorio', 'medicos_consultorio_idx');
        });

        DB::statement('ALTER TABLE medicos RENAME CONSTRAINT medicos_pkey TO medicos_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE medicos
            ADD CONSTRAINT medicos_codigo_ck CHECK (char_length(btrim(codigo_medico)) > 0),
            ADD CONSTRAINT medicos_registro_ck CHECK (char_length(btrim(registro_profesional)) > 0),
            ADD CONSTRAINT medicos_nombres_ck CHECK (char_length(btrim(nombres)) > 0),
            ADD CONSTRAINT medicos_apellidos_ck CHECK (char_length(btrim(apellidos)) > 0),
            ADD CONSTRAINT medicos_telefono_ck CHECK (char_length(btrim(telefono)) > 0),
            ADD CONSTRAINT medicos_correo_ck CHECK (char_length(btrim(correo_contacto)) > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('medicos');
    }
};
