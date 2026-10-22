import { Link, useParams } from 'react-router-dom';
import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarConsultorio } from '../services/consultorio.service';
import ConsultorioEditor from '../components/ConsultorioEditor';
import ConsultorioFailure from '../components/ConsultorioFailure';
import '../../../styles/catalogs.css';

const fields = [];
export default function ConsultorioEditorPage({ creating = false }) {
  const { id } = useParams();
  if (!creating && !esIdentificador(id)) return <section className="page-message"><h1>Consultorio no disponible</h1><Link to="/administracion/consultorios">Volver al catálogo</Link></section>;
  return <section className="workspace-page catalog-page"><header className="page-heading"><h1>{creating ? 'Registrar consultorio' : 'Editar consultorio'}</h1><p>Identifica el espacio de atención sin alterar la ubicación histórica de las citas.</p></header>
    {creating ? <ConsultorioEditor /> : <LoadEditor key={id} id={id} />}
  </section>;
}
function LoadEditor({ id }) {
  const query = useCatalogQuery(consultarConsultorio, id, fields);
  return query.loading ? <p role="status">Consultando consultorio…</p> : query.failure ? <><ConsultorioFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button><Link to="/administracion/consultorios">Volver al catálogo</Link></> : <ConsultorioEditor initial={query.data} />;
}
