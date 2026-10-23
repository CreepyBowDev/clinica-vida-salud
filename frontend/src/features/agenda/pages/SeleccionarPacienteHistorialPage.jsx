import { useEffect, useState } from 'react';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import QueryMessage from '../../../components/QueryMessage';
import { useAuth } from '../../../hooks/useAuth';

// Reutiliza la búsqueda administrativa sin conservar fichas entre identidades/restauraciones.
export default function SeleccionarPacienteHistorialPage() {
  const { usuario } = useAuth();
  const [hidden, setHidden] = useState(false);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const hide = () => { setHidden(true); setRevision(value => value + 1); };
    const show = () => setHidden(false);
    window.addEventListener('pagehide', hide); window.addEventListener('pageshow', show);
    return () => { window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show); };
  }, []);
  return hidden ? <QueryMessage loading title="Consulta de pacientes pausada" />
    : <PatientsPage key={`${usuario?.id_usuario}:${usuario?.rol}:${revision}`} historial />;
}
