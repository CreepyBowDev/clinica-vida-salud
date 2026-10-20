import { Link } from 'react-router-dom';

export default function AccountsTable({ accounts }) {
  return <><p className="accounts-scroll-hint">Desplaza la tabla horizontalmente para ver el estado y abrir el detalle.</p><div className="accounts-table-scroll" role="region" aria-label="Cuentas de usuario, tabla desplazable" tabIndex="0">
    <table className="accounts-table"><caption>Cuentas de usuario · más recientes primero</caption>
      <thead><tr><th scope="col">Cuenta</th><th scope="col">Rol</th><th scope="col">Estado de acceso</th><th scope="col">Consulta</th></tr></thead>
      <tbody>{accounts.map(account => <tr key={account.id_usuario}>
        <td>{account.rol !== 'Paciente' && <strong>{account.nombre_visible}</strong>}<span>{account.correo_acceso}</span><small>ID {account.id_usuario}</small></td>
        <td>{account.rol}</td><td><span className="role-badge">{account.estado_cuenta}</span></td>
        <td><Link to={`/administracion/cuentas/${account.id_usuario}`} aria-label={`Ver cuenta ${account.correo_acceso}`}>Ver detalle</Link></td>
      </tr>)}</tbody>
    </table>
  </div></>;
}
