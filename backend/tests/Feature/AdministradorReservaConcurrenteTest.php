<?php

namespace Tests\Feature;

// Misma batería multiproceso, con Administración y Recepción como operadores distintos.
class AdministradorReservaConcurrenteTest extends ReservaConcurrenteTest
{
    protected const ROLES_OPERADORES = ['Administrador', 'Recepcionista'];
}
