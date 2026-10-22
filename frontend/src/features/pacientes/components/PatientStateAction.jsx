import { useRef, useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import PatientFailure from './PatientFailure';
import PatientVersionReview from './PatientVersionReview';
import { usePatientSubmission } from '../hooks/usePatientSubmission';
import { cambiarEstadoPaciente } from '../services/paciente.service';

export default function PatientStateAction({ patient, onSaved }) {
  const [base, setBase] = useState(patient);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const { busy, failure, remaining, execute, report } = usePatientSubmission(form, { operation: 'state' });
  const action = base.activo ? 'Desactivar ficha' : 'Reactivar ficha';
  return <section className="patients-panel"><h2>Estado administrativo</h2><p className="patients-note">Desactivar impide nuevas reservas; conserva citas e historial. Reactivar solo restablece elegibilidad para nuevas reservas. Ninguna acción cambia la cuenta web, sus sesiones o sus bloqueos.</p>
    {!open ? <Button variant={base.activo ? 'danger' : 'secondary'} onClick={() => setOpen(true)}>{action}</Button> : <form ref={form} className="admin-action-form" aria-label="Cambiar estado de ficha" onSubmit={async event => {
      event.preventDefault(); if (busy || blocked || remaining) return;
      if (!reason.trim() || /\p{C}/u.test(reason)) { report({ message: 'Introduce el motivo administrativo.', fields: { motivo: 'El motivo es obligatorio y no admite caracteres de control.' } }); return; }
      if (!confirm) { setConfirm(true); return; }
      const response = await execute(() => cambiarEstadoPaciente(base.id_paciente, { activo: !base.activo, motivo: reason.trim(), actualizado_en_original: base.actualizado_en }));
      if (response?.data) onSaved('Estado de ficha actualizado. La cuenta web y las citas existentes se conservan.');
      if (response?.failure) { setConfirm(false); if (response.failure.stale || response.failure.uncertain) setBlocked(true); }
    }}>
      <PatientFailure failure={failure} remaining={remaining} />
      <FormField label="Motivo del cambio de estado" required maxLength={500} value={reason} readOnly={busy || blocked || confirm} onChange={event => setReason(event.target.value)} error={failure?.fields.motivo} />
      {confirm && <ConfirmationPanel title="Revisar estado de ficha"><p role="status">¿{base.activo ? 'Desactivar' : 'Reactivar'} la ficha de {base.nombres} {base.apellidos}? Se conserva su cuenta y su historial.</p></ConfirmationPanel>}
      {blocked && <PatientVersionReview id={base.id_paciente} stateOnly onUse={current => { setBase(current); setReason(''); setConfirm(false); setBlocked(false); report(null); }} />}
      <ActionGroup className="form-actions"><Button type="submit" variant={base.activo ? 'danger' : 'primary'} loading={busy} disabled={blocked || remaining > 0}>{busy ? 'Actualizando estado…' : confirm ? `Confirmar: ${action.toLowerCase()}` : action}</Button>
        {!blocked && <Button variant="quiet" disabled={busy} onClick={() => { if (confirm) setConfirm(false); else { setOpen(false); setReason(''); report(null); } }}>Volver</Button>}</ActionGroup>
    </form>}
  </section>;
}
