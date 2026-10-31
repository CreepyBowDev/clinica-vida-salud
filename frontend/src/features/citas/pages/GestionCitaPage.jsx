import { useEffect, useRef, useState } from 'react';
import { Link, useBlocker, useLocation, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PageHeading from '../../../components/PageHeading';
import QueryMessage from '../../../components/QueryMessage';
import { useAuth } from '../../../hooks/useAuth';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { useAgendaQuery } from '../../agenda/hooks/useAgendaQuery';
import { consultarContextoGestion } from '../services/gestionCita.service';
import GestionCitaResumen from '../components/GestionCitaResumen';
import ReprogramacionCita from '../components/ReprogramacionCita';
import CancelacionCita from '../components/CancelacionCita';
import { useGestionCita } from '../hooks/useGestionCita';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import '../../../styles/catalogs.css';
import '../../agenda/styles/agenda.css';

export default function GestionCitaPage() {
  const { id } = useParams();
  const { usuario } = useAuth();
  const { state } = useLocation();
  if (!esIdentificador(id)) return <section className="workspace-page page-message"><h1>Cita no disponible</h1>
    <p>El identificador de la cita no es válido.</p><Link to="/recepcion/citas/gestion">Seleccionar una cita</Link></section>;
  return <Gestion key={`${usuario?.id_usuario}:${usuario?.rol}:${id}`} id={id} rol={usuario?.rol} historial={state?.origen === 'historial'} />;
}

function Gestion({ id, rol, historial }) {
  const query = useAgendaQuery(consultarContextoGestion, { id, rol }, { kind: 'gestion' });
  const escritura = useGestionCita(id);
  const [modo, setModo] = useState('reprogramacion');
  const resultadoHeading = useRef(null);
  const blocker = useBlocker(escritura.peligro);
  useEffect(() => { if (!escritura.peligro && blocker.state === 'blocked') blocker.reset(); }, [escritura.peligro, blocker]);
  useEffect(() => { if (escritura.status !== 'inicial') resultadoHeading.current?.focus(); }, [escritura.status]);
  useEffect(() => {
    const clear = () => setModo('reprogramacion');
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  const busy = ['guardando', 'consultando'].includes(escritura.status);
  const refrescar = () => { escritura.reset(); setModo('reprogramacion'); query.reload(); };
  const cancelando = escritura.operacion === 'CANCELACION';
  const tituloResultado = cancelando ? 'Resultado de cancelación' : 'Resultado de reprogramación';
  const vistaVigente = escritura.status === 'inicial' || (['guardada', 'recuperada'].includes(escritura.status)
    && query.data?.gestion.actualizada_en >= escritura.resultado?.actualizada_en);
  return <section className="workspace-page catalog-page query-detail gestion-cita-page">
    <Link className="back-link" to={historial ? `/recepcion/historial-citas/${id}` : `/citas/${id}`}>Volver al detalle de la cita</Link>
    <PageHeading title="Gestionar cita" description="Consulta la programación y las condiciones de gestión. Fechas y horarios en America/La_Paz." />
    {blocker.state === 'blocked' && <Alert variant="warning"><p>La {cancelando ? 'cancelación' : 'reprogramación'} podría haberse guardado. Salir no revierte la operación.</p>
      <Button variant="secondary" onClick={() => blocker.reset()}>Permanecer y consultar</Button>
      <Button variant="quiet" onClick={() => blocker.proceed()}>Salir sin conservar la comprobación</Button></Alert>}
    {escritura.status !== 'inicial' && <section aria-label={tituloResultado} aria-busy={busy}>
      <h2 tabIndex="-1" ref={resultadoHeading}>{tituloResultado}</h2>
      {busy && <QueryMessage loading title={escritura.status === 'guardando' ? (cancelando ? 'Guardando cancelación…' : 'Guardando reprogramación…') : 'Consultando estado persistido…'} />}
      {escritura.failure && <Alert><p>{escritura.failure.message}</p>{Object.values(escritura.failure.fields).map((message, index) => <p key={index}>{message}</p>)}</Alert>}
      {escritura.status === 'incierto' && !escritura.failure && <Alert variant="warning"><p>No hay un resultado verificado de la operación anterior. Consulta el estado actual; no se reenviará automáticamente.</p></Alert>}
      {escritura.status === 'guardada' && <Alert variant="success"><p>{cancelando ? 'Cancelación guardada. La cita quedó Cancelada y conserva su historial.' : 'Reprogramación guardada. La misma cita quedó Programada.'}</p></Alert>}
      {escritura.status === 'recuperada' && <Alert variant="warning"><p>Estado actual consultado. No demuestra qué petición concreta causó el último cambio. Revisa de nuevo antes de otra operación.</p></Alert>}
      {escritura.resultado && <p>Resultado verificado: {escritura.resultado.estado} · {formatearInstanteCita(escritura.resultado.inicio_at)} · versión {escritura.resultado.version_programacion}.</p>}
      {escritura.status === 'incierto' && <Button disabled={escritura.remaining > 0} onClick={async () => { if (await escritura.consultar()) query.reload(); }}>Consultar estado actual</Button>}
      {['guardada', 'recuperada', 'error'].includes(escritura.status) && <Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Consultar gestión y comenzar una nueva revisión</Button>}
      {escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de continuar.</p>}
      {escritura.status === 'guardada' && query.failure && <p>El guardado está confirmado; falta actualizar la consulta de gestión.</p>}
    </section>}
    <div aria-busy={query.loading}>{query.loading ? <QueryMessage loading title="Consultando gestión de la cita…" />
      : query.failure ? <div className="catalog-failure"><Alert><p>{query.failure.message}</p>
        {query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
        {![401, 403, 404, 422].includes(query.failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button>}
      </div> : <>{vistaVigente ? <GestionCitaResumen contexto={query.data} />
        : <p>La programación consultada antes del envío ya no se presenta como vigente. Consulta la gestión o el estado actual antes de continuar.</p>}
        {escritura.status === 'inicial' && escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de iniciar otra gestión.</p>}
        {escritura.status === 'inicial' && escritura.remaining === 0 && modo === 'reprogramacion' && query.data.gestion.acciones.reprogramar && <ReprogramacionCita
          key={`${query.data.gestion.version_programacion}:${query.data.gestion.actualizada_en}`} original={query.data.gestion}
          onGuardar={async entrada => { if (await escritura.guardar(entrada)) query.reload(); }} />}
        {escritura.status === 'inicial' && query.data.gestion.acciones.cancelar && (modo === 'cancelacion'
          ? <CancelacionCita key={`${query.data.gestion.version_programacion}:${query.data.gestion.actualizada_en}`} contexto={query.data}
            onVolver={() => setModo('reprogramacion')} onGuardar={async entrada => { if (await escritura.cancelar(entrada)) query.reload(); }} />
          : <Button variant="secondary" disabled={escritura.remaining > 0} onClick={() => setModo('cancelacion')}>Preparar cancelación</Button>)}
        {!escritura.peligro && <Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Actualizar gestión</Button>}
      </>}
    </div>
  </section>;
}
