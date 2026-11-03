import { useRef } from 'react';
import { useOutletContext } from 'react-router-dom';
import CatalogResults from '../../../components/CatalogResults';
import PageHeading from '../../../components/PageHeading';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarBitacora } from '../services/bitacora.service';
import BitacoraFilters from '../components/BitacoraFilters';
import BitacoraTable from '../components/BitacoraTable';
import '../../../styles/catalogs.css';
import '../styles/bitacora.css';
import '../../../styles/administrative-query.css';
import '../styles/audit-reading.css';

const fields = ['fecha_desde', 'fecha_hasta', 'id_usuario_responsable', 'id_usuario_objetivo',
  'tipo_actor', 'modulo', 'operacion', 'tipo_evento', 'resultado', 'direccion_ip', 'entidad_referida', 'registro_referido', 'per_page', 'page'];

export default function BitacoraPage() {
  const { draft, setDraft, filters, setFilters, clear } = useOutletContext();
  const query = useCatalogQuery(listarBitacora, filters, fields);
  const results = useRef(null);
  return <section className="workspace-page catalog-page administrative-query audit-page">
    <PageHeading title="Bitácora del Sistema" description="Consulta administrativa del historial de eventos del sistema, en solo lectura." />
    <BitacoraFilters draft={draft} onChange={setDraft} errors={query.failure?.fields} disabled={query.remaining > 0}
      onApply={() => { setFilters({ ...draft, page: 1 }); query.reload(); }}
      onClear={() => { clear(); query.reload(); }} />
    <div className="query-results" ref={results} tabIndex="-1" role="region" aria-label="Resultados de bitácora" aria-busy={query.loading}>
      <div className="audit-results-heading"><h2 className="query-results-title">Eventos registrados</h2>
        {query.data && <p className="query-server-summary">Total del servidor: {query.data.meta.total} {query.data.meta.total === 1 ? 'evento' : 'eventos'}</p>}</div>
      <CatalogResults query={query} label="eventos" loadingLabel="Consultando eventos…" emptyTitle="No hay eventos para estos filtros"
        emptyHint="Prueba otro período o limpia los filtros para consultar el historial disponible."
        onPage={page => { setFilters({ ...filters, page }); results.current?.focus(); }}>
        <BitacoraTable eventos={query.data?.data || []} />
      </CatalogResults>
    </div>
    <p className="catalog-note">Responsable y cuenta objetivo se muestran por identificador y rol histórico. La consulta no modifica eventos ni concede acceso a fichas clínicas.</p>
  </section>;
}
