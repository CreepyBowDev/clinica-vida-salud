import { useEffect, useRef, useState } from 'react';
import { Link, useBlocker, useLocation, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PageHeading from '../../../components/PageHeading';
import QueryMessage from '../../../components/QueryMessage';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { useAgendaQuery } from '../../agenda/hooks/useAgendaQuery';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { consultarContextoAsistencia } from '../services/asistenciaCita.service';
import { useAsistenciaCita } from '../hooks/useAsistenciaCita';
import AsistenciaResumen from '../components/AsistenciaResumen';
import ConfirmacionAsistencia from '../components/ConfirmacionAsistencia';
import { citaOperation } from '../../../constants/citaOperations';
import OperacionNoDisponible from '../components/OperacionNoDisponible';
import OperationDraft from '../components/OperationDraft';
import '../../../styles/catalogs.css';
import '../../agenda/styles/agenda.css';
import '../styles/reserva.css';
import '../styles/attendance.css';

export default function AsistenciaCitaPage() {
  const { id } = useParams(), { usuario } = useAuth(), { state, search } = useLocation();
  const entry = citaOperation(search, 'asistencia', usuario?.rol);
  if (!esIdentificador(id)) return <section className="workspace-page page-message"><h1>Cita no disponible</h1>
    <p>El identificador de la cita no es válido.</p><Link to={`/asistencia${entry.search}`}>Seleccionar una cita</Link></section>;
  return <Asistencia key={`${usuario?.id_usuario}:${usuario?.rol}:${id}`} id={id} rol={usuario?.rol} idActor={usuario?.id_usuario}
    origen={['asistencia', 'historial'].includes(state?.origen) ? state.origen : 'direct'} />;
}

function Asistencia({ id, rol, idActor, origen }) {
  const { search, pathname } = useLocation();
  const entry = citaOperation(search, 'asistencia', rol);
  const query = useAgendaQuery(consultarContextoAsistencia, { id, rol }, { kind: 'asistencia' });
  const escritura = useAsistenciaCita(id, rol, idActor), resultadoHeading = useRef(null);
  const [modoLocal, setModo] = useState('LLEGADA');
  const scoped = useRef(false);
  if (entry.operation) scoped.current = true;
  const modo = entry.operation?.mode ?? modoLocal;
  const [dirty, setDirty] = useState(false);
  const [draftRevision, setDraftRevision] = useState(0);
  const formularioHeading = useRef(null), prepararAusencia = useRef(null), prepararAtencion = useRef(null), modoAnterior = useRef(modo);
  const protegido = escritura.peligro || (dirty && escritura.status === 'inicial') || escritura.remaining > 0;
  const blocker = useBlocker(({ currentLocation, nextLocation }) => escritura.peligro || escritura.remaining > 0 ||
    (dirty && (Boolean(entry.operation) || (currentLocation.pathname === nextLocation.pathname && currentLocation.search !== nextLocation.search))));
  useEffect(() => { if (!protegido && blocker.state === 'blocked') blocker.reset(); }, [protegido, blocker]);
  useEffect(() => { if (escritura.status !== 'inicial') resultadoHeading.current?.focus(); }, [escritura.status]);
  useEffect(() => {
    if (modo !== 'LLEGADA') formularioHeading.current?.focus();
    else if (modoAnterior.current === 'ATENCION') prepararAtencion.current?.focus();
    else if (modoAnterior.current === 'INASISTENCIA') prepararAusencia.current?.focus();
    modoAnterior.current = modo;
  }, [modo]);
  useEffect(() => {
    const clear = () => { setModo('LLEGADA'); setDirty(false); setDraftRevision(value => value + 1); };
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
  const refrescar = () => { escritura.reset(); setDirty(false); setModo('LLEGADA'); query.reload(); };
  const ausencia = escritura.operacion === 'INASISTENCIA';
  const atencion = escritura.operacion === 'ATENCION';
  const nombre = atencion ? 'atención' : ausencia ? 'inasistencia' : 'llegada';
  const vigente = escritura.status === 'inicial' || (['guardada', 'recuperada'].includes(escritura.status)
    && query.data?.asistencia.actualizada_en >= escritura.resultado?.actualizada_en);
  const back = entry.operation ? `/asistencia${entry.search}` : origen === 'asistencia' ? '/asistencia' : origen === 'historial' && rol !== ROLES.MEDICO ? `/recepcion/historial-citas/${id}` : `/citas/${id}`;
  return <>{!entry.valid && <OperacionNoDisponible to="/asistencia" />}<section hidden={!entry.valid && blocker.state !== 'blocked' && escritura.status === 'inicial'} className="workspace-page catalog-page query-detail gestion-cita-page attendance-page">
    <Link className="back-link" to={back}>{entry.operation || origen === 'asistencia' ? 'Volver a seleccionar una cita' : 'Volver al detalle de la cita'}</Link>
    <PageHeading title={entry.operation?.label ?? 'Asistencia de la cita'} description="Revisa la cita antes de declarar un hecho presencial. Fechas y horarios en America/La_Paz." />
    {blocker.state === 'blocked' && <Alert variant="warning"><p>{escritura.peligro ? `La ${nombre} podría haberse guardado. Salir no revierte la operación.` : 'Hay una preparación pendiente. Al cambiar de operación en esta cita se conserva en memoria; salir de la cita la descarta.'}</p>
      <Button variant="secondary" onClick={() => blocker.reset()}>Permanecer y consultar</Button>
      <Button variant="quiet" onClick={() => blocker.proceed()}>{blocker.location?.pathname === pathname ? 'Cambiar operación conservando el proceso' : 'Salir sin conservar la comprobación'}</Button></Alert>}
    {escritura.status !== 'inicial' && <section className="attendance-outcome" aria-label={`Resultado de ${nombre}`} aria-busy={busy} aria-live="polite">
      <h2 ref={resultadoHeading} tabIndex="-1">Resultado de {nombre}</h2>
      {busy && <QueryMessage loading title={escritura.status === 'guardando' ? `Guardando ${nombre}…` : 'Consultando estado persistido…'} />}
      {escritura.failure && <Alert><p>{escritura.failure.message}</p>{Object.values(escritura.failure.fields).map((message, i) => <p key={i}>{message}</p>)}</Alert>}
      {escritura.status === 'incierto' && !escritura.failure && <Alert variant="warning"><p>No hay un resultado verificado. Consulta el estado actual; no se reenviará automáticamente.</p></Alert>}
      {escritura.status === 'guardada' && <Alert variant="success"><p>{atencion ? 'Atención guardada. La cita quedó Atendida, sin crear ni modificar documentación clínica; no admite reapertura ordinaria.' : ausencia ? 'Inasistencia guardada. La cita conserva su historial y no admite reapertura ordinaria.' : 'Llegada guardada. La cita quedó Confirmada; no significa que la atención haya finalizado.'}</p></Alert>}
      {escritura.status === 'recuperada' && <Alert variant="warning"><p>Estado actual consultado. No demuestra qué petición concreta causó el último cambio. Revisa nuevamente antes de otra operación.</p></Alert>}
      {escritura.resultado && <p>Resultado verificado: {escritura.resultado.estado} · {formatearInstanteCita(escritura.resultado.inicio_at)} · versión {escritura.resultado.version_programacion}.</p>}
      {escritura.status === 'incierto' && <Button disabled={escritura.remaining > 0} onClick={async () => { if (await escritura.consultar()) query.reload(); }}>Consultar estado actual</Button>}
      {['guardada', 'recuperada', 'error'].includes(escritura.status) && <Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Consultar asistencia y comenzar una nueva revisión</Button>}
      {escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de continuar.</p>}
      {escritura.status === 'guardada' && query.failure && <p>El guardado está confirmado; falta actualizar la consulta de asistencia.</p>}
    </section>}
    <div hidden={!entry.valid} aria-busy={query.loading}>{query.loading ? <QueryMessage loading title="Consultando asistencia…" />
      : query.failure ? <div className="catalog-failure"><Alert><p>{query.failure.message}</p>
        {query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
        {![401, 403, 404, 422].includes(query.failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button>}
      </div> : <>{vigente ? <AsistenciaResumen contexto={query.data} rol={rol} />
        : <p>La programación consultada antes del envío ya no se presenta como vigente. Consulta el estado actual antes de continuar.</p>}
        {escritura.status === 'inicial' && escritura.remaining === 0 && <>
           {dirty && entry.operation && <Button variant="quiet" onClick={() => { setDirty(false); setDraftRevision(value => value + 1); }}>Descartar preparación</Button>}
           {!entry.operation && <div className="attendance-choices">
           {query.data.asistencia.acciones.registrar_inasistencia && modo !== 'INASISTENCIA' && <Button ref={prepararAusencia} variant="secondary" onClick={() => setModo('INASISTENCIA')}>Preparar inasistencia</Button>}
           {query.data.asistencia.acciones.marcar_atendida && modo !== 'ATENCION' && <Button ref={prepararAtencion} variant="secondary" onClick={() => setModo('ATENCION')}>Preparar atención</Button>}
           </div>}
           {!entry.operation && modo === 'ATENCION' && <><h2 tabIndex="-1" ref={formularioHeading}>Preparación de atención</h2>
            <Button variant="quiet" onClick={() => setModo('LLEGADA')}>Abandonar atención</Button></>}
           {!entry.operation && modo === 'INASISTENCIA' && <><h2 tabIndex="-1" ref={formularioHeading}>Preparación de inasistencia</h2>
            <Button variant="quiet" onClick={() => setModo('LLEGADA')}>Abandonar inasistencia</Button></>}
           {entry.operation && !query.data.asistencia.acciones[entry.operation.capability] && <Alert variant="warning"><p>La cita no admite la operación seleccionada. Selecciona otra cita o actualiza su contexto.</p></Alert>}
           {Object.entries({ LLEGADA: 'confirmar_llegada', INASISTENCIA: 'registrar_inasistencia', ATENCION: 'marcar_atendida' }).map(([operation, capability]) => query.data.asistencia.acciones[capability] && (scoped.current || modo === operation) && <OperationDraft key={`${operation}:${draftRevision}`} active={entry.valid && modo === operation} onDirty={() => setDirty(true)}><ConfirmacionAsistencia
            key={`${operation}:${query.data.asistencia.version_programacion}:${query.data.asistencia.actualizada_en}:${query.data.asistencia.consultado_en}`}
            operacion={operation} contexto={query.data} onGuardar={async entrada => {
              setDirty(false);
              const guardar = operation === 'ATENCION' ? escritura.marcarAtendida : operation === 'INASISTENCIA' ? escritura.registrarInasistencia : escritura.guardar;
              if (await guardar(entrada)) query.reload();
            }} /></OperationDraft>)}
        </>}
        {escritura.status === 'inicial' && escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de preparar otra operación.</p>}
        {!escritura.peligro && <Button variant="secondary" disabled={escritura.remaining > 0 || (dirty && Boolean(entry.operation))} onClick={refrescar}>Actualizar asistencia</Button>}
      </>}
    </div>
  </section></>;
}
