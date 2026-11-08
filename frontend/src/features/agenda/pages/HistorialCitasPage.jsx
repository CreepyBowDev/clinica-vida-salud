import { Link, useLocation, useParams } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { citaOperation } from '../../../constants/citaOperations';
import OperacionNoDisponible from '../../citas/components/OperacionNoDisponible';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import HistorialCitas from '../components/HistorialCitas';

export default function HistorialCitasPage() {
  const { id } = useParams();
  const { search } = useLocation(), { usuario } = useAuth();
  const entry = citaOperation(search, 'gestion', usuario?.rol);
  if (!entry.valid) return <OperacionNoDisponible to="/recepcion/citas/gestion" />;
  if (!esIdentificador(id)) return <section className="workspace-page page-message"><h1>Historial no disponible</h1>
    <p>El identificador de la ficha no es válido.</p><Link to="/recepcion/historial-citas">Volver a seleccionar paciente</Link></section>;
  return <HistorialCitas key={id} pacienteId={id} operation={entry.operation} operationSearch={entry.search} />;
}
