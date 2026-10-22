import { Link } from 'react-router-dom';

export default function PatientsTable({ patients }) {
   return <div className="patients-table-scroll query-table">
      <table className="patients-table" role="table"><caption>Fichas administrativas · más recientes primero</caption>
        <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Paciente</th><th scope="col" role="columnheader">Código de ficha</th><th scope="col" role="columnheader">CI</th><th scope="col" role="columnheader">Estado de ficha</th><th scope="col" role="columnheader">Consulta</th></tr></thead>
        <tbody role="rowgroup">{patients.map(patient => <tr key={patient.id_paciente} role="row">
          <td role="cell"><strong>{patient.nombres} {patient.apellidos}</strong></td>
          <td role="cell"><span className="patient-cell-label" aria-hidden="true">Código de ficha</span>{patient.codigo_paciente}</td>
          <td role="cell"><span className="patient-cell-label" aria-hidden="true">CI</span>{patient.documento_identidad || 'Sin CI registrado'}</td>
          <td role="cell"><span className="patient-cell-label" aria-hidden="true">Estado de ficha</span><span className={`role-badge patient-status${patient.activo ? '' : ' patient-status--inactive'}`}>{patient.activo ? 'Activa' : 'Inactiva'}</span></td>
          <td role="cell" className="query-table-action"><Link to={`/recepcion/pacientes/${patient.id_paciente}`} aria-label={`Ver ficha de ${patient.nombres} ${patient.apellidos}`}>Ver ficha</Link></td>
        </tr>)}</tbody>
      </table>
    </div>;
}
