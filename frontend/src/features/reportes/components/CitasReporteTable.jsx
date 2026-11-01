import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export default function CitasReporteTable({ citas, caption }) {
  const columns = ['Cita y horario', 'Paciente', 'Médico', 'Especialidad', 'Consultorio', 'Modalidad', 'Estado'];
  return <div className="catalog-table-wrap query-table agenda-table"><table className="catalog-table" role="table">
    <caption>{caption}</caption>
    <thead role="rowgroup"><tr role="row">{columns.map(label => <th key={label} scope="col" role="columnheader">{label}</th>)}</tr></thead>
    <tbody role="rowgroup">{citas.map(cita => <tr key={cita.id_cita} role="row">
      <td role="cell"><strong>{cita.codigo_cita}</strong>
        <span className="agenda-cell-secondary">Inicio: <time dateTime={cita.inicio_at}>{formatearInstanteCita(cita.inicio_at)}</time></span>
        <span className="agenda-cell-secondary">Fin: <time dateTime={cita.fin_at}>{formatearInstanteCita(cita.fin_at)}</time> · {cita.duracion_minutos} min</span></td>
      {['paciente', 'medico'].map(tipo => <td key={tipo} role="cell"><span className="catalog-cell-label" aria-hidden="true">{tipo === 'paciente' ? 'Paciente' : 'Médico'}</span>
        <strong>{cita[tipo].nombres} {cita[tipo].apellidos}</strong><span className="agenda-cell-secondary">{cita[tipo][`codigo_${tipo}`]}</span></td>)}
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Especialidad</span>{cita.especialidad.nombre}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Consultorio</span>{cita.consultorio_atencion}<span className="agenda-cell-secondary">{cita.sede_atencion}</span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Modalidad</span>{cita.modalidad}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Estado</span><span className="agenda-state">{cita.estado}</span></td>
    </tr>)}</tbody>
  </table></div>;
}
