import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { consultarConsultorio } from '../services/consultorio.service';
import ConsultorioData from './ConsultorioData';
import ConsultorioFailure from './ConsultorioFailure';

const fields = [];
export default function ConsultorioVersionReview({ id, onUse, stateOnly = false }) {
  const query = useCatalogQuery(consultarConsultorio, id, fields);
  return <section className="catalog-review" aria-label="Revisión de datos actuales" aria-busy={query.loading}>
    <h2>Revisa el consultorio actual</h2>
    <p>Esta consulta no vuelve a enviar la operación. Comprueba los datos antes de continuar.</p>
    {query.loading ? <p role="status">Consultando datos actuales…</p> : query.failure ? <ConsultorioFailure failure={query.failure} remaining={query.remaining} />
      : <><ConsultorioData consultorio={query.data} /><Button variant="secondary" onClick={() => onUse(query.data)}>{stateOnly ? 'Usar estado actual y volver a revisar' : 'Cargar datos actuales y descartar mi borrador'}</Button></>}
    <Button variant="quiet" disabled={query.loading || query.remaining > 0} onClick={query.reload}>Consultar de nuevo</Button>
  </section>;
}
