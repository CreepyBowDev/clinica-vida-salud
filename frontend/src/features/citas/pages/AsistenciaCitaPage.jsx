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
import '../../../styles/catalogs.css';
import '../../agenda/styles/agenda.css';
import '../styles/reserva.css';
import '../styles/attendance.css';

export default function AsistenciaCitaPage() {
  const { id } = useParams(), { usuario } = useAuth(), { state } = useLocation();
  if (!esIdentificador(id)) return <section className="workspace-page page-message"><h1>Cita no disponible</h1>
    <p>El identificador de la cita no es válido.</p><Link to="/asistencia">Seleccionar una cita</Link></section>;
  return <Asistencia key={`${usuario?.id_usuario}:${usuario?.rol}:${id}`} id={id} rol={usuario?.rol} idActor={usuario?.id_usuario}
    origen={['asistencia', 'historial'].includes(state?.origen) ? state.origen : 'direct'} />;
}

function Asistencia({ id, rol, idActor, origen }) {
  const query = useAgendaQuery(consultarContextoAsistencia, { id, rol }, { kind: 'asistencia' });
  const escritura = useAsistenciaCita(id, rol, idActor), resultadoHeading = useRef(null);
  const [modo, setModo] = useState('LLEGADA');
  const formularioHeading = useRef(null), prepararAusencia = useRef(null), prepararAtencion = useRef(null), modoAnterior = useRef(modo);
  const blocker = useBlocker(escritura.peligro);
  useEffect(() => { if (!escritura.peligro && blocker.state === 'blocked') blocker.reset(); }, [escritura.peligro, blocker]);
  useEffect(() => { if (escritura.status !== 'inicial') resultadoHeading.current?.focus(); }, [escritura.status]);
  useEffect(() => {
    if (modo !== 'LLEGADA') formularioHeading.current?.focus();
    else if (modoAnterior.current === 'ATENCION') prepararAtencion.current?.focus();
    else if (modoAnterior.current === 'INASISTENCIA') prepararAusencia.current?.focus();
    modoAnterior.current = modo;
  }, [modo]);
  useEffect(() => {
    const clear = () => setModo('LLEGADA');
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  const busy = ['guardando', 'consultando'].includes(escritura.status);
  const refrescar = () => { escritura.reset(); setModo('LLEGADA'); query.reload(); };
  const ausencia = escritura.operacion === 'INASISTENCIA';
  const atencion = escritura.operacion === 'ATENCION';
  const nombre = atencion ? 'atención' : ausencia ? 'inasistencia' : 'llegada';
  const vigente = escritura.status === 'inicial' || (['guardada', 'recuperada'].includes(escritura.status)
    && query.data?.asistencia.actualizada_en >= escritura.resultado?.actualizada_en);
  const back = origen === 'asistencia' ? '/asistencia' : origen === 'historial' && rol !== ROLES.MEDICO ? `/recepcion/historial-citas/${id}` : `/citas/${id}`;
  return <section className="workspace-page catalog-page query-detail gestion-cita-page attendance-page">
    <Link className="back-link" to={back}>{origen === 'asistencia' ? 'Volver a seleccionar una cita' : 'Volver al detalle de la cita'}</Link>
    <PageHeading title="Asistencia de la cita" description="Revisa la cita antes de declarar un hecho presencial. Fechas y horarios en America/La_Paz." />
    {blocker.state === 'blocked' && <Alert variant="warning"><p>La {nombre} podría haberse guardado. Salir no revierte la operación.</p>
      <Button variant="secondary" onClick={() => blocker.reset()}>Permanecer y consultar</Button>
      <Button variant="quiet" onClick={() => blocker.proceed()}>Salir sin conservar la comprobación</Button></Alert>}
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
    <div aria-busy={query.loading}>{query.loading ? <QueryMessage loading title="Consultando asistencia…" />
      : query.failure ? <div className="catalog-failure"><Alert><p>{query.failure.message}</p>
        {query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
        {![401, 403, 404, 422].includes(query.failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button>}
      </div> : <>{vigente ? <AsistenciaResumen contexto={query.data} rol={rol} />
        : <p>La programación consultada antes del envío ya no se presenta como vigente. Consulta el estado actual antes de continuar.</p>}
        {escritura.status === 'inicial' && escritura.remaining === 0 && <>
           <div className="attendance-choices">
           {query.data.asistencia.acciones.registrar_inasistencia && modo !== 'INASISTENCIA' && <Button ref={prepararAusencia} variant="secondary" onClick={() => setModo('INASISTENCIA')}>Preparar inasistencia</Button>}
           {query.data.asistencia.acciones.marcar_atendida && modo !== 'ATENCION' && <Button ref={prepararAtencion} variant="secondary" onClick={() => setModo('ATENCION')}>Preparar atención</Button>}
           </div>
          {modo === 'ATENCION' && <><h2 tabIndex="-1" ref={formularioHeading}>Preparación de atención</h2>
            <Button variant="quiet" onClick={() => setModo('LLEGADA')}>Abandonar atención</Button></>}
          {modo === 'INASISTENCIA' && <><h2 tabIndex="-1" ref={formularioHeading}>Preparación de inasistencia</h2>
            <Button variant="quiet" onClick={() => setModo('LLEGADA')}>Abandonar inasistencia</Button></>}
          {((modo === 'LLEGADA' && query.data.asistencia.acciones.confirmar_llegada) || (modo === 'INASISTENCIA' && query.data.asistencia.acciones.registrar_inasistencia) || (modo === 'ATENCION' && query.data.asistencia.acciones.marcar_atendida)) && <ConfirmacionAsistencia
            key={`${modo}:${query.data.asistencia.version_programacion}:${query.data.asistencia.actualizada_en}:${query.data.asistencia.consultado_en}`}
            operacion={modo} contexto={query.data} onGuardar={async entrada => {
              const guardar = modo === 'ATENCION' ? escritura.marcarAtendida : modo === 'INASISTENCIA' ? escritura.registrarInasistencia : escritura.guardar;
              if (await guardar(entrada)) query.reload();
            }} />}
        </>}
        {escritura.status === 'inicial' && escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de preparar otra operación.</p>}
        {!escritura.peligro && <Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Actualizar asistencia</Button>}
      </>}
    </div>
  </section>;
}
