import { Link } from 'react-router-dom';

export default function AccountsTable({ accounts }) {
  return <div className="accounts-table-scroll">
    <table className="accounts-table" role="table"><caption>Cuentas de usuario · más recientes primero</caption>
      <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Cuenta</th><th role="columnheader" scope="col">Rol</th><th role="columnheader" scope="col">Estado de acceso</th><th role="columnheader" scope="col">Consulta</th></tr></thead>
      <tbody role="rowgroup">{accounts.map(account => <tr role="row" key={account.id_usuario}>
        <td role="cell">{account.rol !== 'Paciente' && <strong>{account.nombre_visible}</strong>}<span>{account.correo_acceso}</span><small>ID {account.id_usuario}</small></td>
        <td role="cell"><span className="account-cell-label" aria-hidden="true">Rol</span>{account.rol}</td><td role="cell"><span className="account-cell-label" aria-hidden="true">Estado de acceso</span><span className="role-badge">{account.estado_cuenta}</span></td>
        <td role="cell"><Link to={`/administracion/cuentas/${account.id_usuario}`} aria-label={`Ver cuenta ${account.correo_acceso}`}>Ver detalle</Link></td>
      </tr>)}</tbody>
    </table>
  </div>;
}
