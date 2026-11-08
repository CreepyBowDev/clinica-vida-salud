import { destinoSeguro, ROLES } from '../../../constants/roles';
import { navigationAtPath, navigationForRole } from '../../../constants/navigation';

const presentationKeys = ['operacion', 'recorrido', 'vista'];

function presentacionValida(pathname, params, rol) {
  if (params.size !== 1) return false;
  const [key, value] = [...params][0];
  const item = navigationAtPath(navigationForRole(rol), pathname, `?${params}`)?.item;
  if (!item?.cases?.length) return false;
  if (item.operation || item.labMode) return key === 'operacion' && value === (item.operation ?? item.labMode);
  if (item.paymentView || item.id === 'pagos') return key === 'vista';
  if (['historia', 'consulta', 'solicitar'].includes(item.id)) return key === 'recorrido'
    && value === (item.id === 'solicitar' ? 'solicitar-estudio' : item.id);
  return false;
}

// Solo modos cerrados de presentación: nunca copiar filtros, secretos o propuestas al state del login.
export function destinoLogin(pathname, search = '') {
  const params = new URLSearchParams([...new URLSearchParams(search)].filter(([key]) => presentationKeys.includes(key)));
  if (!params.size) return pathname;
  return Object.values(ROLES).some(rol => presentacionValida(pathname, params, rol)) ? `${pathname}?${params}` : '/inicio';
}

// Revalidar después de conocer el rol. Mantener la lista de pathnames seguros y sus fallbacks históricos.
export function destinoTrasLogin(from, rol) {
  if (typeof from !== 'string') return '/inicio';
  const index = from.indexOf('?');
  const pathname = index < 0 ? from : from.slice(0, index);
  const safe = destinoSeguro(pathname, rol);
  if (safe !== pathname || index < 0) return safe;
  const params = new URLSearchParams(from.slice(index + 1));
  return presentacionValida(pathname, params, rol) ? `${safe}?${params}` : '/inicio';
}
