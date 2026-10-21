import { useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarMedico } from '../services/medico.service';
import DoctorActivity from '../components/DoctorActivity';
import DoctorFailure from '../components/DoctorFailure';
import '../../../styles/catalogs.css';

const fields = [];
export default function DoctorDetailPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Médico no disponible</h1><Link to="/administracion/medicos">Volver a médicos</Link></section>;
  return <Detail key={id} id={id} />;
}
function Detail({ id }) {
  const query = useCatalogQuery(consultarMedico, id, fields);
  const location = useLocation();
  const [notice, setNotice] = useState(location.state?.doctorNotice || '');
  return <section className="workspace-page catalog-page"><Link to="/administracion/medicos">Volver a médicos</Link>
    <header className="page-heading"><h1>Detalle de médico</h1><p>La actividad profesional, la cuenta de acceso y las habilitaciones son independientes.</p></header>
    {notice && <Alert variant="success">{notice}</Alert>}
    {query.loading ? <p role="status">Consultando médico…</p> : query.failure ? <><DoctorFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></> : <DoctorActivity initial={query.data} onSaved={() => { setNotice('Actividad profesional actualizada. Se consultó nuevamente el registro.'); query.reload(); }} />}
  </section>;
}
