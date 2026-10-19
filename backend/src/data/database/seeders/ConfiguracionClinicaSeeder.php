<?php

namespace Clinica\data\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConfiguracionClinicaSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('configuracion_clinica')->insertOrIgnore([
            'id_configuracion' => 1,
            'nombre_sede' => 'Clínica Vida Salud',
        ]);
    }
}
