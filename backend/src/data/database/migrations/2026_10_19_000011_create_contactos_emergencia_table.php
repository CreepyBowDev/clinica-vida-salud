<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contactos_emergencia', function (Blueprint $table): void {
            $table->bigIncrements('id_contacto');
            $table->bigInteger('id_paciente');
            $table->string('nombre', 240);
            $table->string('parentesco', 80);
            $table->string('telefono', 40);
            $table->foreign('id_paciente', 'contactos_emergencia_paciente_fk')->references('id_paciente')->on('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('id_paciente', 'contactos_emergencia_paciente_uq');
        });

        DB::statement('ALTER TABLE contactos_emergencia RENAME CONSTRAINT contactos_emergencia_pkey TO contactos_emergencia_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE contactos_emergencia
            ADD CONSTRAINT contactos_emergencia_nombre_ck CHECK (char_length(btrim(nombre)) > 0),
            ADD CONSTRAINT contactos_emergencia_parentesco_ck CHECK (char_length(btrim(parentesco)) > 0),
            ADD CONSTRAINT contactos_emergencia_telefono_ck CHECK (char_length(btrim(telefono)) > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contactos_emergencia');
    }
};
