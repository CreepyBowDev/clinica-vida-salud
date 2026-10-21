import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { consultarEspecialidad } from '../services/especialidad.service';
import SpecialtyData from './SpecialtyData';
import SpecialtyFailure from './SpecialtyFailure';

const fields = [];
export default function SpecialtyVersionReview({ id, onUse, stateOnly = false }) {
  const query = useCatalogQuery(consultarEspecialidad, id, fields);
  return <section className="catalog-review" aria-labelledby="specialty-review-title" aria-busy={query.loading}>
    <h2 id="specialty-review-title">Versión actual de la especialidad</h2>
    {query.loading ? <p role="status">Consultando la versión actual…</p> : query.failure ? <><SpecialtyFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Reintentar consulta actual</Button></> : <>
      <p>Compara los datos actuales con los que estabas revisando. No se reenviará ningún cambio automáticamente.</p>
      <SpecialtyData specialty={query.data} />
      <Button variant="secondary" onClick={() => onUse(query.data)}>{stateOnly ? 'Usar estado actual y reiniciar confirmación' : 'Cargar datos actuales y descartar mi borrador'}</Button>
    </>}
  </section>;
}
