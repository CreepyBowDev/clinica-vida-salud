import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export default function RecordatoriosTable({ avisos, administrativo = false }) {
  return <div className="agenda-day-table"><table className="agenda-day-records" role="table">
    <caption>{administrativo ? 'Recordatorios del paciente' : 'Recordatorios de tus citas'} · horarios de la sede</caption>
    <thead role="rowgroup"><tr role="row">{['Cita y horario', 'Médico', 'Especialidad', 'Consultorio', 'Modalidad', 'Estado'].map(label => <th key={label} scope="col" role="columnheader">{label}</th>)}</tr></thead>
    <tbody role="rowgroup">{avisos.map(({ id_recordatorio, cita: c }) => <tr key={id_recordatorio} role="row">
      <td role="cell"><strong>{c.codigo_cita}</strong>
        <span className="agenda-cell-secondary">Inicio: <time dateTime={c.inicio_at}>{formatearInstanteCita(c.inicio_at)}</time></span>
        <span className="agenda-cell-secondary">Fin: <time dateTime={c.fin_at}>{formatearInstanteCita(c.fin_at)}</time></span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Médico</span>{c.medico.nombres} {c.medico.apellidos}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Especialidad</span>{c.especialidad.nombre}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Consultorio</span>{c.consultorio.ubicacion}<span className="agenda-cell-secondary">{c.sede_atencion}</span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Modalidad</span>{c.modalidad}</td>
       <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Estado</span><span className="agenda-state" data-estado={c.estado}>{c.estado}</span></td>
    </tr>)}</tbody>
  </table></div>;
}
