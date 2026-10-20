import { createContext, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { avanzarSesion, escucharSesionInvalidada } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { cerrarSesion, consultarSesion, iniciarSesion } from '../services/auth.service';
import { cambiarClave, completarClaveTemporal, restablecerClave } from '../services/password.service';

export const AuthContext = createContext(null);

function interpretarSesion(data) {
  if (data?.acceso === 'cambio_clave' && data.cambio_clave_obligatorio === true) {
    return { status: 'restricted', usuario: null, error: null };
  }
  if (data && data.cambio_clave_obligatorio !== true && Object.values(ROLES).includes(data.rol)
    && typeof data.id_usuario === 'string' && /^\d+$/.test(data.id_usuario)
    && typeof data.nombre_visible === 'string' && typeof data.correo_acceso === 'string') {
    return { status: 'authenticated', usuario: {
      id_usuario: data.id_usuario, nombre_visible: data.nombre_visible, correo_acceso: data.correo_acceso, rol: data.rol,
    }, error: null };
  }
  throw new Error('Respuesta de sesión no reconocida.');
}

export function AuthProvider({ children }) {
  const [state, setState] = useState({ status: 'loading', usuario: null, error: null });
  const stateRef = useRef(state);
  const mounted = useRef(false);
  const sequence = useRef(0);
  const pending = useRef(null);
  const changing = useRef(false);

  const commit = useCallback((next) => {
    stateRef.current = next;
    if (mounted.current) setState(next);
  }, []);

  const refreshSession = useCallback(() => {
    if (pending.current) return pending.current;
    if (changing.current) return Promise.resolve();
    const operation = ++sequence.current;
    const request = (async () => {
      try {
        const data = await consultarSesion();
        if (operation === sequence.current) commit(interpretarSesion(data));
      } catch (error) {
        if (operation !== sequence.current) return;
        if (error.response?.status === 401) {
          commit({ status: 'anonymous', usuario: null, error: null });
        } else {
          // No se confunde un fallo de red/403/419/500 con logout; el área queda oculta hasta comprobar.
          commit({ status: 'unavailable', usuario: null, error: 'No pudimos comprobar tu sesión. Revisa tu conexión y vuelve a intentarlo.' });
        }
      }
    })();
    pending.current = request;
    request.finally(() => { if (pending.current === request) pending.current = null; });
    return request;
  }, [commit]);

  useEffect(() => {
    mounted.current = true;
    const unsubscribe = escucharSesionInvalidada(() => {
      sequence.current += 1;
      pending.current = null;
      const expired = ['authenticated', 'restricted'].includes(stateRef.current.status);
      commit({ status: 'anonymous', usuario: null, error: expired ? 'Tu sesión ha finalizado. Inicia sesión para continuar.' : null });
    });
    refreshSession();
    const revalidate = () => {
      if (document.visibilityState === 'visible' && ['authenticated', 'restricted', 'unavailable'].includes(stateRef.current.status)) refreshSession();
    };
    window.addEventListener('focus', revalidate);
    document.addEventListener('visibilitychange', revalidate);
    return () => {
      mounted.current = false;
      unsubscribe();
      window.removeEventListener('focus', revalidate);
      document.removeEventListener('visibilitychange', revalidate);
    };
  }, [commit, refreshSession]);

  const login = useCallback(async (credentials) => {
    if (changing.current) return;
    changing.current = true;
    const operation = ++sequence.current;
    pending.current = null;
    avanzarSesion();
    try {
      const data = await iniciarSesion(credentials);
      if (operation === sequence.current) commit(interpretarSesion(data));
    } finally { changing.current = false; }
  }, [commit]);

  const logout = useCallback(async () => {
    if (changing.current) return;
    changing.current = true;
    const operation = ++sequence.current;
    pending.current = null;
    avanzarSesion();
    try {
      await cerrarSesion();
      if (operation === sequence.current) commit({ status: 'anonymous', usuario: null, error: null });
    } catch (error) {
      if (error.response?.status === 401) {
        commit({ status: 'anonymous', usuario: null, error: null });
      } else throw error;
    } finally { changing.current = false; }
  }, [commit]);

  // Las escrituras de credenciales invalidan comprobaciones antiguas y se reconcilian con /me.
  // Recuperar otra cuenta nunca cierra por suposición la sesión actual: decide Laravel.
  const updatePassword = useCallback(async (data, recovery = false) => {
    if (changing.current) throw new Error('Ya hay una operación de sesión en curso.');
    changing.current = true;
    sequence.current += 1;
    pending.current = null;
    avanzarSesion();
    let confirmed = false;
    try {
      await (recovery ? restablecerClave(data) : cambiarClave(data));
      confirmed = true;
    } finally { changing.current = false; }
    if (confirmed) {
      sequence.current += 1;
      pending.current = null;
      avanzarSesion();
      await refreshSession();
    }
  }, [refreshSession]);

  // Coordina escrituras que pueden revocar la propia sesión. El contexto no almacena su resultado.
  const mutateAccount = useCallback(async (request, onConfirmed) => {
    if (changing.current) throw new Error('Ya hay una operación de sesión en curso.');
    changing.current = true;
    sequence.current += 1;
    pending.current = null;
    avanzarSesion();
    let confirmed = false;
    try {
      const result = await request();
      confirmed = true;
      onConfirmed?.(result);
    } finally {
      changing.current = false;
      if (confirmed) {
        sequence.current += 1;
        pending.current = null;
        avanzarSesion();
        await refreshSession();
      }
    }
  }, [refreshSession]);

  const completeTemporaryPassword = useCallback(async (data) => {
    if (changing.current) throw new Error('Ya hay una operación de sesión en curso.');
    changing.current = true;
    const operation = ++sequence.current;
    pending.current = null;
    avanzarSesion();
    try {
      await completarClaveTemporal(data);
      if (operation === sequence.current) {
        avanzarSesion();
        commit({ status: 'anonymous', usuario: null, error: null, notice: 'clave_temporal_actualizada' });
      }
    } finally { changing.current = false; }
  }, [commit]);

  const value = useMemo(() => ({ ...state, loading: state.status === 'loading',
    isAuthenticated: state.status === 'authenticated', isRestricted: state.status === 'restricted',
    login, logout, refreshSession, updatePassword, mutateAccount, completeTemporaryPassword,
  }), [state, login, logout, refreshSession, updatePassword, mutateAccount, completeTemporaryPassword]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
