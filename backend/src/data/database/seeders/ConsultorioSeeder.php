<?php

namespace Clinica\data\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConsultorioSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('consultorios')->insertOrIgnore([
            ['codigo_consultorio' => 'CONS-01', 'nombre' => 'Consultorio 1'],
            ['codigo_consultorio' => 'CONS-02', 'nombre' => 'Consultorio 2'],
            ['codigo_consultorio' => 'CONS-03', 'nombre' => 'Consultorio 3'],
        ]);
    }
}
