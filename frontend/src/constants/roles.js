export const ROLES = Object.freeze({
  ADMINISTRADOR: 'Administrador',
  RECEPCIONISTA: 'Recepcionista',
  MEDICO: 'Médico',
  PACIENTE: 'Paciente',
});

export const AREAS = Object.freeze({
  [ROLES.ADMINISTRADOR]: { path: '/administracion', label: 'Administración', description: 'Organiza el acceso y la gestión administrativa de la clínica.', tasks: ['Cuentas del personal y acceso de pacientes', 'Configuración administrativa', 'Reportes y bitácora'] },
  [ROLES.RECEPCIONISTA]: { path: '/recepcion', label: 'Recepción', description: 'Acompaña a los pacientes en su atención presencial.', tasks: ['Registro administrativo de pacientes', 'Activación de acceso web', 'Citas y registro de llegadas'] },
  [ROLES.MEDICO]: { path: '/medico', label: 'Área médica', description: 'Un espacio para dar continuidad a la atención de tus pacientes.', tasks: ['Agenda propia', 'Consultas y antecedentes autorizados', 'Solicitudes y revisión de laboratorio'] },
  [ROLES.PACIENTE]: { path: '/paciente', label: 'Mi atención', description: 'Tu espacio personal de atención en Clínica Vida Salud.', tasks: ['Reserva de citas propias', 'Historial e información autorizada', 'Resultados publicados y avisos internos'] },
});

export function destinoSeguro(pathname) {
  return ['/inicio', '/mi-perfil', '/mi-perfil/correo', '/mi-cuenta/contrasena', '/administracion/cuentas', '/administracion/cuentas/nueva', '/administracion/medicos', '/administracion/especialidades', '/recepcion/pacientes', '/recepcion/pacientes/nuevo', ...Object.values(AREAS).map((area) => area.path)].includes(pathname)
    || pathname === '/administracion/especialidades/nueva'
    || pathname === '/administracion/medicos/nuevo'
    || pathname === '/administracion/bitacora'
    || /^\/administracion\/bitacora\/[1-9][0-9]{0,18}$/.test(pathname)
    || /^\/administracion\/medicos\/[1-9][0-9]{0,18}(?:\/(?:editar|especialidades))?$/.test(pathname)
    || /^\/administracion\/especialidades\/[1-9][0-9]{0,18}(?:\/editar)?$/.test(pathname)
    || /^\/administracion\/cuentas\/[1-9][0-9]{0,18}$/.test(pathname)
    || /^\/recepcion\/pacientes\/[1-9][0-9]{0,18}(?:\/(?:editar|activacion))?$/.test(pathname) ? pathname : '/inicio';
}
