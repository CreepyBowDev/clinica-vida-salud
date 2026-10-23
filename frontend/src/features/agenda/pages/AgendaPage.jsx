import { useEffect, useRef, useState } from 'react';
import PageHeading from '../../../components/PageHeading';
import QueryMessage from '../../../components/QueryMessage';
import CatalogResults from '../../../components/CatalogResults';
import { ROLES } from '../../../constants/roles';
import { useAuth } from '../../../hooks/useAuth';
import AgendaFilters from '../components/AgendaFilters';
import MedicoAgendaSelector from '../components/MedicoAgendaSelector';
import CitasTable from '../components/CitasTable';
import { useAgendaWorkspace } from '../components/AgendaWorkspace';
import { useAgendaQuery } from '../hooks/useAgendaQuery';
import { consultarAgenda } from '../services/agenda.service';
import { esFechaCivil, formatearFechaCivil } from '../utils/agendaFormat';
import '../../../styles/catalogs.css';
import '../styles/agenda.css';

export default function AgendaPage() {
  const { usuario } = useAuth();
  const { workspace, setWorkspace } = useAgendaWorkspace();
  const { selectedId, draft, filters, catalog } = workspace;
  const own = usuario.rol === ROLES.MEDICO;
  const enabled = own || Boolean(selectedId);
  const query = useAgendaQuery(consultarAgenda, { ...filters, ...(own ? {} : { id_medico: selectedId }) }, {
    enabled, kind: 'agenda', allowedFields: ['id_medico', 'fecha', 'estado', 'page', 'per_page'],
  });
  const [errors, setErrors] = useState({});
  const form = useRef(null), results = useRef(null);
  useEffect(() => { if (query.failure?.status === 422) form.current?.querySelector('[aria-invalid="true"]')?.focus(); }, [query.failure]);
  const effectiveDate = query.data?.meta.filtros.fecha;
  const change = patch => setWorkspace(current => ({ ...current, ...patch }));
  function apply() {
    if (draft.fecha && !esFechaCivil(draft.fecha)) { setErrors({ fecha: 'Introduce una fecha válida.' }); return; }
    setErrors({}); change({ filters: { fecha: draft.fecha ?? effectiveDate, estado: draft.estado, per_page: Number(draft.per_page), page: 1 } }); query.reload();
  }
  return <section className="workspace-page catalog-page agenda-page">
    <PageHeading title="Agenda médica" description={own ? 'Consulta tus citas asignadas por fecha y estado.' : 'Selecciona un médico y consulta sus citas por fecha y estado.'} />
    {!own && <MedicoAgendaSelector selectedId={selectedId} catalog={catalog} onChange={next => change({ catalog: next })}
      onSelect={id => { change({ selectedId: id, filters: { fecha: draft.fecha, estado: draft.estado, per_page: Number(draft.per_page), page: 1 } }); setErrors({}); results.current?.focus(); }} />}
    <AgendaFilters draft={draft} effectiveDate={filters.fecha ?? effectiveDate} onChange={next => { change({ draft: next }); setErrors({}); }} onApply={apply}
      onClear={() => { change({ draft: { estado: '', per_page: '20' }, filters: { page: 1, per_page: 20 } }); setErrors({}); query.reload(); }}
      disabled={!enabled || query.remaining > 0} errors={{ ...query.failure?.fields, ...errors }} formRef={form} />
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      {!enabled ? <QueryMessage title="Selecciona un médico para consultar su agenda" description="Puedes buscar por nombre, apellidos o código. No se selecciona un profesional automáticamente." />
        : <>
          {query.data && <section className="agenda-context" aria-label="Contexto de la agenda"><h2>{query.data.meta.medico.nombres} {query.data.meta.medico.apellidos}</h2>
            <p>{query.data.meta.medico.codigo_medico} · <time dateTime={effectiveDate}>{formatearFechaCivil(effectiveDate)}</time> · America/La_Paz</p></section>}
          <CatalogResults query={query} label="citas" loadingLabel="Consultando agenda…" emptyTitle="No hay citas para estos filtros"
            emptyHint="Consulta otra fecha o selecciona todos los estados. Un resultado vacío no indica disponibilidad para reservar."
            onPage={page => { change({ filters: { ...filters, fecha: effectiveDate, page } }); results.current?.focus(); }}>
            <CitasTable citas={query.data?.data ?? []} caption="Citas de la agenda por hora de inicio ascendente"
              onDetail={() => change({ detailSource: 'agenda', filters: { ...filters, fecha: effectiveDate } })} />
          </CatalogResults>
        </>}
    </div>
    <p className="catalog-note">Consulta en solo lectura. Confirmada significa llegada presencial verificada por Recepción, no reserva registrada.</p>
  </section>;
}
