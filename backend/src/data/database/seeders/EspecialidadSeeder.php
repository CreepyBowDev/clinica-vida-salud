<?php

namespace Clinica\data\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EspecialidadSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('especialidades')->insertOrIgnore([
            ['nombre' => 'Pediatría'],
            ['nombre' => 'Medicina General'],
            ['nombre' => 'Geriatría'],
        ]);
    }
}
