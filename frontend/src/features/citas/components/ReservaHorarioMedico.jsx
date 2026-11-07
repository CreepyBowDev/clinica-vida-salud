import { useId } from 'react';
import { dias } from '../../horarios/utils/horarioFormat';

// Horario habitual informativo: los cupos se siguen consultando por fecha.
const hora = value => value.endsWith(':00') ? value.slice(0, 5) : value;

export default function ReservaHorarioMedico({ horarios }) {
  const tituloId = useId();
  return <section className="reserva-section booking-schedule" aria-labelledby={tituloId}>
    <h2 id={tituloId}>Horario de atención</h2>
    {horarios?.length ? <>
      <dl className="booking-schedule-days">
        {dias.map((dia, index) => {
          const intervalos = horarios.filter(h => h.dia_semana === index + 1);
          return intervalos.length > 0 && <div key={dia}>
            <dt>{dia}</dt>
            <dd>{intervalos.map(h => <span key={`${h.hora_inicio}:${h.hora_fin}`}>
              <time dateTime={h.hora_inicio}>{hora(h.hora_inicio)}</time>
              {' – '}<time dateTime={h.hora_fin}>{hora(h.hora_fin)}</time>
            </span>)}</dd>
          </div>;
        })}
      </dl>
      <p className="booking-schedule-note">Horario habitual en America/La_Paz. Elige una fecha para consultar los turnos libres; este horario no garantiza disponibilidad.</p>
    </> : <p className="booking-schedule-note">{horarios === undefined
      ? 'El horario semanal no está disponible en esta consulta. Elige una fecha para consultar los turnos libres.'
      : 'El médico no tiene horarios activos en esta consulta. Cambia de médico o actualiza la búsqueda.'}</p>}
  </section>;
}
