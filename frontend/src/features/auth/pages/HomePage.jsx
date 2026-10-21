import { useAuth } from '../../../hooks/useAuth';
import { AREAS, ROLES } from '../../../constants/roles';
import { Link } from 'react-router-dom';

export default function HomePage({ area = false }) {
  const { usuario } = useAuth();
  const current = AREAS[usuario.rol];
  return <div className="workspace-page workspace-home">
    <header className="page-heading"><h1>{area ? current.label : `Hola, ${usuario.nombre_visible}`}</h1>
      <p>{area ? current.description : 'Bienvenido a tu espacio en Clínica Vida Salud.'}</p></header>
    <section className="workspace-overview" aria-labelledby="access-title">
      <div className="workspace-intro"><span className="small-cross" aria-hidden="true">+</span>
        <h2 id="access-title">Un acceso, tu propio espacio.</h2>
        <p>Tu sesión está vinculada al rol autorizado por la clínica. Cada área muestra únicamente las opciones que te corresponden.</p>
        {!area && <Link className="button button--primary" to={current.path}>Ir a {current.label.toLowerCase()}</Link>}
        {area && usuario.rol === ROLES.ADMINISTRADOR && <Link className="button button--primary" to="/administracion/cuentas">Consultar cuentas de usuario</Link>}
        {area && usuario.rol === ROLES.RECEPCIONISTA && <Link className="button button--primary" to="/recepcion/pacientes">Consultar pacientes</Link>}
        {area && usuario.rol === ROLES.PACIENTE && <Link className="button button--primary" to="/mi-perfil">Consultar mi perfil</Link>}
      </div>
      <dl className="account-summary"><div><dt>Nombre</dt><dd>{usuario.nombre_visible}</dd></div>
        <div><dt>Correo de acceso</dt><dd>{usuario.correo_acceso}</dd></div>
        <div><dt>Rol</dt><dd><span className="role-badge">{usuario.rol}</span></dd></div></dl>
    </section>
    <section className="upcoming-section" aria-labelledby="upcoming-title"><div><h2 id="upcoming-title">{area ? 'Tu área de trabajo' : 'Construimos tu portal, paso a paso'}</h2>
      <p>La sesión y la navegación ya están disponibles. Estas funciones se incorporarán en las siguientes entregas, según el alcance de cada módulo.</p></div>
      <ul>{current.tasks.map((task, index) => <li key={task}><span>{task}</span><span className="upcoming-label">{usuario.rol === ROLES.ADMINISTRADOR && index === 0 ? 'Gestión de acceso disponible' : usuario.rol === ROLES.ADMINISTRADOR && index === 1 ? 'Consulta de médicos y especialidades disponible' : usuario.rol === ROLES.RECEPCIONISTA && index === 0 ? 'Consulta disponible' : usuario.rol === ROLES.RECEPCIONISTA && index === 1 ? 'Emisión disponible' : 'Próximamente'}</span></li>)}</ul>
    </section>
  </div>;
}
