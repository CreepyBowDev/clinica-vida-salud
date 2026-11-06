export const ROLES = Object.freeze({
  ADMINISTRADOR: 'Administrador',
  RECEPCIONISTA: 'Recepcionista',
  MEDICO: 'Médico',
  PACIENTE: 'Paciente',
});

export const AREAS = Object.freeze({
  [ROLES.ADMINISTRADOR]: { path: '/administracion', label: 'Administración', description: 'Gestiona cuentas, pacientes, citas y configuración de la clínica.', tasks: ['Cuentas y fichas de pacientes', 'Programación, agenda e historial de citas', 'Configuración y bitácora'] },
  [ROLES.RECEPCIONISTA]: { path: '/recepcion', label: 'Recepción', description: 'Acompaña a los pacientes en su atención presencial.', tasks: ['Registro administrativo de pacientes', 'Activación de acceso web', 'Citas y registro de llegadas'] },
  [ROLES.MEDICO]: { path: '/medico', label: 'Área médica', description: 'Un espacio para dar continuidad a la atención de tus pacientes.', tasks: ['Agenda propia', 'Consultas y antecedentes autorizados', 'Solicitudes y revisión de laboratorio'] },
  [ROLES.PACIENTE]: { path: '/paciente', label: 'Mi atención', description: 'Tu espacio personal de atención en Clínica Vida Salud.', tasks: ['Reserva de citas propias', 'Historial e información autorizada', 'Resultados publicados y avisos internos'] },
});

export function destinoSeguro(pathname, rol) {
  if (typeof pathname === 'string' && (pathname === '/pagos' || pathname.startsWith('/pagos/'))) {
    if (![ROLES.PACIENTE, ROLES.ADMINISTRADOR].includes(rol)) return '/inicio';
    if (pathname === '/pagos') return pathname;
    if (pathname === '/pagos/demostracion') return pathname;
    const uuid = '[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}';
    if (rol === ROLES.PACIENTE && (new RegExp(`^/pagos/obligaciones/${uuid}(?:/pagar)?$`).test(pathname)
      || new RegExp(`^/pagos/retorno/(stripe|paypal)/${uuid}$`).test(pathname))) return pathname;
    const match = rol === ROLES.ADMINISTRADOR && pathname.match(new RegExp(`^/pagos/pacientes/([1-9][0-9]{0,18})(?:/obligaciones/${uuid})?$`));
    return match && BigInt(match[1]) <= 9223372036854775807n ? pathname : '/pagos';
  }
  if (pathname === '/reportes') return rol === ROLES.ADMINISTRADOR ? pathname : '/inicio';
  if (pathname === '/recordatorios') return [ROLES.PACIENTE, ROLES.ADMINISTRADOR].includes(rol) ? pathname : '/inicio';
  if (typeof pathname === 'string' && pathname.startsWith('/laboratorio')) {
    if (![ROLES.ADMINISTRADOR, ROLES.MEDICO, ROLES.PACIENTE].includes(rol)) return '/inicio';
    if (pathname === '/laboratorio') return pathname;
    if (pathname === '/laboratorio/solicitar') return [ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol) ? pathname : '/inicio';
    const match = pathname.match(/^\/laboratorio\/(pacientes|consultas|solicitudes)\/([1-9][0-9]{0,18})$/);
    return match && BigInt(match[2]) <= 9223372036854775807n && (match[1] !== 'pacientes' || rol === ROLES.ADMINISTRADOR) ? pathname : '/inicio';
  }
  if (pathname === '/registrar-consulta') return [ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol) ? pathname : '/inicio';
  if (typeof pathname === 'string' && (pathname.startsWith('/historia-clinica') || /^\/citas\/[^/]+\/consulta/.test(pathname))) {
    const interno = [ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol);
    if (!interno && rol !== ROLES.PACIENTE) return '/inicio';
    if (pathname === '/historia-clinica') return pathname;
    const match = pathname.match(/^\/citas\/([1-9][0-9]{0,18})\/consulta(\/(?:versiones|editar|solicitar-estudio))?$/)
      ?? (interno ? pathname.match(/^\/historia-clinica\/pacientes\/([1-9][0-9]{0,18})(\/antecedentes\/versiones)?$/) : null);
    return match && BigInt(match[1]) <= 9223372036854775807n && (interno || !match[2]) ? pathname : '/inicio';
  }
  return ['/inicio', '/mi-perfil', '/mi-perfil/correo', '/mi-cuenta/contrasena', '/administracion/cuentas', '/administracion/cuentas/nueva', '/administracion/medicos', '/administracion/especialidades', '/recepcion/pacientes', '/recepcion/pacientes/nuevo', ...Object.values(AREAS).map((area) => area.path)].includes(pathname)
    || pathname === '/administracion/especialidades/nueva'
    || pathname === '/administracion/medicos/nuevo'
    || pathname === '/administracion/bitacora'
    || ['/recepcion/activacion-web', '/recepcion/citas/nueva', '/recepcion/citas/gestion', '/recepcion/historial-citas'].includes(pathname)
    || pathname === '/asistencia'
    || /^\/citas\/[1-9][0-9]{0,18}\/(gestion|asistencia)$/.test(pathname)
    || /^\/recepcion\/pacientes\/[1-9][0-9]{0,18}\/citas$/.test(pathname)
    || /^\/recepcion\/historial-citas\/[1-9][0-9]{0,18}$/.test(pathname)
    || /^\/administracion\/bitacora\/[1-9][0-9]{0,18}$/.test(pathname)
    || /^\/administracion\/medicos\/[1-9][0-9]{0,18}(?:\/(?:editar|especialidades))?$/.test(pathname)
    || /^\/administracion\/especialidades\/[1-9][0-9]{0,18}(?:\/editar)?$/.test(pathname)
    || /^\/administracion\/cuentas\/[1-9][0-9]{0,18}$/.test(pathname)
    || /^\/recepcion\/pacientes\/[1-9][0-9]{0,18}(?:\/(?:editar|activacion))?$/.test(pathname) ? pathname : '/inicio';
}
