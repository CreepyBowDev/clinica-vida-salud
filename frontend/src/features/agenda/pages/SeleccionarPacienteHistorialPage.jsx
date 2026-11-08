import { useEffect, useState } from 'react';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import QueryMessage from '../../../components/QueryMessage';
import { useAuth } from '../../../hooks/useAuth';
import { useLocation, useNavigate } from 'react-router-dom';
import { citaOperation } from '../../../constants/citaOperations';
import OperacionNoDisponible from '../../citas/components/OperacionNoDisponible';

// Reutiliza la búsqueda administrativa sin conservar fichas entre identidades/restauraciones.
export default function SeleccionarPacienteHistorialPage({ gestion = false }) {
  const { usuario } = useAuth();
  const { search } = useLocation(), navigate = useNavigate();
  const entry = citaOperation(search, 'gestion', usuario?.rol);
  const [hidden, setHidden] = useState(false);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const hide = () => { setHidden(true); setRevision(value => value + 1); };
    const show = () => setHidden(false);
    window.addEventListener('pagehide', hide); window.addEventListener('pageshow', show);
    return () => { window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show); };
  }, []);
  if (gestion && !entry.valid) return <OperacionNoDisponible to="/recepcion/citas/gestion" />;
  return hidden ? <QueryMessage loading title="Consulta de pacientes pausada" />
    : <PatientsPage key={`${usuario?.id_usuario}:${usuario?.rol}:${revision}:${gestion}`} historial gestion={gestion}
      {...(gestion && entry.operation ? { tituloSeleccion: entry.operation.label,
        descripcionSeleccion: 'Selecciona un Paciente y después una cita. Las condiciones se consultarán antes de preparar la operación.',
        onSeleccionar: paciente => navigate(`/recepcion/pacientes/${paciente.id_paciente}/citas${entry.search}`) } : {})} />;
}
