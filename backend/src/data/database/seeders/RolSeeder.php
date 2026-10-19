<?php

namespace Clinica\data\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->insertOrIgnore([
            ['nombre' => 'Administrador'],
            ['nombre' => 'Recepcionista'],
            ['nombre' => 'Médico'],
            ['nombre' => 'Paciente'],
        ]);
    }
}
