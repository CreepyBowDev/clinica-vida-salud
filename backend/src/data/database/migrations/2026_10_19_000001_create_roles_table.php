<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->bigIncrements('id_rol');
            $table->string('nombre', 24);
            $table->unique('nombre', 'roles_nombre_uq');
        });

        DB::statement('ALTER TABLE roles RENAME CONSTRAINT roles_pkey TO roles_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE roles ADD CONSTRAINT roles_nombre_ck
            CHECK (nombre IN ('Administrador', 'Recepcionista', 'Médico', 'Paciente'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
