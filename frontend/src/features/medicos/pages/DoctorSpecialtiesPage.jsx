import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import Button from '../../../components/Button';
import PageHeading from '../../../components/PageHeading';
import FormSection from '../../../components/FormSection';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { cambiarEspecialidadesMedico, consultarMedico } from '../services/medico.service';
import { useDoctorSubmission } from '../hooks/useDoctorSubmission';
import DoctorFailure from '../components/DoctorFailure';
import DoctorVersionReview from '../components/DoctorVersionReview';
import InitialSpecialties from '../components/InitialSpecialties';
import '../../../styles/catalogs.css';

const fields = [];
export default function DoctorSpecialtiesPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Médico no disponible</h1><Link to="/administracion/medicos">Volver a médicos</Link></section>;
  return <LoadSpecialties key={id} id={id} />;
}
function LoadSpecialties({ id }) {
  const query = useCatalogQuery(consultarMedico, id, fields);
  return <section className="workspace-page catalog-page admin-form-page"><Link className="back-link" to={`/administracion/medicos/${id}`}>Volver al detalle de médico</Link>
    <PageHeading title="Gestionar habilitaciones" description="Conserva al menos una especialidad habilitada y todas las asociaciones históricas." />
    {query.loading ? <p role="status">Consultando médico…</p> : query.failure ? <><DoctorFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></> : <SpecialtyEditor initial={query.data} />}
  </section>;
}
function SpecialtyEditor({ initial }) {
  const [base, setBase] = useState(initial);
  const [selected, setSelected] = useState(() => initial.especialidades.filter(item => item.habilitada));
  const [confirm, setConfirm] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const navigate = useNavigate();
  const { busy, failure, remaining, execute, report } = useDoctorSubmission(form);
  const enabled = base.especialidades.filter(item => item.habilitada);
  const removed = enabled.filter(item => !selected.some(value => value.id_especialidad === item.id_especialidad));
  const added = selected.filter(item => !enabled.some(value => value.id_especialidad === item.id_especialidad));
  async function submit(event) {
    event.preventDefault();
    if (busy || blocked || remaining) return;
    if (!selected.length) { report({ message: 'Selecciona al menos una especialidad habilitada.', fields: { especialidades: 'El médico debe conservar al menos una especialidad habilitada, incluso si está inactivo.' } }); return; }
    if (!removed.length && !added.length) { report({ message: 'No hay cambios de habilitaciones para guardar.', fields: {} }); return; }
    if (!confirm) { report(null); setConfirm(true); return; }
    const result = await execute(() => cambiarEspecialidadesMedico(base.id_medico, { especialidades: selected.map(item => item.id_especialidad), actualizado_en_original: base.actualizado_en }));
    if (result?.data) navigate(`/administracion/medicos/${base.id_medico}`, { replace: true, state: { doctorNotice: 'Habilitaciones guardadas. Se consultó nuevamente el registro.' } });
    if (result?.failure) { setConfirm(false); if (result.failure.stale || result.failure.uncertain) setBlocked(true); }
  }
  return <form ref={form} className="catalog-panel catalog-editor admin-form" aria-label="Editar habilitaciones" aria-busy={busy} onSubmit={submit}>
    <p><strong>{base.nombres} {base.apellidos}</strong> · {base.codigo_medico}</p>
    <p className="catalog-note">Retirar conserva el par histórico y las citas. El servidor rechaza todo el cambio si un par que se quiere retirar tiene citas futuras Programadas o Confirmadas. No se cancelan ni reprograman citas desde aquí. Rehabilitar reutiliza la asociación; no activa médico, catálogo ni cuenta.</p>
    <DoctorFailure failure={failure} remaining={remaining} />
    <FormSection title="Conjunto de habilitaciones" disabled={busy || blocked || confirm}>
      <InitialSpecialties managing selected={selected} onChange={setSelected} error={failure?.fields.especialidades} />
    </FormSection>
    {confirm && <ConfirmationPanel title="Revisar cambios de habilitaciones">
      <h3>Habilitar o rehabilitar</h3>{added.length ? <ul>{added.map(item => <li key={item.id_especialidad}>{item.nombre}{!item.activa && ' (catálogo inactivo)'}</li>)}</ul> : <p>Ninguna.</p>}
      <h3>Retirar para nuevos turnos</h3>{removed.length ? <ul>{removed.map(item => <li key={item.id_especialidad}>{item.nombre}</li>)}</ul> : <p>Ninguna.</p>}
      <p>Se enviarán las {selected.length} especialidades del conjunto completo seleccionado. La validación definitiva corresponde al servidor.</p>
    </ConfirmationPanel>}
    {blocked && <DoctorVersionReview id={base.id_medico} onUse={current => { setBase(current); setSelected(current.especialidades.filter(item => item.habilitada)); setBlocked(false); setConfirm(false); report(null); }} />}
    <ActionGroup className="form-actions"><Button type="submit" loading={busy} disabled={blocked || remaining > 0}>{busy ? 'Guardando habilitaciones…' : confirm ? 'Confirmar habilitaciones' : 'Revisar cambios'}</Button>
      {confirm && <Button variant="quiet" disabled={busy} onClick={() => setConfirm(false)}>Volver a la selección</Button>}
      <Link className="button button--secondary" to={`/administracion/medicos/${base.id_medico}`}>Volver sin guardar</Link></ActionGroup>
  </form>;
}
