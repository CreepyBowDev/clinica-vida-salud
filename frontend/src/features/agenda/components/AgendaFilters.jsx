import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import ActionGroup from '../../../components/ActionGroup';
import { ESTADOS_CITA } from '../utils/agendaFormat';

export default function AgendaFilters({ draft, effectiveDate, onChange, onApply, onClear, disabled, errors, formRef }) {
  return <form ref={formRef} className="catalog-filters" aria-label="Filtrar agenda médica" onSubmit={event => { event.preventDefault(); onApply(); }}>
    <FormField label="Fecha de atención" type="date" min="0001-01-01" max="9999-12-31" value={draft.fecha ?? effectiveDate ?? ''}
      hint="Fecha de la clínica en America/La_Paz. Si no indicas una, se consulta hoy." error={errors.fecha}
      onChange={event => onChange({ ...draft, fecha: event.target.value })} />
    <SelectField label="Estado de cita" value={draft.estado} error={errors.estado} onChange={event => onChange({ ...draft, estado: event.target.value })}>
      <option value="">Todos los estados</option>{ESTADOS_CITA.map(estado => <option key={estado} value={estado}>{estado}</option>)}
    </SelectField>
    <SelectField label="Citas por página" value={draft.per_page} error={errors.per_page} onChange={event => onChange({ ...draft, per_page: event.target.value })}>
      {[10, 20, 50, 100].map(size => <option key={size} value={size}>{size}</option>)}
    </SelectField>
    <ActionGroup><Button type="submit" disabled={disabled}>Consultar agenda</Button><Button variant="quiet" disabled={disabled} onClick={onClear}>Restablecer filtros</Button></ActionGroup>
  </form>;
}
