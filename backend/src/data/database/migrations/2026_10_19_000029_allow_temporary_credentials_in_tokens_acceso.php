<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE tokens_acceso
            DROP CONSTRAINT tokens_acceso_proposito_ck,
            ADD CONSTRAINT tokens_acceso_proposito_ck
                CHECK (proposito IN ('InvitacionPersonal', 'RecuperacionClave', 'CredencialTemporal'))
            SQL);
    }

    public function down(): void
    {
        // PostgreSQL rechaza la reversión si hay credenciales temporales, sin borrar su historia.
        DB::statement(<<<'SQL'
            ALTER TABLE tokens_acceso
            DROP CONSTRAINT tokens_acceso_proposito_ck,
            ADD CONSTRAINT tokens_acceso_proposito_ck
                CHECK (proposito IN ('InvitacionPersonal', 'RecuperacionClave'))
            SQL);
    }
};
