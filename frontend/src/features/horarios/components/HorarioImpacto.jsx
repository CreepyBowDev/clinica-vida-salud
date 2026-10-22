import Button from '../../../components/Button';
import { diaSemana, horaLocal, fechaSede } from '../utils/horarioFormat';

const labels = { HORARIO_INTERVALO_INVALIDO: 'El fin debe ser posterior al inicio', DURACION_INCOMPATIBLE: 'La duración no cabe en el intervalo',
  CONSULTORIO_NO_DISPONIBLE: 'Se requiere un consultorio fijo activo', PLANIFICACION_CONFLICTO: 'Conflicto de horario u ocupación',
  HORARIO_CITAS_SIN_COBERTURA: 'Hay citas vigentes sin cobertura completa' };
export default function HorarioImpacto({ impacto, onPage, disabled, configuracion = false }) {
  const { data, meta } = impacto;
  return <section className="catalog-review" aria-label={configuracion ? 'Impacto de duración global' : 'Impacto del horario'}><h2>{configuracion ? 'Impacto de duración global' : 'Impacto del horario'}</h2>
    <div className={`impact-outcome${data.puede_confirmar ? '' : ' is-conflict'}`}><p className="impact-count">{data.totales.conflictos} conflictos en la propuesta completa.</p>
    <p>{configuracion ? (data.puede_confirmar ? 'La duración cabe en todos los horarios activos según la consulta actual. El servidor volverá a comprobarlo al guardar.' : 'No se puede guardar esta duración. Revisa los intervalos incompatibles, incluidos los de Médicos inactivos.') : (data.puede_confirmar ? 'La propuesta es compatible según la consulta actual. Al guardar, el servidor volverá a comprobar horarios y citas.' : 'No se puede guardar. Revisa los conflictos; las reprogramaciones de citas corresponden a Recepción.')}</p></div>
    <p className="catalog-note">Horas y fechas de la sede, America/La_Paz. Solo referencias administrativas, sin datos clínicos ni de pacientes.</p>
    {data.elementos.length ? <ul className="horario-impact-list">{data.elementos.map((item, index) => <li key={`${meta.current_page}-${index}`}>
      <strong>{Object.hasOwn(labels, item.codigo) ? labels[item.codigo] : 'Conflicto de planificación'}</strong>
      <dl className="catalog-data">{[['id_horario', 'Horario'], ['id_cita', 'Cita'], ['id_medico', 'Médico'], ['id_consultorio', 'Consultorio']].map(([key, label]) =>
        typeof item[key] === 'string' && /^\d{1,19}$/.test(item[key]) ? <div key={key}><dt>{label}</dt><dd>{item[key]}</dd></div> : null)}
        {item.dia_semana !== undefined && <div><dt>Día</dt><dd>{diaSemana(item.dia_semana)}</dd></div>}
        {item.hora_inicio && <div><dt>Hora inicial</dt><dd>{horaLocal(item.hora_inicio)}</dd></div>}
        {item.hora_fin && <div><dt>Hora final</dt><dd>{horaLocal(item.hora_fin)}</dd></div>}
        {item.inicio_at && <div><dt>Inicio de cita</dt><dd>{fechaSede(item.inicio_at)}</dd></div>}
        {item.fin_at && <div><dt>Fin de cita</dt><dd>{fechaSede(item.fin_at)}</dd></div>}
        {['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'].includes(item.estado) && <div><dt>Estado de cita</dt><dd>{item.estado}</dd></div>}
      </dl>
    </li>)}</ul> : <p>No hay conflictos para mostrar en esta página.</p>}
    <nav className="catalog-pagination" aria-label={configuracion ? 'Paginación del impacto de duración' : 'Paginación del impacto de horario'}><p>{meta.total} elementos · página {meta.current_page} de {meta.last_page}</p><div>
      <Button variant="secondary" disabled={disabled || meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Impacto anterior</Button>
      <Button variant="secondary" disabled={disabled || meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Impacto siguiente</Button>
    </div></nav>
  </section>;
}
