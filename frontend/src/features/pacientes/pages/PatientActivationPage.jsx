import { Link, useParams } from 'react-router-dom';
import Button from '../../../components/Button';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { usePatientQuery } from '../hooks/usePatientQuery';
import PatientFailure from '../components/PatientFailure';
import ActivationCodePage from './ActivationCodePage';

export default function PatientActivationPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Ficha no disponible</h1><Link to="/recepcion/pacientes">Volver a pacientes</Link></section>;
  return <PatientActivation key={id} id={id} />;
}
function PatientActivation({ id }) {
  const { data, loading, failure, reload } = usePatientQuery('detail', id);
  if (loading) return <p role="status">Consultando ficha para activación…</p>;
  if (failure) return <section className="workspace-page"><h1>Activación web de Paciente</h1><PatientFailure failure={failure} /><Button onClick={reload}>Volver a consultar</Button></section>;
  if (!data.documento_identidad?.trim() || data.cuenta_vinculada) return <section className="workspace-page patients-page"><h1>La ficha no admite esta emisión</h1><p className="patients-note">Se requiere CI informado y ausencia de cuenta web vinculada. El servidor comprueba nuevamente las condiciones al emitir.</p><Link to={`/recepcion/pacientes/${id}`}>Volver a la ficha</Link></section>;
  return <ActivationCodePage patient={data} />;
}
