import Button from '../../../components/Button';
import PatientFailure from './PatientFailure';
import { usePatientQuery } from '../hooks/usePatientQuery';

export default function PatientVersionReview({ id, onUse, stateOnly = false, profile = false }) {
  const { data, failure, loading, reload } = usePatientQuery(profile ? 'profile' : 'detail', profile ? undefined : id);
  return <section className="patient-version" aria-labelledby="patient-version-heading" aria-busy={loading}>
    <h2 id="patient-version-heading">Versión actual de la ficha</h2>
    {loading ? <p role="status">Consultando los datos actuales…</p> : failure ? <><PatientFailure failure={failure} /><Button variant="secondary" onClick={reload}>Reintentar consulta actual</Button></> : <>
      <p>Compara estos datos con tu borrador. No se reenviará ningún cambio automáticamente.</p>
      <dl className="patients-data"><div><dt>Paciente</dt><dd>{data.nombres} {data.apellidos}</dd></div><div><dt>CI</dt><dd>{data.documento_identidad || 'Sin CI registrado'}</dd></div>
        <div><dt>Estado de ficha</dt><dd>{data.activo ? 'Activa' : 'Inactiva'}</dd></div><div><dt>Fecha de nacimiento</dt><dd>{data.fecha_nacimiento}</dd></div><div><dt>Sexo</dt><dd>{data.sexo || 'No registrado'}</dd></div>
        <div><dt>Teléfono</dt><dd>{data.telefono}</dd></div><div><dt>Correo de contacto</dt><dd>{data.correo_contacto || 'No registrado'}</dd></div><div><dt>Dirección</dt><dd>{data.direccion || 'No registrada'}</dd></div>
        <div><dt>Contacto de emergencia</dt><dd>{data.contacto_emergencia ? `${data.contacto_emergencia.nombre} · ${data.contacto_emergencia.parentesco} · ${data.contacto_emergencia.telefono}` : 'Sin contacto registrado'}</dd></div>
        {profile && <><div><dt>Recordatorios internos</dt><dd>{data.recordatorios_habilitados == null ? 'Sin preferencia registrada' : data.recordatorios_habilitados ? 'Habilitados' : 'Deshabilitados'}</dd></div><div><dt>Correo de acceso</dt><dd>{data.correo_acceso}</dd></div></>}
      </dl>
      <Button variant="secondary" onClick={() => onUse(data)}>{stateOnly ? 'Usar estado actual y reiniciar confirmación' : 'Cargar datos actuales y descartar mi borrador'}</Button>
    </>}
  </section>;
}
