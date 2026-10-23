import { Link, useParams } from 'react-router-dom';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import HistorialCitas from '../components/HistorialCitas';

export default function HistorialCitasPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="workspace-page page-message"><h1>Historial no disponible</h1>
    <p>El identificador de la ficha no es válido.</p><Link to="/recepcion/historial-citas">Volver a seleccionar paciente</Link></section>;
  return <HistorialCitas key={id} pacienteId={id} />;
}
