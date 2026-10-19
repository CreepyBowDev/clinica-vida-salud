<?php

namespace Clinica\business\services;

use Clinica\data\repositories\UsuarioRepository;
use Clinica\shared\errors\AppError;

class ContinuidadAdministrativaService
{
    public function __construct(private UsuarioRepository $usuarios) {}

    public function coordinar(): void
    {
        $this->usuarios->coordinarAdministracion();
    }

    public function verificar(): void
    {
        if (! $this->usuarios->existeAdministradorNormal()) {
            throw new AppError('ULTIMO_ADMINISTRADOR', 'La operación debe conservar al menos un Administrador habilitado para acceso normal.');
        }
    }
}
