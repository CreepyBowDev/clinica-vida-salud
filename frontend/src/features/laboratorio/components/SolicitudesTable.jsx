import { Link } from 'react-router-dom';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export default function SolicitudesTable({ solicitudes, propia }) {
  return <div className="lab-table-scroll"><table className="lab-table">
    <caption>Estudios solicitados · más recientes primero</caption>
    <thead><tr><th scope="col">Estudio</th>{!propia && <th scope="col">Paciente</th>}<th scope="col">Solicitado</th><th scope="col">Estado</th><th scope="col">Consulta</th></tr></thead>
    <tbody>{solicitudes.map(s => <tr key={s.id_solicitud}>
      <td><strong>{s.estudio_solicitado}</strong><span className="lab-note">Médico: {s.medico.nombres} {s.medico.apellidos}</span></td>
      {!propia && <td><span className="lab-cell-label">Paciente</span>{s.paciente.nombres} {s.paciente.apellidos}<span className="lab-note">{s.paciente.codigo_paciente}</span></td>}
      <td><span className="lab-cell-label">Solicitado</span><time dateTime={s.solicitada_en}>{formatearInstanteCita(s.solicitada_en)}</time></td>
      <td><span className="lab-cell-label">Estado</span><span className="role-badge">{s.estado}</span></td>
      <td><Link to={`/laboratorio/solicitudes/${s.id_solicitud}`} aria-label={`Ver solicitud de ${s.estudio_solicitado} de ${s.paciente.nombres} ${s.paciente.apellidos}`}>Ver solicitud</Link></td>
    </tr>)}</tbody>
  </table></div>;
}
