<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('especialidades', function (Blueprint $table): void {
            $table->bigIncrements('id_especialidad');
            $table->string('nombre', 120);
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
        });

        DB::statement('ALTER TABLE especialidades RENAME CONSTRAINT especialidades_pkey TO especialidades_pk');
        DB::statement('ALTER TABLE especialidades ADD CONSTRAINT especialidades_nombre_ck CHECK (char_length(btrim(nombre)) > 0)');
        DB::statement('CREATE UNIQUE INDEX especialidades_nombre_normalizado_uq ON especialidades (lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('especialidades');
    }
};
