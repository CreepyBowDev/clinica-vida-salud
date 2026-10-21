import { useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { usePatientQuery } from '../hooks/usePatientQuery';
import PatientFailure from '../components/PatientFailure';
import PatientStateAction from '../components/PatientStateAction';
import '../styles/patients.css';

export default function PatientDetailPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Ficha no disponible</h1><Link to="/recepcion/pacientes">Volver a pacientes</Link></section>;
  return <PatientDetail key={id} id={id} />;
}

function Datum({ label, children }) { return <div><dt>{label}</dt><dd>{children || 'No registrado'}</dd></div>; }

function PatientDetail({ id }) {
  const { data: patient, loading, failure, reload } = usePatientQuery('detail', id);
  const location = useLocation();
  const [notice, setNotice] = useState(location.state?.patientNotice ? 'Cambios administrativos guardados. Consulta los datos actuales de la ficha.' : '');
  return <section className="workspace-page patients-page">
    <Link className="patients-back" to="/recepcion/pacientes">Volver a pacientes</Link>
    <header className="page-heading"><h1>Ficha administrativa</h1><p>Identidad y datos de contacto del paciente.</p></header>
    {notice && <Alert variant="success"><p>{notice}</p></Alert>}
    <div aria-busy={loading}>{loading ? <p className="patients-empty" role="status">Consultando ficha…</p> : failure ? <><PatientFailure failure={failure} /><Button variant="secondary" onClick={reload}>Volver a consultar</Button></> : <>
      <section className="patients-panel" aria-labelledby="patient-identity"><h2 id="patient-identity">{patient.nombres} {patient.apellidos}</h2>
        <dl className="patients-data"><Datum label="Código de ficha">{patient.codigo_paciente}</Datum><Datum label="Identificador interno">{patient.id_paciente}</Datum>
          <Datum label="CI">{patient.documento_identidad || 'Sin CI registrado'}</Datum><Datum label="Estado de ficha">{patient.activo ? 'Activa' : 'Inactiva'}</Datum>
          <Datum label="Fecha de nacimiento"><time dateTime={patient.fecha_nacimiento}>{patient.fecha_nacimiento.split('-').reverse().join('/')}</time></Datum><Datum label="Sexo">{patient.sexo}</Datum>
          <Datum label="Cuenta web">{patient.cuenta_vinculada ? 'Vinculada' : 'Sin cuenta vinculada'}</Datum></dl>
        <p className="patients-note">El vínculo con una cuenta no indica si su acceso está activo ni si tiene un bloqueo temporal.</p>
        {!patient.activo && <p className="patients-note">La ficha inactiva no admite nuevas citas. Conserva su historial y las citas existentes.</p>}
        <div className="patients-actions"><Link className="button button--primary" to={`/recepcion/pacientes/${id}/editar`}>Editar ficha administrativa</Link>
          {patient.documento_identidad?.trim() && !patient.cuenta_vinculada && <Link className="button button--secondary" to={`/recepcion/pacientes/${id}/activacion`}>Emitir código de activación</Link>}</div>
        {!patient.documento_identidad?.trim() && <p className="patients-note">Para iniciar la vinculación web, incorpora primero el CI a esta misma ficha.</p>}
        {patient.cuenta_vinculada && <p className="patients-note">La ficha ya tiene una cuenta vinculada; no admite un nuevo código de activación.</p>}
      </section>
      <section className="patients-panel" aria-labelledby="patient-contact"><h2 id="patient-contact">Contacto</h2><dl className="patients-data">
        <Datum label="Teléfono">{patient.telefono}</Datum><Datum label="Correo de contacto">{patient.correo_contacto}</Datum><Datum label="Dirección">{patient.direccion}</Datum>
      </dl><p className="patients-note">El correo de contacto es independiente del correo utilizado para iniciar sesión.</p></section>
      <section className="patients-panel" aria-labelledby="patient-emergency"><h2 id="patient-emergency">Contacto de emergencia</h2>{patient.contacto_emergencia ? <dl className="patients-data">
        <Datum label="Nombre">{patient.contacto_emergencia.nombre}</Datum><Datum label="Parentesco">{patient.contacto_emergencia.parentesco}</Datum><Datum label="Teléfono de emergencia">{patient.contacto_emergencia.telefono}</Datum>
      </dl> : <p className="patients-note">Sin contacto de emergencia registrado.</p>}</section>
      <PatientStateAction key={patient.actualizado_en} patient={patient} onSaved={message => { setNotice(message); reload(); }} />
    </>}</div>
  </section>;
}
