import { Link, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PageHeading from '../../../components/PageHeading';
import QueryMessage from '../../../components/QueryMessage';
import { useAuth } from '../../../hooks/useAuth';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import CitaDetalle from '../components/CitaDetalle';
import { useAgendaQuery } from '../hooks/useAgendaQuery';
import { consultarCita } from '../services/agenda.service';
import { useAgendaWorkspace } from '../components/AgendaWorkspace';
import { ROLES } from '../../../constants/roles';
import '../../../styles/catalogs.css';
import '../styles/agenda.css';

export default function CitaDetallePage({ origin = 'direct' }) {
  const { id } = useParams();
  const { usuario } = useAuth();
  if (!esIdentificador(id)) return <section className="workspace-page page-message"><h1>Cita no disponible</h1>
    <p>El identificador de la cita no es válido.</p><Link to="/inicio">Volver a Inicio</Link></section>;
  return <Detalle key={`${usuario?.id_usuario}:${usuario?.rol}:${id}:${origin}`} id={id} rol={usuario?.rol} origin={origin} />;
}

function Detalle({ id, rol, origin }) {
  const query = useAgendaQuery(consultarCita, { id, rol });
  const { workspace } = useAgendaWorkspace();
  const fromAgenda = origin === 'direct' && workspace.detailSource === 'agenda' && rol !== ROLES.PACIENTE;
  const patientId = query.data?.paciente.id_paciente ?? (workspace.detailSource === 'historial' && esIdentificador(workspace.historial?.owner) ? workspace.historial.owner : null);
  const back = origin === 'mis-citas' ? { to: '/mis-citas', label: 'Volver a Mis citas' }
    : origin === 'historial' ? { to: patientId ? `/recepcion/pacientes/${patientId}/citas` : '/recepcion/historial-citas', label: patientId ? 'Volver al historial del paciente' : 'Volver a seleccionar paciente' }
      : fromAgenda ? { to: '/agenda', label: 'Volver a agenda médica' } : { to: '/inicio', label: 'Volver a Inicio' };
  return <section className="workspace-page catalog-page query-detail">
    <Link className="back-link" to={back.to}>{back.label}</Link>
    <PageHeading title="Detalle de cita" description="Consulta en solo lectura. Fechas y horarios de atención en America/La_Paz." />
    <div aria-busy={query.loading}>{query.loading ? <QueryMessage loading title="Consultando cita…" /> : query.failure ? <div className="catalog-failure">
      <Alert><p>{query.failure.message}</p>{query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
      {![401, 403, 404, 422].includes(query.failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button>}
    </div> : <CitaDetalle cita={query.data} rol={rol} />}</div>
  </section>;
}
