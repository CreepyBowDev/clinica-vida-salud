import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { consultarMedico } from '../services/medico.service';
import DoctorData from './DoctorData';
import DoctorFailure from './DoctorFailure';

const fields = [];
export default function DoctorVersionReview({ id, onUse, stateOnly = false }) {
  const query = useCatalogQuery(consultarMedico, id, fields);
  return <section className="catalog-review" aria-labelledby="doctor-review-title" aria-busy={query.loading}><h2 id="doctor-review-title">Versión actual del profesional</h2>
    {query.loading ? <p role="status">Consultando los datos actuales…</p> : query.failure ? <><DoctorFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Reintentar consulta actual</Button></> : <>
      <p>Compara los datos antes de continuar. No se reenviarán cambios automáticamente.</p><DoctorData doctor={query.data} />
      <Button variant="secondary" onClick={() => onUse(query.data)}>{stateOnly ? 'Usar estado actual y reiniciar confirmación' : 'Cargar datos actuales y descartar mi borrador'}</Button>
    </>}
  </section>;
}
