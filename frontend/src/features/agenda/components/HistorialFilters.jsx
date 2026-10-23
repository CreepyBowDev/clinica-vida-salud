import Button from '../../../components/Button';
import SelectField from '../../../components/SelectField';
import ActionGroup from '../../../components/ActionGroup';
import { ESTADOS_CITA } from '../utils/agendaFormat';

export default function HistorialFilters({ draft, defaultPeriod, onChange, onApply, onClear, disabled, errors, formRef }) {
  return <form ref={formRef} className="catalog-filters" aria-label="Filtrar historial de citas" onSubmit={event => { event.preventDefault(); onApply(); }}>
    <SelectField label="Período de citas" value={draft.periodo ?? defaultPeriod} error={errors.periodo} onChange={event => onChange({ ...draft, periodo: event.target.value })}
      hint="El período clasifica las citas por tiempo; no modifica su estado.">
      <option value="proximas">Próximas</option><option value="anteriores">Anteriores</option><option value="todas">Todas</option>
    </SelectField>
    <SelectField label="Estado de cita" value={draft.estado} error={errors.estado} onChange={event => onChange({ ...draft, estado: event.target.value })}>
      <option value="">Todos los estados</option>{ESTADOS_CITA.map(estado => <option key={estado} value={estado}>{estado}</option>)}
    </SelectField>
    <SelectField label="Citas por página" value={draft.per_page} error={errors.per_page} onChange={event => onChange({ ...draft, per_page: event.target.value })}>
      {[10, 20, 50, 100].map(size => <option key={size} value={size}>{size}</option>)}
    </SelectField>
    <ActionGroup><Button type="submit" disabled={disabled}>Consultar historial</Button><Button variant="quiet" disabled={disabled} onClick={onClear}>Restablecer filtros</Button></ActionGroup>
  </form>;
}
