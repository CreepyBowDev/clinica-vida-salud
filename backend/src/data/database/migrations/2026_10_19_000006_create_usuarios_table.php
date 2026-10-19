<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->bigIncrements('id_usuario');
            $table->bigInteger('id_rol');
            $table->string('correo_acceso', 254);
            $table->string('password_hash', 255)->nullable();
            $table->string('estado_cuenta', 16)->default('Pendiente');
            $table->timestampTz('correo_verificado_en', 6)->nullable();
            $table->boolean('cambio_clave_obligatorio')->default(false);
            $table->integer('intentos_fallidos')->default(0);
            $table->timestampTz('bloqueado_hasta', 6)->nullable();
            $table->timestampTz('creado_en', 6)->useCurrent();
            $table->timestampTz('actualizado_en', 6)->useCurrent();
            $table->string('nombres_personal', 120)->nullable();
            $table->string('apellidos_personal', 120)->nullable();
            $table->foreign('id_rol', 'usuarios_rol_fk')->references('id_rol')->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->index('id_rol', 'usuarios_rol_idx');
        });

        DB::statement('ALTER TABLE usuarios RENAME CONSTRAINT usuarios_pkey TO usuarios_pk');
        DB::statement('CREATE UNIQUE INDEX usuarios_correo_normalizado_uq ON usuarios (lower(btrim(correo_acceso)))');
        DB::statement(<<<'SQL'
            ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_correo_ck CHECK (char_length(btrim(correo_acceso)) > 0),
            ADD CONSTRAINT usuarios_estado_ck CHECK (estado_cuenta IN ('Pendiente', 'Activa', 'Desactivada')),
            ADD CONSTRAINT usuarios_intentos_ck CHECK (intentos_fallidos >= 0),
            ADD CONSTRAINT usuarios_hash_ck CHECK (password_hash IS NULL OR char_length(btrim(password_hash)) > 0),
            ADD CONSTRAINT usuarios_hash_pendiente_ck CHECK (password_hash IS NOT NULL OR estado_cuenta = 'Pendiente'),
            ADD CONSTRAINT usuarios_activa_verificada_ck CHECK (estado_cuenta <> 'Activa' OR (password_hash IS NOT NULL AND correo_verificado_en IS NOT NULL)),
            ADD CONSTRAINT usuarios_nombres_ck CHECK (nombres_personal IS NULL OR char_length(btrim(nombres_personal)) > 0),
            ADD CONSTRAINT usuarios_apellidos_ck CHECK (apellidos_personal IS NULL OR char_length(btrim(apellidos_personal)) > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
