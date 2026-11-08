import { createContext, useContext, useEffect, useState } from 'react';
import { Outlet } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';

const AgendaContext = createContext(null);
const inicial = () => ({ selectedId: '', draft: { estado: '', per_page: '20' }, filters: { page: 1, per_page: 20 },
  catalog: { nombre: '', filters: { page: 1, per_page: 5 } }, detailSource: null });

// Ámbito local de rutas: solo criterios en memoria, nunca copias de citas/Pacientes.
export default function AgendaWorkspace({ children }) {
  const { usuario } = useAuth();
  return <Workspace key={`${usuario?.id_usuario}:${usuario?.rol}`}>{children}</Workspace>;
}
function Workspace({ children }) {
  const [workspace, setWorkspace] = useState(inicial);
  useEffect(() => {
    const clear = () => setWorkspace(inicial());
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  return <AgendaContext.Provider value={{ workspace, setWorkspace }}>{children ?? <Outlet />}</AgendaContext.Provider>;
}
export function useAgendaWorkspace() { return useContext(AgendaContext); }
