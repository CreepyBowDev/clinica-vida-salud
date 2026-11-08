import { useEffect, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import ActionGroup from '../../../components/ActionGroup';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { normalizarMotivoCancelacion } from '../services/gestionCita.service';

export default function CancelacionCita({ contexto, onGuardar, onVolver }) {
  const [motivo, setMotivo] = useState('');
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(null);
  const heading = useRef(null);
  const campo = useRef(null);
  const { gestion: cita, paciente, medico, especialidad } = contexto;
  useEffect(() => { if (revision) heading.current?.focus(); }, [revision]);
  return <section className="catalog-panel management-cancellation" aria-labelledby="cancelacion-titulo"><h2 id="cancelacion-titulo">Cancelar cita</h2>
    <p>Cancelar conserva el registro y su programación en el historial; no permite reabrir la cita desde este flujo.</p>
    <p>No es necesario consultar otros turnos ni que la ficha, el Médico o los recursos estén activos para cancelar una cita que siga siendo elegible.</p>
    <form aria-label="Revisar cancelación" noValidate onSubmit={event => {
      event.preventDefault();
      try { const normalizado = normalizarMotivoCancelacion(motivo); setError(''); setRevision({ motivo: normalizado }); }
      catch { setRevision(null); setError('Introduce hasta 500 caracteres, sin saltos de línea ni caracteres de control.'); campo.current?.focus(); }
    }}>
      <FormField ref={campo} label="Motivo de cancelación (opcional)" autoComplete="off" value={motivo} error={error}
        hint="Hasta 500 caracteres. No incluyas diagnósticos ni información clínica; el motivo se registra de forma privada."
        onChange={event => { setMotivo(event.target.value); setError(''); setRevision(null); }} />
      <ActionGroup><Button type="submit">Revisar cancelación</Button><Button variant="quiet" onClick={onVolver ?? (() => { setRevision(null); setError(''); campo.current?.focus(); })}>Volver sin cancelar</Button></ActionGroup>
    </form>
    {revision && <section aria-labelledby="cancelacion-revision"><h3 id="cancelacion-revision" tabIndex="-1" ref={heading}>Confirmar cancelación de {cita.codigo_cita}</h3>
      <dl className="catalog-data record-data">
        <div><dt>Paciente</dt><dd>{paciente.nombres} {paciente.apellidos}</dd></div>
        <div><dt>Médico responsable</dt><dd>{medico.nombres} {medico.apellidos}</dd></div>
        <div><dt>Especialidad</dt><dd>{especialidad.nombre}</dd></div>
        <div><dt>Estado actual</dt><dd>{cita.estado}</dd></div>
        <div><dt>Inicio</dt><dd>{formatearInstanteCita(cita.inicio_at)}</dd></div><div><dt>Fin</dt><dd>{formatearInstanteCita(cita.fin_at)}</dd></div>
        <div><dt>Duración</dt><dd>{cita.duracion_minutos} minutos</dd></div>
        <div><dt>Ubicación conservada</dt><dd>{cita.sede_atencion} · {cita.consultorio_atencion}</dd></div>
        <div><dt>Motivo que se enviará</dt><dd>{revision.motivo ?? 'Sin motivo'}</dd></div>
      </dl>
      <Alert variant="warning"><p>La cita pasará a Cancelada y liberará su intervalo solo si el servidor confirma la operación. Se conserva el historial.</p></Alert>
      <ActionGroup><Button variant="danger" onClick={() => onGuardar({ original: cita, motivo: revision.motivo })}>Confirmar cancelación</Button>
        <Button variant="quiet" onClick={onVolver ?? (() => { setRevision(null); campo.current?.focus(); })}>Abandonar cancelación</Button></ActionGroup>
    </section>}
  </section>;
}
