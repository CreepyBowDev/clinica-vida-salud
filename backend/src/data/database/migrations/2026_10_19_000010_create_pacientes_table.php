<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table): void {
            $table->bigIncrements('id_paciente');
            $table->bigInteger('id_usuario')->nullable();
            $table->string('codigo_paciente', 50);
            $table->string('documento_identidad', 50)->nullable();
            $table->string('nombres', 120);
            $table->string('apellidos', 120);
            $table->date('fecha_nacimiento');
            $table->string('sexo', 32)->nullable();
            $table->string('telefono', 40);
            $table->string('correo_contacto', 254)->nullable();
            $table->text('direccion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('creado_en', 6)->useCurrent();
            $table->timestampTz('actualizado_en', 6)->useCurrent();
            $table->foreign('id_usuario', 'pacientes_usuario_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('id_usuario', 'pacientes_usuario_uq');
            $table->unique('codigo_paciente', 'pacientes_codigo_uq');
        });

        DB::statement('ALTER TABLE pacientes RENAME CONSTRAINT pacientes_pkey TO pacientes_pk');
        DB::statement('CREATE UNIQUE INDEX pacientes_documento_uq ON pacientes (btrim(documento_identidad)) WHERE documento_identidad IS NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE pacientes
            ADD CONSTRAINT pacientes_codigo_ck CHECK (char_length(btrim(codigo_paciente)) > 0),
            ADD CONSTRAINT pacientes_documento_ck CHECK (documento_identidad IS NULL OR char_length(btrim(documento_identidad)) > 0),
            ADD CONSTRAINT pacientes_nombres_ck CHECK (char_length(btrim(nombres)) > 0),
            ADD CONSTRAINT pacientes_apellidos_ck CHECK (char_length(btrim(apellidos)) > 0),
            ADD CONSTRAINT pacientes_telefono_ck CHECK (char_length(btrim(telefono)) > 0),
            ADD CONSTRAINT pacientes_correo_ck CHECK (correo_contacto IS NULL OR char_length(btrim(correo_contacto)) > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
