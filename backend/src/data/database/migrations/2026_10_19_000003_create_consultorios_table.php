<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultorios', function (Blueprint $table): void {
            $table->bigIncrements('id_consultorio');
            $table->string('codigo_consultorio', 50);
            $table->string('nombre', 120);
            $table->boolean('activo')->default(true);
            $table->unique('codigo_consultorio', 'consultorios_codigo_uq');
        });

        DB::statement('ALTER TABLE consultorios RENAME CONSTRAINT consultorios_pkey TO consultorios_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE consultorios
            ADD CONSTRAINT consultorios_codigo_ck CHECK (char_length(btrim(codigo_consultorio)) > 0),
            ADD CONSTRAINT consultorios_nombre_ck CHECK (char_length(btrim(nombre)) > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consultorios');
    }
};
