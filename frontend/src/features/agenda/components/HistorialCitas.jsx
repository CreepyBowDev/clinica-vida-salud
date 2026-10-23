import { useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import PageHeading from '../../../components/PageHeading';
import CatalogResults from '../../../components/CatalogResults';
import { useAgendaQuery } from '../hooks/useAgendaQuery';
import { consultarHistorialPaciente, consultarMisCitas } from '../services/agenda.service';
import { formatearInstanteCita } from '../utils/agendaFormat';
import { useAgendaWorkspace } from './AgendaWorkspace';
import HistorialFilters from './HistorialFilters';
import CitasTable from './CitasTable';
import '../../../styles/catalogs.css';
import '../styles/agenda.css';

const inicial = owner => ({ owner, draft: { estado: '', per_page: '20' }, filters: { page: 1, per_page: 20 } });

export default function HistorialCitas({ own = false, pacienteId }) {
  const { workspace, setWorkspace } = useAgendaWorkspace();
  const owner = own ? 'propio' : pacienteId;
  // Otra ficha empieza de cero sin reutilizar ni mostrar criterios/datos de la anterior.
  const state = workspace.historial?.owner === owner ? workspace.historial : inicial(owner);
  const { draft, filters } = state;
  const defaultPeriod = own ? 'proximas' : 'todas';
  const query = useAgendaQuery(own ? consultarMisCitas : consultarHistorialPaciente, { ...filters, ...(own ? {} : { id: pacienteId }) }, {
    kind: 'historial', allowedFields: ['periodo', 'estado', 'page', 'per_page'],
  });
  const form = useRef(null), results = useRef(null);
  useEffect(() => { if (query.failure?.status === 422) form.current?.querySelector('[aria-invalid="true"]')?.focus(); }, [query.failure]);
  const change = patch => setWorkspace(current => ({ ...current, historial: { ...state, ...patch } }));
  const period = query.data?.meta.filtros.periodo ?? filters.periodo ?? defaultPeriod;
  const detailPath = id => own ? `/mis-citas/${id}` : `/recepcion/historial-citas/${id}`;
  return <section className="workspace-page catalog-page agenda-page">
    {!own && <Link className="back-link" to={`/recepcion/pacientes/${pacienteId}`}>Volver a la ficha del paciente</Link>}
    <PageHeading title={own ? 'Mis citas' : 'Historial de citas'} description={own ? 'Consulta las citas vinculadas a tu ficha. El período y el estado se filtran por separado.' : 'Consulta administrativa de las citas del paciente seleccionado, incluidas las anteriores y canceladas.'} />
    <HistorialFilters draft={draft} defaultPeriod={defaultPeriod} onChange={next => change({ draft: next })}
      onApply={() => { change({ filters: { periodo: draft.periodo ?? defaultPeriod, estado: draft.estado, per_page: Number(draft.per_page), page: 1 } }); query.reload(); }}
      onClear={() => { change(inicial(owner)); query.reload(); }} disabled={query.remaining > 0} errors={query.failure?.fields ?? {}} formRef={form} />
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      {query.data && <section className="agenda-context" aria-label="Paciente del historial"><h2>{query.data.meta.paciente.nombres} {query.data.meta.paciente.apellidos}</h2>
        <p>{query.data.meta.paciente.codigo_paciente} · America/La_Paz</p>
        <p>Consulta realizada: <time dateTime={query.data.meta.consultado_en}>{formatearInstanteCita(query.data.meta.consultado_en)}</time></p></section>}
      <CatalogResults query={query} label="citas" loadingLabel="Consultando historial de citas…" emptyTitle="No hay citas para este período y estado"
        emptyHint="Puedes seleccionar Todas y Todos los estados para consultar el historial completo. Una combinación sin coincidencias no cambia los filtros automáticamente."
        onPage={page => { change({ filters: { ...filters, periodo: period, page } }); results.current?.focus(); }}>
        <CitasTable citas={query.data?.data ?? []} historial detailPath={detailPath}
          caption={period === 'proximas' ? 'Próximas citas por hora de inicio ascendente' : 'Historial por fecha y hora de atención descendente'}
          onDetail={() => setWorkspace(current => ({ ...current, historial: { ...state, filters: { ...filters, periodo: period } }, detailSource: own ? 'mis-citas' : 'historial' }))} />
      </CatalogResults>
    </div>
    <p className="catalog-note">Próximas incluye citas Programadas o Confirmadas cuyo intervalo aún no terminó, incluso en curso. Las canceladas futuras se consultan en Todas.</p>
    <p className="catalog-note">El paso del tiempo no cambia el estado de una cita. Esta pantalla no permite reservar, reprogramar ni cancelar.</p>
  </section>;
}
