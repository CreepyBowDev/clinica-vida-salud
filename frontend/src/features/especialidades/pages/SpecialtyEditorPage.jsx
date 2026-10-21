import { Link, useParams } from 'react-router-dom';
import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarEspecialidad } from '../services/especialidad.service';
import SpecialtyEditor from '../components/SpecialtyEditor';
import SpecialtyFailure from '../components/SpecialtyFailure';
import '../../../styles/catalogs.css';

const fields = [];
export default function SpecialtyEditorPage({ creating = false }) {
  const { id } = useParams();
  if (!creating && !esIdentificador(id)) return <section className="page-message"><h1>Especialidad no disponible</h1><Link to="/administracion/especialidades">Volver al catálogo</Link></section>;
  return <section className="workspace-page catalog-page">
    <header className="page-heading"><h1>{creating ? 'Registrar especialidad' : 'Editar especialidad'}</h1><p>Administra el catálogo conservando sus asociaciones e historial.</p></header>
    {creating ? <SpecialtyEditor /> : <LoadEditor key={id} id={id} />}
  </section>;
}
function LoadEditor({ id }) {
  const query = useCatalogQuery(consultarEspecialidad, id, fields);
  return query.loading ? <p role="status">Consultando especialidad…</p> : query.failure ? <><SpecialtyFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button><Link to="/administracion/especialidades">Volver al catálogo</Link></> : <SpecialtyEditor initial={query.data} />;
}
