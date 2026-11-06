import { Link } from 'react-router-dom';
import { formatearDiaCita, formatearHoraCita, formatearInstanteCita } from '../utils/agendaFormat';
import { SITUACIONES_PRECIO } from '../../pagos/utils/pagoFormat';

export default function CitasTable({ citas, caption, onDetail, historial = false, detailPath = id => `/citas/${id}`, detailState, destacarHorario = false, destacarFecha = false }) {
  return <div className={destacarHorario || destacarFecha ? 'agenda-day-table' : 'catalog-table-wrap query-table agenda-table'}><table className={destacarHorario || destacarFecha ? 'agenda-day-records' : 'catalog-table'} role="table"><caption>{caption}</caption>
    <thead role="rowgroup"><tr role="row">{['Cita y horario', historial ? 'Médico' : 'Paciente', 'Especialidad', 'Consultorio', 'Modalidad', 'Estado'].map(label => <th key={label} scope="col" role="columnheader">{label}</th>)}</tr></thead>
    <tbody role="rowgroup">{citas.map(cita => <tr key={cita.id_cita} role="row">
      <td role="cell">{destacarFecha ? <>
        <time className="history-date" dateTime={cita.inicio_at}>{formatearDiaCita(cita.inicio_at)}</time>
        <span className="agenda-cell-secondary">Inicio: {formatearHoraCita(cita.inicio_at)} · Fin: {formatearHoraCita(cita.fin_at)}{formatearDiaCita(cita.inicio_at) !== formatearDiaCita(cita.fin_at) && <> del {formatearDiaCita(cita.fin_at)}</>}</span>
        <Link className="history-cita-link" to={detailPath(cita.id_cita)} state={detailState} aria-label={`Ver cita ${cita.codigo_cita}`} onClick={onDetail}>{cita.codigo_cita}</Link>
        {historial && <span className="agenda-cell-secondary">Registro: <time dateTime={cita.creada_en}>{formatearInstanteCita(cita.creada_en)}</time></span>}
      </> : destacarHorario ? <>
        <Link className="agenda-day-time" to={detailPath(cita.id_cita)} state={detailState} aria-label={`Ver cita ${cita.codigo_cita}`} onClick={onDetail}>
          <time dateTime={cita.inicio_at}>{formatearHoraCita(cita.inicio_at)}</time><span aria-hidden="true">–</span><span className="sr-only"> hasta </span><time dateTime={cita.fin_at}>{formatearHoraCita(cita.fin_at)}</time>
        </Link>
        <span className="agenda-cell-secondary">{formatearDiaCita(cita.inicio_at)}{formatearDiaCita(cita.inicio_at) !== formatearDiaCita(cita.fin_at) && <> al {formatearDiaCita(cita.fin_at)}</>}</span>
        <span className="agenda-cell-secondary">{cita.codigo_cita}</span>
      </> : <><Link to={detailPath(cita.id_cita)} state={detailState} aria-label={`Ver cita ${cita.codigo_cita}`} onClick={onDetail}><strong>{cita.codigo_cita}</strong></Link>
        <span className="agenda-cell-secondary">Inicio: <time dateTime={cita.inicio_at}>{formatearInstanteCita(cita.inicio_at)}</time></span>
        <span className="agenda-cell-secondary">Fin: <time dateTime={cita.fin_at}>{formatearInstanteCita(cita.fin_at)}</time></span>
        {historial && <span className="agenda-cell-secondary">Registro: <time dateTime={cita.creada_en}>{formatearInstanteCita(cita.creada_en)}</time></span>}</>}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">{historial ? 'Médico' : 'Paciente'}</span>
        <strong>{historial ? `${cita.medico.nombres} ${cita.medico.apellidos}` : `${cita.paciente.nombres} ${cita.paciente.apellidos}`}</strong>
        <span className="agenda-cell-secondary">{historial ? cita.medico.codigo_medico : cita.paciente.codigo_paciente}</span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Especialidad</span>{cita.especialidad.nombre}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Consultorio</span>{cita.consultorio_atencion}<span className="agenda-cell-secondary">{cita.sede_atencion}</span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Modalidad</span>{cita.modalidad}</td>
       <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Estado</span><span className="agenda-state" data-estado={cita.estado}>{cita.estado}</span>
         {historial && cita.situacion_precio && <span className="agenda-cell-secondary">{SITUACIONES_PRECIO[cita.situacion_precio]}</span>}</td>
    </tr>)}</tbody></table></div>;
}
