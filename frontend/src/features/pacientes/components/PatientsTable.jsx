import { Link } from 'react-router-dom';

export default function PatientsTable({ patients }) {
  return <><p className="patients-scroll-hint">Desplaza la tabla horizontalmente para ver todos los datos y abrir la ficha.</p>
    <div className="patients-table-scroll" role="region" aria-label="Pacientes, tabla desplazable" tabIndex="0">
      <table className="patients-table"><caption>Fichas administrativas · más recientes primero</caption>
        <thead><tr><th scope="col">Paciente</th><th scope="col">Código de ficha</th><th scope="col">CI</th><th scope="col">Estado de ficha</th><th scope="col">Consulta</th></tr></thead>
        <tbody>{patients.map(patient => <tr key={patient.id_paciente}>
          <td><strong>{patient.nombres} {patient.apellidos}</strong></td><td>{patient.codigo_paciente}</td>
          <td>{patient.documento_identidad || 'Sin CI registrado'}</td><td><span className="role-badge">{patient.activo ? 'Activa' : 'Inactiva'}</span></td>
          <td><Link to={`/recepcion/pacientes/${patient.id_paciente}`} aria-label={`Ver ficha de ${patient.nombres} ${patient.apellidos}`}>Ver ficha</Link></td>
        </tr>)}</tbody>
      </table>
    </div></>;
}
