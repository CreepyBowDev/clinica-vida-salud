<?php

namespace Tests\Feature;

// Misma regresión HTTP y persistencia, ahora con el operador Administrador.
class AdministradorPacienteAdministrativoTest extends PacienteAdministrativoTest
{
    protected const ROL_OPERADOR = 'Administrador';
}
