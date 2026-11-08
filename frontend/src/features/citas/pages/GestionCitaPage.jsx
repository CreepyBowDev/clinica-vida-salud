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
import { citaOperation } from '../../../constants/citaOperations';
import OperacionNoDisponible from '../components/OperacionNoDisponible';
import OperationDraft from '../components/OperationDraft';
import '../../../styles/catalogs.css';
import '../../agenda/styles/agenda.css';
import '../styles/appointment-management.css';

export default function GestionCitaPage() {
  const { id } = useParams();
  const { usuario } = useAuth();
  const { state, search } = useLocation();
  const entry = citaOperation(search, 'gestion', usuario?.rol);
  if (!esIdentificador(id)) return <section className="workspace-page page-message"><h1>Cita no disponible</h1>
    <p>El identificador de la cita no es válido.</p><Link to={`/recepcion/citas/gestion${entry.search}`}>Seleccionar una cita</Link></section>;
  return <Gestion key={`${usuario?.id_usuario}:${usuario?.rol}:${id}`} id={id} rol={usuario?.rol} historial={state?.origen === 'historial'} />;
}

function Gestion({ id, rol, historial }) {
  const { search, pathname } = useLocation();
  const entry = citaOperation(search, 'gestion', rol);
  const query = useAgendaQuery(consultarContextoGestion, { id, rol }, { kind: 'gestion' });
  const escritura = useGestionCita(id);
  const [modoLocal, setModo] = useState('reprogramacion');
  const scoped = useRef(false);
  if (entry.operation) scoped.current = true;
  const modo = entry.operation?.mode ?? modoLocal;
  const [dirty, setDirty] = useState(false);
  const [draftRevision, setDraftRevision] = useState(0);
  const resultadoHeading = useRef(null);
  const protegido = escritura.peligro || (dirty && escritura.status === 'inicial') || escritura.remaining > 0;
  const blocker = useBlocker(({ currentLocation, nextLocation }) => escritura.peligro || escritura.remaining > 0 ||
    (dirty && (Boolean(entry.operation) || (currentLocation.pathname === nextLocation.pathname && currentLocation.search !== nextLocation.search))));
  useEffect(() => { if (!protegido && blocker.state === 'blocked') blocker.reset(); }, [protegido, blocker]);
  useEffect(() => { if (escritura.status !== 'inicial') resultadoHeading.current?.focus(); }, [escritura.status]);
  useEffect(() => {
    const clear = () => { setModo('reprogramacion'); setDirty(false); setDraftRevision(value => value + 1); };
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  useEffect(() => {
    if (!dirty) return;
    const warn = event => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);
  const busy = ['guardando', 'consultando'].includes(escritura.status);
  const refrescar = () => { escritura.reset(); setDirty(false); setModo('reprogramacion'); query.reload(); };
  const cancelando = escritura.operacion === 'CANCELACION';
  const tituloResultado = cancelando ? 'Resultado de cancelación' : 'Resultado de reprogramación';
  const vistaVigente = escritura.status === 'inicial' || (['guardada', 'recuperada'].includes(escritura.status)
    && query.data?.gestion.actualizada_en >= escritura.resultado?.actualizada_en);
  const back = entry.operation ? (query.data?.gestion.id_paciente ? `/recepcion/pacientes/${query.data.gestion.id_paciente}/citas${entry.search}` : `/recepcion/citas/gestion${entry.search}`) : historial ? `/recepcion/historial-citas/${id}` : `/citas/${id}`;
  return <>{!entry.valid && <OperacionNoDisponible to="/recepcion/citas/gestion" />}
    <section hidden={!entry.valid && blocker.state !== 'blocked' && escritura.status === 'inicial'} className="workspace-page catalog-page query-detail gestion-cita-page appointment-management">
    <Link className="back-link" to={back}>{entry.operation ? 'Volver a seleccionar una cita' : 'Volver al detalle de la cita'}</Link>
    <PageHeading title={entry.operation?.label ?? 'Gestionar cita'} description="Consulta la programación y las condiciones de gestión. Fechas y horarios en America/La_Paz." />
    {blocker.state === 'blocked' && <Alert variant="warning"><p>{escritura.peligro ? `La ${cancelando ? 'cancelación' : 'reprogramación'} podría haberse guardado. Salir no revierte la operación.` : 'Hay una preparación pendiente. Al cambiar de operación en esta cita se conserva en memoria; salir de la cita la descarta.'}</p>
      <Button variant="secondary" onClick={() => blocker.reset()}>Permanecer y consultar</Button>
      <Button variant="quiet" onClick={() => blocker.proceed()}>{blocker.location?.pathname === pathname ? 'Cambiar operación conservando el proceso' : 'Salir sin conservar la comprobación'}</Button></Alert>}
    {escritura.status !== 'inicial' && <section className="management-outcome" aria-label={tituloResultado} aria-busy={busy}>
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
    <div hidden={!entry.valid} aria-busy={query.loading}>{query.loading ? <QueryMessage loading title="Consultando gestión de la cita…" />
      : query.failure ? <div className="catalog-failure"><Alert><p>{query.failure.message}</p>
        {query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
        {![401, 403, 404, 422].includes(query.failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button>}
      </div> : <>{vistaVigente ? <GestionCitaResumen contexto={query.data} />
        : <p>La programación consultada antes del envío ya no se presenta como vigente. Consulta la gestión o el estado actual antes de continuar.</p>}
        {escritura.status === 'inicial' && escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de iniciar otra gestión.</p>}
        {dirty && entry.operation && escritura.status === 'inicial' && <Button variant="quiet" onClick={() => { setDirty(false); setDraftRevision(value => value + 1); }}>Descartar preparación</Button>}
        {entry.operation && !query.data.gestion.acciones[entry.operation.capability] && <Alert variant="warning"><p>La cita no admite la operación seleccionada. Selecciona otra cita o actualiza su contexto.</p></Alert>}
        {escritura.status === 'inicial' && escritura.remaining === 0 && query.data.gestion.acciones.reprogramar && (scoped.current || modo === 'reprogramacion') && <OperationDraft key={`reprogramar:${draftRevision}`} active={entry.valid && modo === 'reprogramacion'} onDirty={() => setDirty(true)}><ReprogramacionCita
          key={`${query.data.gestion.version_programacion}:${query.data.gestion.actualizada_en}`} original={query.data.gestion}
          onGuardar={async entrada => { setDirty(false); if (await escritura.guardar(entrada)) query.reload(); }} /></OperationDraft>}
        {escritura.status === 'inicial' && escritura.remaining === 0 && query.data.gestion.acciones.cancelar && (scoped.current || modo === 'cancelacion') && <OperationDraft key={`cancelar:${draftRevision}`} active={entry.valid && modo === 'cancelacion'} onDirty={() => setDirty(true)}>
          <CancelacionCita key={`${query.data.gestion.version_programacion}:${query.data.gestion.actualizada_en}`} contexto={query.data}
            onVolver={entry.operation ? undefined : () => setModo('reprogramacion')} onGuardar={async entrada => { setDirty(false); if (await escritura.cancelar(entrada)) query.reload(); }} />
          </OperationDraft>}
        {!entry.operation && modo !== 'cancelacion' && escritura.status === 'inicial' && query.data.gestion.acciones.cancelar && <div className="management-cancel-choice"><Button variant="secondary" disabled={escritura.remaining > 0} onClick={() => setModo('cancelacion')}>Preparar cancelación</Button></div>}
        {!escritura.peligro && <Button variant="secondary" disabled={escritura.remaining > 0 || (dirty && Boolean(entry.operation))} onClick={refrescar}>Actualizar gestión</Button>}
      </>}
    </div>
  </section></>;
}
