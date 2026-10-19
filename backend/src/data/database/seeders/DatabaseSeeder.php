<?php

namespace Clinica\data\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call([
                RolSeeder::class,
                EspecialidadSeeder::class,
                ConfiguracionClinicaSeeder::class,
                ConsultorioSeeder::class,
            ]);
        });
    }
}
