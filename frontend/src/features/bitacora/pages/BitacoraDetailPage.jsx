import { Link, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PageHeading from '../../../components/PageHeading';
import QueryMessage from '../../../components/QueryMessage';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarEvento } from '../services/bitacora.service';
import BitacoraEventData from '../components/BitacoraEventData';
import '../../../styles/catalogs.css';
import '../styles/bitacora.css';

const fields = [];

export default function BitacoraDetailPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Evento no disponible</h1><p>El identificador del evento no es válido.</p><Link to="/administracion/bitacora">Volver a Bitácora</Link></section>;
  return <Detail key={id} id={id} />;
}

function Detail({ id }) {
  const query = useCatalogQuery(consultarEvento, id, fields);
  const failure = query.failure;
  const message = failure?.status === 404 ? 'El evento no está disponible. Vuelve al listado para continuar.'
    : failure?.status === 422 ? 'El identificador del evento no es válido. Vuelve al listado para seleccionar otro evento.'
      : failure && !failure.status ? 'No pudimos consultar el evento. Comprueba tu conexión y vuelve a intentarlo.' : failure?.message;
  return <section className="workspace-page catalog-page query-detail"><Link className="back-link" to="/administracion/bitacora">Volver a Bitácora</Link>
    <PageHeading title="Detalle de evento" description="Consulta en solo lectura. Fechas presentadas en America/La_Paz; se conserva también el valor UTC original." />
    {query.loading ? <QueryMessage loading title="Consultando evento…" /> : failure ? <div className="catalog-failure">
      <Alert><p>{message}</p>{query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
      {![404, 422].includes(failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button>}
    </div> : <BitacoraEventData evento={query.data} />}
  </section>;
}
