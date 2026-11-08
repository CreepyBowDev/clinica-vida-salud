import { matchPath } from 'react-router-dom';
import { ROLES } from './roles';

const { ADMINISTRADOR: A, MEDICO: M, PACIENTE: P } = ROLES;
export const LAB_OPERATIONS = {
  documentos: { label: 'Cargar o corregir documentos de laboratorio', roles: [A], cu: 'CU-25' },
  revision: { label: 'Revisar y autorizar la publicación de resultados', roles: [A, M], cu: 'CU-26' },
  consulta: { label: 'Consultar solicitudes y descargar resultados autorizados', roles: [A, M, P], cu: 'CU-27' },
};
export const CLINICAL_JOURNEYS = {
  historia: { label: 'Consultar la historia clínica autorizada', roles: [A, M, P], to: '/historia-clinica' },
  consulta: { label: 'Registrar o actualizar una consulta médica', roles: [A, M], to: '/registrar-consulta' },
  'solicitar-estudio': { label: 'Solicitar estudios de laboratorio', roles: [A, M], to: '/laboratorio/solicitar' },
};

// Exclusivamente presentación. Ningún valor se transmite a los servicios.
export function clinicalPresentation(pathname, search, role) {
  const params = new URLSearchParams(search);
  const lab = ['/laboratorio', '/laboratorio/pacientes/:id', '/laboratorio/consultas/:id', '/laboratorio/solicitudes/:id'].some(p => matchPath(p, pathname));
  const fixed = matchPath('/registrar-consulta', pathname) || matchPath('/citas/:id/consulta/editar', pathname) ? 'consulta'
    : matchPath('/laboratorio/solicitar', pathname) || matchPath('/citas/:id/consulta/solicitar-estudio', pathname) ? 'solicitar-estudio'
      : matchPath('/historia-clinica/*', pathname) || matchPath('/historia-clinica', pathname) ? 'historia' : null;
  const key = lab ? 'operacion' : 'recorrido', other = lab ? 'recorrido' : 'operacion';
  const values = params.getAll(key), value = values[0] ?? null;
  const catalog = lab ? LAB_OPERATIONS : CLINICAL_JOURNEYS;
  const valid = !params.has(other) && values.length <= 1 && (!values.length || Object.hasOwn(catalog, value) && catalog[value].roles.includes(role))
    && (!fixed || !value || fixed === value) && role !== ROLES.RECEPCIONISTA;
  return { valid, value, lab, label: catalog[value ?? fixed]?.label, to: lab ? '/laboratorio' : CLINICAL_JOURNEYS[fixed ?? value ?? 'historia']?.to,
    search: value ? `?${key}=${value}` : '', fixed };
}
