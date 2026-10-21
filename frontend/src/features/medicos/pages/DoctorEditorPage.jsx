import { Link, useParams } from 'react-router-dom';
import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarMedico } from '../services/medico.service';
import DoctorEditor from '../components/DoctorEditor';
import DoctorFailure from '../components/DoctorFailure';
import '../../../styles/catalogs.css';

const fields = [];
export default function DoctorEditorPage({ creating = false }) {
  const { id } = useParams();
  if (!creating && !esIdentificador(id)) return <section className="page-message"><h1>Médico no disponible</h1><Link to="/administracion/medicos">Volver a médicos</Link></section>;
  return <section className="workspace-page catalog-page"><header className="page-heading"><h1>{creating ? 'Registrar médico' : 'Editar médico'}</h1><p>Conserva la identidad profesional y su historial.</p></header>
    {creating ? <DoctorEditor /> : <LoadEditor key={id} id={id} />}
  </section>;
}
function LoadEditor({ id }) {
  const query = useCatalogQuery(consultarMedico, id, fields);
  return query.loading ? <p role="status">Consultando médico…</p> : query.failure ? <><DoctorFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button><Link to="/administracion/medicos">Volver a médicos</Link></> : <DoctorEditor initial={query.data} />;
}
