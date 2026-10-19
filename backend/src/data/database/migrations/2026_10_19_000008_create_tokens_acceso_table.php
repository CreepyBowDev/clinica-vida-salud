<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens_acceso', function (Blueprint $table): void {
            $table->bigIncrements('id_token');
            $table->bigInteger('id_usuario');
            $table->string('proposito', 24);
            $table->string('token_hash', 255);
            $table->timestampTz('emitido_en', 6);
            $table->timestampTz('expira_en', 6);
            $table->timestampTz('consumido_en', 6)->nullable();
            $table->timestampTz('invalidado_en', 6)->nullable();
            $table->foreign('id_usuario', 'tokens_acceso_usuario_fk')->references('id_usuario')->on('usuarios')->restrictOnDelete()->restrictOnUpdate();
            $table->unique('token_hash', 'tokens_acceso_hash_uq');
            $table->index(['id_usuario', 'proposito'], 'tokens_acceso_usuario_proposito_idx');
        });

        DB::statement('ALTER TABLE tokens_acceso RENAME CONSTRAINT tokens_acceso_pkey TO tokens_acceso_pk');
        DB::statement(<<<'SQL'
            ALTER TABLE tokens_acceso
            ADD CONSTRAINT tokens_acceso_proposito_ck CHECK (proposito IN ('InvitacionPersonal', 'RecuperacionClave')),
            ADD CONSTRAINT tokens_acceso_hash_ck CHECK (char_length(btrim(token_hash)) > 0),
            ADD CONSTRAINT tokens_acceso_vigencia_ck CHECK (expira_en > emitido_en)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens_acceso');
    }
};
