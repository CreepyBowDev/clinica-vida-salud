import { Link } from 'react-router-dom';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export default function SolicitudesTable({ solicitudes, propia, operacion = 'consulta' }) {
  const label = operacion === 'documentos' ? 'Gestionar documentos' : operacion === 'revision' ? 'Revisar resultado' : 'Ver solicitud';
  return <div className="lab-table-scroll"><table className="lab-table" role="table">
    <caption>Estudios solicitados · más recientes primero</caption>
    <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Estudio</th>{!propia && <th role="columnheader" scope="col">Paciente</th>}<th role="columnheader" scope="col">Solicitado</th><th role="columnheader" scope="col">Estado</th><th role="columnheader" scope="col">Consulta</th></tr></thead>
    <tbody role="rowgroup">{solicitudes.map(s => <tr key={s.id_solicitud} role="row">
      <td role="cell"><strong>{s.estudio_solicitado}</strong><span className="lab-note">Médico: {s.medico.nombres} {s.medico.apellidos}</span></td>
      {!propia && <td role="cell"><span className="lab-cell-label" aria-hidden="true">Paciente</span>{s.paciente.nombres} {s.paciente.apellidos}<span className="lab-note">{s.paciente.codigo_paciente}</span></td>}
      <td role="cell"><span className="lab-cell-label" aria-hidden="true">Solicitado</span><time dateTime={s.solicitada_en}>{formatearInstanteCita(s.solicitada_en)}</time></td>
      <td role="cell"><span className="lab-cell-label" aria-hidden="true">Estado</span><span className="role-badge" data-estado={s.estado}>{s.estado}</span></td>
      <td role="cell"><Link to={`/laboratorio/solicitudes/${s.id_solicitud}?operacion=${operacion}`} aria-label={`${label} de ${s.estudio_solicitado} de ${s.paciente.nombres} ${s.paciente.apellidos}`}>{label}</Link></td>
    </tr>)}</tbody>
  </table></div>;
}
