import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export default function PacientesRegistradosTable({ pacientes }) {
  const columns = ['ID y código', 'Nombres', 'Apellidos', 'Actividad actual', 'Registro de ficha'];
  return <div className="catalog-table-wrap query-table agenda-table"><table className="catalog-table" role="table">
    <caption>Pacientes registrados por fecha de creación de ficha</caption>
    <thead role="rowgroup"><tr role="row">{columns.map(label => <th key={label} scope="col" role="columnheader">{label}</th>)}</tr></thead>
    <tbody role="rowgroup">{pacientes.map(paciente => <tr key={paciente.id_paciente} role="row">
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">ID y código</span><strong>{paciente.codigo_paciente}</strong><span className="agenda-cell-secondary">ID: {paciente.id_paciente}</span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Nombres</span>{paciente.nombres}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Apellidos</span>{paciente.apellidos}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Actividad actual</span><span className="agenda-state">{paciente.activo ? 'Activo' : 'Inactivo'}</span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Registro de ficha</span><time dateTime={paciente.creado_en}>{formatearInstanteCita(paciente.creado_en)}</time></td>
    </tr>)}</tbody>
  </table></div>;
}
