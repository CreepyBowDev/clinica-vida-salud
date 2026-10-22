export default function DoctorData({ doctor }) {
  return <><dl className="catalog-data record-data">
    <div><dt>Profesional</dt><dd>{doctor.nombres} {doctor.apellidos}</dd></div>
    <div><dt>Código profesional</dt><dd>{doctor.codigo_medico}</dd></div>
    <div><dt>Registro profesional</dt><dd>{doctor.registro_profesional}</dd></div>
    <div><dt>Teléfono</dt><dd>{doctor.telefono}</dd></div>
    <div><dt>Correo de contacto</dt><dd>{doctor.correo_contacto}</dd></div>
    <div><dt>Actividad profesional</dt><dd>{doctor.activo ? 'Activo' : 'Inactivo'}</dd></div>
    <div><dt>Cuenta de acceso</dt><dd>{doctor.cuenta_vinculada ? 'Cuenta vinculada; su estado se consulta en gestión de cuentas' : 'Sin cuenta vinculada'}</dd></div>
  </dl><section className="doctor-specialties" aria-label="Asociaciones de especialidades"><h2>Especialidades del profesional</h2>
    <ul>{doctor.especialidades.map(item => <li key={item.id_especialidad}><strong>{item.nombre}</strong> · {item.habilitada ? 'Habilitada' : 'Retirada; asociación histórica'} · catálogo {item.activa ? 'activo' : 'inactivo'}</li>)}</ul>
  </section></>;
}
