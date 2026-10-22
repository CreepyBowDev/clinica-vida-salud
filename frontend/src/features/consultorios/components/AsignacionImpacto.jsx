import Button from '../../../components/Button';

const fechaSede = new Intl.DateTimeFormat('es-BO', { timeZone: 'America/La_Paz', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
const labels = {
  CAMBIO_UBICACION: 'Cambio de ubicación propuesto', PLANIFICACION_CONFLICTO: 'Conflicto de horario u ocupación',
  ATENCION_NO_TRASLADABLE: 'Atención iniciada o documentada: no trasladable', CONSULTORIO_NO_DISPONIBLE: 'Consultorio no disponible',
};
function fecha(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(value)) return 'No disponible';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) || date.toISOString() !== value.replace(/(\.\d{3})\d{3}Z$/, '$1Z') ? 'No disponible' : fechaSede.format(date);
}
export default function AsignacionImpacto({ impacto, onPage, disabled }) {
  const { data, meta } = impacto;
  return <section className="catalog-review" aria-label="Impacto de asignación">
    <h2>Revisa el impacto completo</h2>
    <p>{data.totales.conflictos} conflictos · {data.totales.citas_trasladadas} citas con cambio de ubicación propuesto.</p>
    <p>{data.puede_confirmar ? 'La propuesta es compatible según la última consulta. El servidor volverá a comprobarla al guardar.' : 'No se puede guardar esta propuesta. Resuelve los conflictos por las operaciones autorizadas o selecciona otro consultorio.'}</p>
    <p className="catalog-note">Horarios de la sede: America/La_Paz. Las referencias son identificadores administrativos, sin datos de pacientes. Un conflicto y un traslado pueden referirse a la misma cita.</p>
    {data.elementos.length ? <ul className="assignment-impact-list">{data.elementos.map((item, index) => <li key={`${meta.current_page}-${index}`}>
      <strong>{Object.hasOwn(labels, item.codigo) ? labels[item.codigo] : 'Conflicto de asignación'}</strong>
      <dl className="catalog-data">{[['id_cita', 'Cita'], ['id_otra_cita', 'Otra cita'], ['id_medico', 'Médico'], ['id_consultorio', 'Consultorio'], ['id_horario', 'Horario'], ['id_otro_horario', 'Otro horario']].map(([key, label]) =>
        typeof item[key] === 'string' && /^\d{1,19}$/.test(item[key]) ? <div key={key}><dt>{label}</dt><dd>{item[key]}</dd></div> : null)}
        {['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'].includes(item.estado) && <div><dt>Estado de cita</dt><dd>{item.estado}</dd></div>}
        {item.inicio_at && <div><dt>Inicio en la sede</dt><dd>{fecha(item.inicio_at)}</dd></div>}
        {item.fin_at && <div><dt>Fin en la sede</dt><dd>{fecha(item.fin_at)}</dd></div>}
      </dl>
    </li>)}</ul> : <p>No hay elementos afectados para mostrar en esta página.</p>}
    <nav className="catalog-pagination" aria-label="Paginación del impacto"><p>{meta.total} elementos · página {meta.current_page} de {meta.last_page}</p><div>
      <Button variant="secondary" disabled={disabled || meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Impacto anterior</Button>
      <Button variant="secondary" disabled={disabled || meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Impacto siguiente</Button>
    </div></nav>
  </section>;
}
