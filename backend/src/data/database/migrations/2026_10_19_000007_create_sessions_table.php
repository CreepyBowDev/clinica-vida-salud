<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id', 255)->primary();
            $table->bigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity');
            $table->foreign('user_id', 'sessions_usuario_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->index('user_id', 'sessions_usuario_idx');
            $table->index('last_activity', 'sessions_actividad_idx');
        });

        DB::statement('ALTER TABLE sessions RENAME CONSTRAINT sessions_pkey TO sessions_pk');
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
