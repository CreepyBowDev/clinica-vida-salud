import { useEffect, useRef } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';

const advancedFields = ['id_usuario_responsable', 'id_usuario_objetivo', 'tipo_actor', 'operacion',
  'tipo_evento', 'direccion_ip', 'entidad_referida', 'registro_referido'];
const entities = ['Usuario', 'Paciente', 'Medico', 'Especialidad', 'CuentaPrueba', 'ConjuntoDemostracion'];
const entityLabels = ['Usuario', 'Paciente', 'Médico', 'Especialidad', 'Cuenta de prueba', 'Conjunto de demostración'];

export default function BitacoraFilters({ draft, onChange, onApply, onClear, errors, disabled }) {
  const form = useRef(null);
  useEffect(() => {
    if (!errors) return;
    if (advancedFields.some(name => errors[name])) form.current.querySelector('details').open = true;
    form.current?.querySelector('[aria-invalid="true"]')?.focus();
  }, [errors]);
  const field = name => ({ name, value: draft[name], onChange: event => onChange({ ...draft, [name]: event.target.value }), error: errors?.[name] });

  return <form ref={form} className="catalog-filters bitacora-filters" aria-label="Filtrar eventos de Bitácora" onSubmit={event => { event.preventDefault(); onApply(); }}>
    <FormField label="Fecha desde" type="date" min="0001-01-01" max={draft.fecha_hasta || '9999-12-31'} {...field('fecha_desde')} />
    <FormField label="Fecha hasta" type="date" min={draft.fecha_desde || '0001-01-01'} max="9999-12-31" {...field('fecha_hasta')} />
    <FormField label="Módulo" maxLength={40} autoComplete="off" hint="Coincidencia exacta. Ejemplo: AUTENTICACION." {...field('modulo')} />
    <SelectField label="Resultado" {...field('resultado')}><option value="">Todos los resultados</option>
      <option value="EXITO">Éxito</option><option value="FALLO">Fallo</option><option value="DENEGADO">Denegado</option><option value="ERROR">Error</option>
    </SelectField>
    <p className="bitacora-filter-hint">El período corresponde a la fecha del hecho en la zona horaria de la clínica: America/La_Paz.</p>
    <details className="bitacora-advanced">
      <summary>Filtros avanzados</summary>
      <div className="bitacora-advanced-grid">
        <FormField label="ID de usuario responsable" inputMode="numeric" maxLength={19} autoComplete="off" hint="Cuenta que realizó la operación. No es la cuenta objetivo." {...field('id_usuario_responsable')} />
        <FormField label="ID de cuenta objetivo" inputMode="numeric" maxLength={19} autoComplete="off" hint="Cuenta sobre la que se realizó la operación." {...field('id_usuario_objetivo')} />
        <SelectField label="Origen del evento" {...field('tipo_actor')}><option value="">Todos los orígenes</option><option value="USUARIO">Usuario</option><option value="SISTEMA">Sistema</option><option value="ANONIMO">Anónimo</option></SelectField>
        <FormField label="Operación" maxLength={60} autoComplete="off" {...field('operacion')} />
        <FormField label="Tipo de evento" maxLength={80} autoComplete="off" {...field('tipo_evento')} />
        <FormField label="Dirección IP" maxLength={45} autoComplete="off" hint="Dirección IPv4 o IPv6 completa, sin máscara de red." {...field('direccion_ip')} />
        <SelectField label="Tipo de recurso" {...field('entidad_referida')}><option value="">Todos los recursos</option>{entities.map((value, index) => <option key={value} value={value}>{entityLabels[index]}</option>)}</SelectField>
        <FormField label="ID del recurso" maxLength={64} autoComplete="off" hint="Selecciona el tipo de recurso para buscar un identificador." {...field('registro_referido')} />
        <p className="bitacora-filter-hint">Los criterios se combinan. Módulo, operación y tipo de evento deben coincidir exactamente, incluidas las mayúsculas.</p>
      </div>
    </details>
    <SelectField label="Eventos por página" {...field('per_page')}>{[10, 20, 50, 100].map(size => <option key={size} value={size}>{size}</option>)}</SelectField>
    <div className="catalog-actions"><Button type="submit" disabled={disabled}>Aplicar filtros</Button><Button variant="quiet" disabled={disabled} onClick={onClear}>Limpiar filtros</Button></div>
  </form>;
}
