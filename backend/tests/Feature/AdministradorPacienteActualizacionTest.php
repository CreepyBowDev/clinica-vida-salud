<?php

namespace Tests\Feature;

// Reutiliza identidad, CI, versiones, estados, historial y rollback sin duplicar escenarios.
class AdministradorPacienteActualizacionTest extends PacienteActualizacionTest
{
    protected const ROL_OPERADOR = 'Administrador';
}
