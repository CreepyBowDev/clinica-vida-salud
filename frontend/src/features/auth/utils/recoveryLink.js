// Se ejecuta antes de crear el router. El secreto no llega a Router state, DOM o almacenamiento web.
export function capturarEnlaceRecuperacion(pathname = '/recuperar-clave') {
  if (window.location.pathname !== pathname) return { token: null };
  const params = new URLSearchParams(window.location.hash.slice(1));
  const values = params.getAll('token');
  const token = values.length === 1 && /^[a-f0-9]{64}$/.test(values[0]) ? values[0] : null;
  // No se aceptan tokens por query. Se retira cualquier query/fragmento de esta ruta del historial actual.
  window.history.replaceState(window.history.state, '', window.location.pathname);
  return { token };
}
