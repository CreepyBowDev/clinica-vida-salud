import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import PageHeading from '../../../components/PageHeading';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import CatalogResults from '../../../components/CatalogResults';
import { useAgendaQuery } from '../../agenda/hooks/useAgendaQuery';
import { ESTADOS_CITA, formatearFechaCivil, formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { consultarCitasProgramadas, consultarCitasPorMedico, consultarCitasPorEspecialidad, consultarCitasPorEstado, consultarPacientesRegistrados, consultarCantidadesCitas, consultarAgendaDiaria } from '../services/reporte.service';
import { esPeriodoReporte } from '../utils/periodoReporte';
import FiltroHistorico from '../components/FiltroHistorico';
import CitasReporteTable from '../components/CitasReporteTable';
import PacientesRegistradosTable from '../components/PacientesRegistradosTable';
import CantidadesReporte from '../components/CantidadesReporte';
import DescargarReportePdf from '../components/DescargarReportePdf';
import EstadisticasReporte from '../components/EstadisticasReporte';
import '../../agenda/styles/agenda.css';
import '../../../styles/administrative-query.css';
import '../styles/reportes.css';

const REPORTES = {
  programadas: { label: 'Citas Programadas para una fecha', load: consultarCitasProgramadas },
  'por-medico': { label: 'Citas por Médico', load: consultarCitasPorMedico },
  'por-especialidad': { label: 'Citas por especialidad', load: consultarCitasPorEspecialidad },
  'por-estado': { label: 'Citas por estado', load: consultarCitasPorEstado },
  'pacientes-registrados': { label: 'Pacientes registrados', load: consultarPacientesRegistrados },
  cantidades: { label: 'Cantidades de citas por período', load: consultarCantidadesCitas },
  'agenda-diaria': { label: 'Agenda diaria por Médico', load: consultarAgendaDiaria },
};

export default function ReportesPage() {
  const { status, usuario } = useAuth();
  const [session, setSession] = useState({ revision: 0, paused: false });
  useEffect(() => escucharCambioSesion(() => setSession(v => ({ revision: v.revision + 1, paused: true }))), []);
  if (status !== 'authenticated' || usuario?.rol !== ROLES.ADMINISTRADOR) return null;
  if (session.paused) return <section className="workspace-page"><PageHeading title="Reportes administrativos" />
    <p role="alert">La sesión cambió. Vuelve a consultar antes de continuar.</p>
    <Button onClick={() => setSession(v => ({ ...v, paused: false }))}>Volver a consultar</Button></section>;
  return <ConsultaReportes key={`${usuario.id_usuario}:${session.revision}`} />;
}

function ConsultaReportes() {
  const [tipo, setTipo] = useState('programadas');
  const changed = useRef(false);
  return <ConsultaReporte key={tipo} tipo={tipo} focusType={changed.current} onTipo={value => { changed.current = true; setTipo(value); }} />;
}

function ConsultaReporte({ tipo, onTipo, focusType }) {
  const programadas = tipo === 'programadas', pacientes = tipo === 'pacientes-registrados', reporte = REPORTES[tipo];
  const cantidades = tipo === 'cantidades', agenda = tipo === 'agenda-diaria';
  const ordenInicial = pacientes ? 'registro_asc' : 'fecha_asc';
  const [fecha, setFecha] = useState(''), [orden, setOrden] = useState(ordenInicial), [perPage, setPerPage] = useState('20');
  const [activo, setActivo] = useState('');
  const [modo, setModo] = useState('fecha'), [desde, setDesde] = useState(''), [hasta, setHasta] = useState(''), [estado, setEstado] = useState('');
  const [medico, setMedico] = useState(null), [especialidad, setEspecialidad] = useState(null);
  const [filters, setFilters] = useState(null), [error, setError] = useState('');
  const [labels, setLabels] = useState({ medico: 'Todos', especialidad: 'Todas' });
  const results = useRef(null);
  const typeSelect = useRef(null), validation = useRef(null);
  useEffect(() => { if (focusType) typeSelect.current?.focus(); }, [focusType]);
  useEffect(() => { if (error) validation.current?.focus(); }, [error]);
  const query = useAgendaQuery(reporte.load, filters, { enabled: filters !== null, kind: 'reportes', allowedFields: ['fecha', 'desde', 'hasta', 'estado', 'activo', 'id_medico', 'id_especialidad', 'orden', 'page', 'per_page'] });
  function submit(event) {
    event.preventDefault();
    const periodo = modo === 'fecha' ? { fecha } : { desde, hasta };
    if (!esPeriodoReporte(periodo)) { setError('Indica una fecha válida o un rango ordenado de hasta 366 días inclusivos.'); return; }
    if ((tipo === 'por-medico' || agenda) && !medico) { setError('Selecciona un médico para consultar este reporte.'); return; }
    if (tipo === 'por-especialidad' && !especialidad) { setError('Selecciona una especialidad para consultar este reporte.'); return; }
    if (tipo === 'por-estado' && !estado) { setError('Selecciona un estado para consultar este reporte.'); return; }
    const params = { ...periodo };
    if (!cantidades) { params.page = 1; params.per_page = Number(perPage); if (!agenda) params.orden = orden; }
    if (pacientes) { if (activo !== '') params.activo = activo; }
    else {
      params.id_medico = medico?.id_medico ?? '';
      if (!agenda) params.id_especialidad = especialidad?.id_especialidad ?? '';
      if (!programadas && !cantidades && estado) params.estado = estado;
    }
    setError(''); setFilters(params);
    setLabels({ medico: medico ? `${medico.nombres} ${medico.apellidos}` : 'Todos', especialidad: especialidad?.nombre ?? 'Todas' });
    query.reload(); results.current?.focus();
  }
  return <section className="workspace-page administrative-query reports-page">
    <PageHeading title="Reportes administrativos" description={pacientes
      ? 'El período corresponde a la creación de la ficha del paciente, no al registro o activación de su cuenta web.'
      : agenda ? 'Agenda por intersección con el día de la sede: incluye citas que empiezan antes del día y terminan dentro de él. No sumes agendas diarias para calcular cantidades.'
        : 'Consulta administrativa de citas. Los resultados reflejan el inicio programado vigente y el estado actual, no un corte histórico inmutable.'} />
    <div className="report-setup">
    <SelectField ref={typeSelect} label="Reporte" value={tipo} onChange={event => onTipo(event.target.value)}>
      {Object.entries(REPORTES).map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}
    </SelectField>
    <p>{pacientes ? 'Incluye fichas sin CI o cuenta web. La actividad es la actual, no la que tenían al registrarse. ' : programadas
      ? 'Incluye únicamente el estado Programada, no Confirmada (llegada presencial verificada). ' : 'Confirmada significa llegada presencial verificada. '}Fechas y horarios de la sede: America/La_Paz.</p>
    {cantidades && <p>Se cuentan citas cuyo inicio programado vigente cae en el período y cuyo estado actual es Atendida, Cancelada o Inasistencia.
      No son fechas reales de atención, cancelación o inasistencia ni un corte histórico inmutable. Programada y Confirmada no se incluyen.</p>}
    {agenda && <p>Orden fijo: inicio de cita ascendente y, en caso de empate, identificador ascendente. La ubicación y duración son las conservadas en la cita.</p>}
    {!pacientes && <div className="report-selectors"><FiltroHistorico tipo="medico" selected={medico} onSelect={setMedico} required={tipo === 'por-medico' || agenda} />
      {!agenda && <FiltroHistorico tipo="especialidad" selected={especialidad} onSelect={setEspecialidad} required={tipo === 'por-especialidad'} />}</div>}
    <form className="catalog-filters" aria-label={pacientes ? 'Filtros de pacientes registrados' : 'Filtros del reporte de citas'} onSubmit={submit}>
      {!programadas && !agenda && <SelectField label="Período" value={modo} onChange={event => { setModo(event.target.value); setFecha(''); setDesde(''); setHasta(''); setError(''); }}>
        <option value="fecha">Una fecha</option><option value="rango">Rango de fechas</option>
      </SelectField>}
      {modo === 'fecha' ? <FormField label="Fecha" type="date" required value={fecha} error={query.failure?.fields?.fecha} onChange={event => setFecha(event.target.value)} /> : <>
        <FormField label="Desde" type="date" required value={desde} error={query.failure?.fields?.desde} onChange={event => setDesde(event.target.value)} />
        <FormField label="Hasta" type="date" required value={hasta} error={query.failure?.fields?.hasta} hint="Máximo 366 días inclusivos." onChange={event => setHasta(event.target.value)} />
      </>}
      {!programadas && !pacientes && !cantidades && <SelectField label={tipo === 'por-estado' ? 'Estado obligatorio' : 'Estado'} required={tipo === 'por-estado'} value={estado}
        error={query.failure?.fields?.estado} onChange={event => setEstado(event.target.value)}>
        <option value="">{tipo === 'por-estado' ? 'Selecciona un estado' : 'Todos los estados'}</option>
        {ESTADOS_CITA.map(value => <option key={value} value={value}>{value}</option>)}
      </SelectField>}
      {pacientes && <SelectField label="Actividad" value={activo} error={query.failure?.fields?.activo} onChange={event => setActivo(event.target.value)}>
        <option value="">Todas las fichas</option><option value="1">Activos</option><option value="0">Inactivos</option>
      </SelectField>}
      {!cantidades && !agenda && <SelectField label="Orden" value={orden} onChange={event => setOrden(event.target.value)}>
        {pacientes ? <><option value="registro_asc">Registro ascendente</option><option value="registro_desc">Registro descendente</option>
          <option value="nombre_asc">Apellidos y nombres</option></> : <><option value="fecha_asc">Horario ascendente</option><option value="fecha_desc">Horario descendente</option></>}
      </SelectField>}
      {!cantidades && <SelectField label={pacientes ? 'Pacientes por página' : 'Citas por página'} value={perPage} onChange={event => setPerPage(event.target.value)}>
        {[20, 50, 100].map(size => <option key={size} value={size}>{size}</option>)}
      </SelectField>}
      <ActionGroup><Button type="submit" disabled={query.remaining > 0}>Consultar reporte</Button>
        <Button variant="quiet" onClick={() => { setFecha(''); setDesde(''); setHasta(''); setModo('fecha'); setEstado(''); setActivo(''); setOrden(ordenInicial); setPerPage('20'); setMedico(null); setEspecialidad(null); setFilters(null); setError(''); }}>Limpiar filtros</Button></ActionGroup>
      {error && <p ref={validation} role="alert" tabIndex={-1}>{error}</p>}
    </form>
    <p className="report-apply-hint">Los cambios en los filtros se aplican al pulsar «Consultar reporte».</p>
    </div>
    <div className="query-results" ref={results} tabIndex={-1} role="region" aria-label="Resultados del reporte" aria-busy={query.loading}>
      <h2 className="query-results-title">Resultados del reporte</h2>
      {!filters ? <p>Selecciona el período y los filtros obligatorios y consulta el reporte.</p> : <>
        <p className="report-applied">{filters.fecha ? `Fecha consultada: ${formatearFechaCivil(filters.fecha)}` : `Período consultado: ${formatearFechaCivil(filters.desde)} al ${formatearFechaCivil(filters.hasta)}`}. {pacientes
          ? `Actividad: ${filters.activo === undefined ? 'Todas las fichas' : filters.activo === '1' ? 'Activos' : 'Inactivos'}.`
          : `Médico: ${labels.medico}.${agenda ? '' : ` Especialidad: ${labels.especialidad}.`} ${cantidades ? 'Estados: Atendida, Cancelada e Inasistencia.' : `Estado: ${programadas ? 'Programada' : (filters.estado || 'Todos')}.`}`}</p>
        <ActionGroup><Button variant="secondary" disabled={query.loading || query.remaining > 0} onClick={() => { query.reload(); results.current?.focus(); }}>Actualizar reporte</Button></ActionGroup>
        {query.data && <><DescargarReportePdf tipo={tipo} filtros={filters} /><p>El PDF incluye el resumen, las tablas estadísticas completas y los gráficos útiles del conjunto filtrado.</p></>}
        {query.data && <p className="query-server-summary" role="status">{!cantidades && <>Total del servidor: {query.data.meta.total} {pacientes ? 'pacientes registrados' : programadas ? 'citas Programadas' : 'citas'}. </>}Consulta: {formatearInstanteCita(query.data.meta.consultado_en)}.</p>}
        {query.data && <EstadisticasReporte meta={query.data.meta} />}
        {cantidades ? <CantidadesReporte query={query} /> : <CatalogResults query={query} label={pacientes ? 'pacientes' : 'citas'} loadingLabel="Consultando reporte…" emptyTitle={query.data?.meta.total > 0 ? 'Esta página no contiene filas' : pacientes
          ? 'No hay pacientes registrados para estos filtros' : programadas ? 'No hay citas Programadas para estos filtros' : 'No hay citas para estos filtros'}
          emptyHint={query.data?.meta.total > 0 ? 'Las estadísticas corresponden al conjunto completo. Vuelve a una página anterior.' : pacientes ? 'Prueba otro período o actividad.' : 'Prueba otra fecha, médico o especialidad.'} onPage={page => { setFilters(v => ({ ...v, page })); results.current?.focus(); }}>
          {query.data && (pacientes ? <PacientesRegistradosTable pacientes={query.data.data} /> : <CitasReporteTable citas={query.data.data} caption={reporte.label} />)}
        </CatalogResults>}
      </>}
    </div>
  </section>;
}
