import { createContext, useContext, useEffect, useState } from 'react';
import { matchPath, Outlet, useBlocker, useLocation } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import { useReservaRegistro } from '../hooks/useReservaRegistro';

const ReservaContext = createContext(null);
export function useReservaWorkspace() { return useContext(ReservaContext); }

export default function ReservaWorkspace() {
  const { usuario } = useAuth();
  const { pathname } = useLocation();
  const reserva = usuario.rol === ROLES.PACIENTE ? '/reservar-cita' : '/recepcion/citas/nueva';
  // Los guards hijos conservan su autorización. Un destino ajeno al actor no
  // debe retener una coordinación invisible tras confirmar su abandono.
  if (![reserva, '/consultar-disponibilidad'].some(path => matchPath(path, pathname))) return <Outlet />;
  return <CoordinacionReserva key={`${usuario.id_usuario}:${usuario.rol}`} />;
}

function CoordinacionReserva() {
  const registro = useReservaRegistro();
  // Cambiar de CU no desmonta la escritura ni su referencia de reconciliación.
  const protegido = registro.peligro || ['preparando', 'revision', 'ausente'].includes(registro.status) || registro.remaining > 0;
  const blocker = useBlocker(protegido);
  useEffect(() => {
    if (!protegido && blocker.state === 'blocked') blocker.reset();
  }, [protegido, blocker]);
  const paciente = useState(null), especialidad = useState(null), medico = useState(null);
  const fecha = useState(''), errorFecha = useState(''), criterios = useState(null);
  const revision = useState(0), epoch = useState(0);
  useEffect(() => {
    const clear = () => {
      paciente[1](null); especialidad[1](null); medico[1](null); criterios[1](null);
      fecha[1](''); errorFecha[1](''); epoch[1](n => n + 1);
    };
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  return <ReservaContext.Provider value={{ registro, blocker, paciente, especialidad, medico, fecha, errorFecha, criterios, revision, epoch }}><Outlet /></ReservaContext.Provider>;
}
