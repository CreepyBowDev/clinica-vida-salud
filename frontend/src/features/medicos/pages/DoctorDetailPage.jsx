import { useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PageHeading from '../../../components/PageHeading';
import ActionGroup from '../../../components/ActionGroup';
import QueryMessage from '../../../components/QueryMessage';
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
  return <section className="workspace-page catalog-page query-detail"><Link className="back-link" to="/administracion/medicos">Volver a médicos</Link>
    <PageHeading title="Detalle de médico" description="La actividad profesional, la cuenta de acceso y las habilitaciones son independientes." />
    {notice && <Alert variant="success">{notice}</Alert>}
    {query.data && !query.loading && !query.failure && <ActionGroup className="detail-links"><Link className="button button--secondary" to={`/administracion/medicos/${id}/consultorio`}>Gestionar consultorio fijo</Link><Link className="button button--secondary" to={`/administracion/horarios/medicos/${id}`}>Gestionar horarios</Link></ActionGroup>}
    {query.loading ? <QueryMessage loading title="Consultando médico…" /> : query.failure ? <div className="query-failure"><DoctorFailure failure={query.failure} remaining={query.remaining} /><Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></div> : <DoctorActivity initial={query.data} onSaved={() => { setNotice('Actividad profesional actualizada. Se consultó nuevamente el registro.'); query.reload(); }} />}
  </section>;
}
