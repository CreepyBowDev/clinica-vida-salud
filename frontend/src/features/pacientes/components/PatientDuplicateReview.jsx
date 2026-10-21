import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import { usePatientQuery } from '../hooks/usePatientQuery';
import PatientFailure from './PatientFailure';

export default function PatientDuplicateReview({ filters, onReady }) {
  const [page, setPage] = useState(1);
  const { data, failure, loading, reload } = usePatientQuery('list', { ...filters, page });
  useEffect(() => { onReady(Boolean(data)); }, [data, onReady]);
  return <div className="patient-review-results" aria-busy={loading}>
    {loading ? <p role="status">Consultando posibles fichas previas…</p> : failure ? <><PatientFailure failure={failure} /><Button variant="secondary" onClick={reload}>Reintentar búsqueda de fichas</Button></> : <>
      <p role="status">{data.meta.total} fichas encontradas. {data.meta.total === 0 ? 'La ausencia de coincidencias no garantiza que no exista una ficha previa.' : 'Revisa su correspondencia antes de crear otra ficha.'}</p>
      <ul className="patient-matches">{data.data.map(patient => <li key={patient.id_paciente}><strong>{patient.nombres} {patient.apellidos}</strong><span>{patient.codigo_paciente} · {patient.documento_identidad || 'Sin CI registrado'} · {patient.activo ? 'Activa' : 'Inactiva'}</span>
        <Link to={`/recepcion/pacientes/${patient.id_paciente}`} target="_blank" rel="noopener noreferrer">Consultar ficha en otra pestaña</Link></li>)}</ul>
      <nav className="patients-pagination" aria-label="Paginación de posibles fichas"><p>Página {data.meta.current_page} de {data.meta.last_page}</p><div>
        <Button variant="secondary" disabled={data.meta.current_page <= 1} onClick={() => setPage(data.meta.current_page - 1)}>Fichas anteriores</Button>
        <Button variant="secondary" disabled={data.meta.current_page >= data.meta.last_page} onClick={() => setPage(data.meta.current_page + 1)}>Fichas siguientes</Button>
      </div></nav>
    </>}
  </div>;
}
