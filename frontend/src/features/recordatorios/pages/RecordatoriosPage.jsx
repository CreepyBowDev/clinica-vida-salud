import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import PageHeading from '../../../components/PageHeading';
import FormField from '../../../components/FormField';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import CatalogResults from '../../../components/CatalogResults';
import Icon from '../../../components/Icon';
import Modal from '../../../components/Modal';
import { describirError } from '../../../utils/httpError';
import { consultarProximaCita, enviarRecordatorioManual } from '../services/recordatorio.service';
import { esFechaCivil, formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { useRecordatoriosQuery } from '../hooks/useRecordatoriosQuery';
import RecordatoriosTable from '../components/RecordatoriosTable';
import DispositivosPush from '../components/DispositivosPush';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import '../../agenda/styles/agenda.css';
import '../../agenda/styles/agenda-day.css';
import '../styles/recordatorios.css';

const empty = { fecha_desde: '', fecha_hasta: '' };
export default function RecordatoriosPage() {
  const { status, usuario } = useAuth();
  if (status !== 'authenticated' || ![ROLES.PACIENTE, ROLES.ADMINISTRADOR].includes(usuario?.rol)) return null;
  if (usuario.rol === ROLES.ADMINISTRADOR) return <ConsultaAdministrativa key={`${usuario.id_usuario}:${usuario.rol}`} />;
  return <Listado key={`${usuario.id_usuario}:${usuario.rol}`} />;
}
function ConsultaAdministrativa() {
  const [paciente, setPaciente] = useState(null), [revision, setRevision] = useState(0);
  useEffect(() => {
    const clear = () => { setPaciente(null); setRevision(v => v + 1); };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    return () => { unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  if (!paciente) return <PatientsPage key={revision} onSeleccionar={setPaciente}
    tituloSeleccion="Seleccionar paciente para consultar recordatorios"
    descripcionSeleccion="Busca al paciente y consulta sus avisos vigentes. Incluye fichas inactivas o sin cuenta web; esta consulta no modifica sus preferencias ni dispositivos." />;
  return <Listado key={paciente.id_paciente} paciente={paciente} onCambiar={() => setPaciente(null)} />;
}
function Listado({ paciente, onCambiar }) {
  const [draft, setDraft] = useState(empty), [filters, setFilters] = useState({ ...empty, page: 1 }), [error, setError] = useState('');
  const results = useRef(null), query = useRecordatoriosQuery(paciente ? { ...filters, id_paciente: paciente.id_paciente } : filters);
  const field = name => ({ value: draft[name], onChange: e => setDraft(v => ({ ...v, [name]: e.target.value })) });
  function submit(e) {
    e.preventDefault();
    if (Object.values(draft).some(v => v && !esFechaCivil(v)) || (draft.fecha_desde && draft.fecha_hasta && draft.fecha_desde > draft.fecha_hasta)) {
      setError('La fecha final debe ser igual o posterior a la inicial y ambas deben ser válidas.'); return;
    }
    setError(''); setFilters({ ...draft, page: 1 }); query.reload(); results.current?.focus();
  }
  return <section className="workspace-page reminders-page">
    <PageHeading title={paciente ? 'Recordatorios del paciente' : 'Mis recordatorios'} description={paciente
      ? 'Consulta administrativa de avisos vigentes. Fechas y horarios de la sede: America/La_Paz.'
      : 'Consulta los avisos vigentes de tus citas. Fechas y horarios de la sede: America/La_Paz.'} />
    {paciente && <div className="reminders-patient"><p>Paciente seleccionado: <strong>{paciente.nombres} {paciente.apellidos}</strong> · {paciente.codigo_paciente}</p>
      <ActionGroup><Button variant="secondary" onClick={onCambiar}>Cambiar paciente</Button></ActionGroup></div>}
    {paciente && <ProximaCita paciente={paciente} onGenerado={query.reload} />}
    <p className="reminders-guidance">{paciente ? 'Los avisos automáticos se mantienen.' : 'Los avisos se generan automáticamente.'} Consultar o actualizar esta lista no genera avisos ni los marca como leídos.</p>
    <form className="catalog-filters" aria-label="Filtrar recordatorios" onSubmit={submit}>
      <FormField label="Desde" type="date" {...field('fecha_desde')} /><FormField label="Hasta" type="date" {...field('fecha_hasta')} />
      <ActionGroup><Button type="submit" disabled={query.remaining > 0}>Consultar</Button>
        <Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft(empty); setError(''); setFilters({ ...empty, page: 1 }); query.reload(); }}>Limpiar filtros</Button></ActionGroup>
      {error && <p role="alert">{error}</p>}
    </form>
    <div className="reminders-results" ref={results} tabIndex={-1} role="region" aria-label="Resultados de recordatorios" aria-busy={query.loading}>
      <div className="reminders-results-heading"><h2>Avisos vigentes</h2><Button variant="secondary" disabled={query.loading || query.remaining > 0} onClick={query.reload}>Actualizar recordatorios</Button></div>
      <CatalogResults query={query} label="recordatorios" loadingLabel="Consultando recordatorios" emptyTitle="No hay recordatorios para estos filtros"
        emptyHint={paciente ? 'Prueba otras fechas o vuelve a consultar más tarde. Esta lista no sustituye el historial de citas del paciente.' : 'Prueba otras fechas o vuelve a consultar más tarde. Esta lista no sustituye tu historial de citas.'}
        onPage={page => { setFilters(v => ({ ...v, page })); results.current?.focus(); }}>
        {query.data && <><p className="reminders-updated">Consulta realizada: <time dateTime={query.data.meta.consultado_en}>{formatearInstanteCita(query.data.meta.consultado_en)}</time>. Actualiza para comprobar cambios.</p>
          <RecordatoriosTable avisos={query.data.data} administrativo={Boolean(paciente)} /></>}
      </CatalogResults>
    </div>
    {!paciente && <div className="reminders-devices"><DispositivosPush /></div>}
  </section>;
}

function ProximaCita({ paciente, onGenerado }) {
  const [data, setData] = useState(null), [loading, setLoading] = useState(true), [revision, setRevision] = useState(0);
  const [error, setError] = useState(''), [notice, setNotice] = useState(''), [confirmacion, setConfirmacion] = useState(null);
  const [sending, setSending] = useState(false), [incierto, setIncierto] = useState(false), [obsoleto, setObsoleto] = useState(false);
  const [hasta, setHasta] = useState(0), [clock, setClock] = useState(Date.now());
  const operation = useRef(null), busy = useRef(false), alive = useRef(false), pending = useRef(null);
  function resultado(result) {
    operation.current = null;
    setNotice(`Aviso interno generado. ${result.push === 'Pendiente'
      ? 'Web Push solicitado para despacho; no acredita entrega ni lectura.' : result.push === 'Sin_dispositivos'
        ? 'No hay dispositivos autorizados disponibles para Web Push.' : 'Web Push no está disponible; no se solicitó el envío externo.'}`);
    setIncierto(false); setError('');
  }
  function fallo(e, escritura = false) {
    const failure = describirError(e, { operation: escritura ? 'write' : 'query', allowedFields: [] });
    const seconds = Number(e.response?.headers?.['retry-after']);
    if (failure.status === 429) setHasta(Date.now() + (Number.isFinite(seconds) && seconds > 0 ? seconds : 60) * 1000);
    const ambiguo = escritura && e.recordatorioEnviado !== false && ![400, 401, 403, 404, 409, 419, 422, 429].includes(failure.status);
    setIncierto(ambiguo || (operation.current !== null && !escritura));
    setObsoleto(escritura);
    setError(ambiguo ? 'El resultado del envío es incierto. Comprueba el resultado; no se reenviará automáticamente.'
      : failure.status === 409 ? 'La cita, sus preferencias o el límite de envío cambiaron. Actualiza y revisa nuevamente.'
        : failure.status === 429 ? 'Demasiadas solicitudes. Espera antes de volver a consultar.'
          : failure.message);
  }
  useEffect(() => {
    alive.current = true;
    return () => { alive.current = false; pending.current?.abort(); };
  }, []);
  useEffect(() => {
    if (!hasta) return;
    const timer = setInterval(() => setClock(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [hasta]);
  useEffect(() => {
    const controller = new AbortController(); pending.current = controller;
    setLoading(true); setData(null); setConfirmacion(null);
    consultarProximaCita(paciente.id_paciente, operation.current, controller.signal).then(value => {
      if (controller.signal.aborted) return;
      setData(value); setObsoleto(false); setError('');
      if (value.operacion) resultado(value.operacion);
      else if (operation.current) setError('Todavía no se pudo confirmar el resultado. Vuelve a comprobarlo; no se reenviará la solicitud.');
    }, e => { if (!controller.signal.aborted) fallo(e); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [paciente.id_paciente, revision]);
  async function enviar() {
    if (busy.current || !confirmacion) return;
    busy.current = true; setSending(true); setError('');
    const controller = new AbortController(); pending.current = controller;
    try {
      operation.current ??= crypto.randomUUID();
      const response = await enviarRecordatorioManual(paciente.id_paciente, confirmacion, operation.current, controller.signal);
      if (!alive.current || controller.signal.aborted) return;
      resultado(response); setData(v => v ? { ...v, cita: v.cita ? { ...v.cita, manual_generado: true } : null } : v);
      onGenerado();
    } catch (e) {
      if (!alive.current || controller.signal.aborted) return;
      if (!operation.current) e.recordatorioEnviado = false;
      fallo(e, true);
      if ([400, 401, 403, 404, 409, 419, 422, 429].includes(e.response?.status) || e.recordatorioEnviado === false) operation.current = null;
    } finally {
      busy.current = false;
      if (alive.current && !controller.signal.aborted) { setSending(false); setConfirmacion(null); }
    }
  }
  const cita = data?.cita, espera = Math.max(0, Math.ceil((hasta - clock) / 1000));
  return <section className="reminders-next" aria-label="Próxima cita" aria-busy={loading || sending}>
    <div className="reminders-results-heading"><h2>Próxima cita</h2>
      <Button variant="quiet" disabled={loading || sending || espera > 0} onClick={() => setRevision(v => v + 1)}>
        {incierto ? 'Comprobar resultado' : 'Actualizar próxima cita'}</Button></div>
    {loading ? <p role="status">Consultando próxima cita…</p> : data && !cita ? <p>No hay próximas citas futuras vigentes para este Paciente.</p> : cita && <>
      <p><time dateTime={cita.inicio_at}>{formatearInstanteCita(cita.inicio_at)}</time></p>
      <p>Médico: <strong>{cita.medico}</strong></p><p>Consultorio: {cita.consultorio}</p>
      <Button disabled={sending || incierto || obsoleto || espera > 0 || cita.manual_generado || !cita.preferencia_habilitada}
        onClick={() => { setNotice(''); setConfirmacion({ ...cita }); }}><Icon name="bell" />Enviar recordatorio</Button>
      {cita.manual_generado && <p>Esta programación ya tiene un recordatorio manual.</p>}
      {!cita.preferencia_habilitada && <p>El Paciente tiene los recordatorios deshabilitados.</p>}
    </>}
    {notice && <p role="status">{notice}</p>}{error && <p role="alert">{error}</p>}
    {espera > 0 && <p role="status">Podrás consultar en {espera} s.</p>}
    <p className="reminders-next-hint">Un manual por programación, sin sustituir el automático de las 24 horas. Web Push requiere consentimiento y un dispositivo compatible; no garantiza recepción inmediata, sonido ni encendido de pantalla.</p>
    <Modal open={Boolean(confirmacion)} title="Enviar recordatorio" closeLabel="Cerrar confirmación"
      onClose={event => {
        // Un close nativo encolado de la revisión anterior no debe cerrar un diálogo ya reabierto.
        if (!busy.current && !(event?.type === 'close' && event.currentTarget.open)) setConfirmacion(null);
      }}>
      {confirmacion && <><p>¿Enviar un recordatorio de esta cita?</p>
        <p>{formatearInstanteCita(confirmacion.inicio_at)}</p><p>Médico: <strong>{confirmacion.medico}</strong></p>
        <ActionGroup><Button variant="secondary" disabled={sending} onClick={() => setConfirmacion(null)}>Volver</Button>
          <Button loading={sending} onClick={enviar}>{sending ? 'Enviando…' : 'Confirmar envío'}</Button></ActionGroup></>}
    </Modal>
  </section>;
}
