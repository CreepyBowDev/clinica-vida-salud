import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { etiquetaActor, etiquetaCausa, etiquetaResultado, formatearFechaEvento } from '../utils/bitacoraFormat';

const campos = { documento_identidad: 'Documento de identidad', nombres: 'Nombres', apellidos: 'Apellidos',
  fecha_nacimiento: 'Fecha de nacimiento', sexo: 'Sexo', telefono: 'Teléfono', correo_contacto: 'Correo de contacto',
  direccion: 'Dirección', activo: 'Actividad', contacto_emergencia: 'Contacto de emergencia',
  recordatorios_habilitados: 'Preferencia de recordatorios', registro_profesional: 'Registro profesional',
  especialidades: 'Especialidades', nombre: 'Nombre', descripcion: 'Descripción', activa: 'Actividad de especialidad' };
const entidades = { Usuario: 'Usuario', Paciente: 'Paciente', Medico: 'Médico', Especialidad: 'Especialidad',
  CuentaPrueba: 'Cuenta de prueba', ConjuntoDemostracion: 'Conjunto de demostración' };
const referencias = { id_paciente: 'ID de Paciente', id_medico: 'ID de Médico', id_especialidad: 'ID de Especialidad' };
const ausente = 'No disponible';
const object = value => value && typeof value === 'object' && !Array.isArray(value) ? value : {};
const lista = (value, validar) => Array.isArray(value) && value.length <= 100 ? [...new Set(value.filter(validar))] : [];

function Dato({ label, children }) { return <div><dt>{label}</dt><dd>{children}</dd></div>; }
function Fecha({ valor }) { return valor ? <><time dateTime={valor}>{formatearFechaEvento(valor)}</time><span className="bitacora-original">UTC original: {valor}</span></> : ausente; }

function EstadoCuenta({ estado }) {
  const value = object(estado);
  return <dl className="catalog-data">
    <Dato label="Rol">{Object.values(ROLES).includes(value.rol) ? value.rol : ausente}</Dato>
    <Dato label="Estado de cuenta">{['Pendiente', 'Activa', 'Desactivada'].includes(value.estado_cuenta) ? value.estado_cuenta : ausente}</Dato>
    <Dato label="ID de Médico vinculado">{value.id_medico === null ? 'Sin vínculo' : esIdentificador(value.id_medico) ? value.id_medico : ausente}</Dato>
  </dl>;
}

function Cambios({ cambios }) {
  const value = object(cambios);
  const modificados = lista(value.campos, name => typeof name === 'string' && Object.hasOwn(campos, name));
  const habilitaciones = object(value.habilitaciones);
  const habilitadas = lista(habilitaciones.habilitadas, esIdentificador);
  const retiradas = lista(habilitaciones.retiradas, esIdentificador);
  const ids = ['id_paciente', 'id_codigo_activacion'].filter(key => esIdentificador(value[key]));
  const tieneEstado = ['antes', 'despues'].some(key => Object.keys(object(value[key])).some(name => ['rol', 'estado_cuenta', 'id_medico'].includes(name)));
  const hayCambios = modificados.length || habilitadas.length || retiradas.length || ids.length || tieneEstado || typeof value.sin_documento === 'boolean';
  if (!hayCambios) return <p>No hay cambios adicionales disponibles para este evento.</p>;
  return <>
    {modificados.length > 0 && <><h3>Campos modificados</h3><p className="text-muted">Se indican los campos, sin sus valores personales o clínicos.</p><ul>{modificados.map(name => <li key={name}>{campos[name]}</li>)}</ul></>}
    {typeof value.sin_documento === 'boolean' && <p>Registro administrativo sin documento: {value.sin_documento ? 'Sí' : 'No'}.</p>}
    {(habilitadas.length > 0 || retiradas.length > 0) && <dl className="catalog-data">
      {habilitadas.length > 0 && <Dato label="Especialidades habilitadas (IDs)">{habilitadas.join(', ')}</Dato>}
      {retiradas.length > 0 && <Dato label="Especialidades retiradas (IDs)">{retiradas.join(', ')}</Dato>}
    </dl>}
    {tieneEstado && <div className="bitacora-state-grid"><section><h3>Antes</h3><EstadoCuenta estado={value.antes} /></section><section><h3>Después</h3><EstadoCuenta estado={value.despues} /></section></div>}
    {ids.length > 0 && <dl className="catalog-data">{ids.map(key => <Dato key={key} label={key === 'id_paciente' ? 'ID de Paciente' : 'ID del registro de activación (no es el código secreto)'}>{value[key]}</Dato>)}</dl>}
  </>;
}

function Metadatos({ metadatos }) {
  const value = object(metadatos);
  const verificacion = { AUTORIZACION_INSTALACION: 'Autorización de instalación; no demuestra control del buzón', SIMULADA_PRUEBAS: 'Verificación simulada para pruebas' };
  const hayVerificacion = Object.hasOwn(verificacion, value.verificacion_correo);
  if (!hayVerificacion && value.metodo !== 'SEEDER') return <p>No hay metadatos adicionales disponibles para este evento.</p>;
  return <dl className="catalog-data">
    {hayVerificacion && <Dato label="Verificación de correo">{verificacion[value.verificacion_correo]}</Dato>}
    {value.metodo === 'SEEDER' && <Dato label="Método">Instalación mediante seeder</Dato>}
  </dl>;
}

export default function BitacoraEventData({ evento }) {
  const formatoConocido = evento.version_formato === 1;
  const refs = formatoConocido ? object(evento.referencias) : {};
  const refsDisponibles = Object.keys(referencias).filter(key => esIdentificador(refs[key]));
  return <div className="catalog-panel bitacora-detail">
    <section aria-labelledby="evento-cabecera"><h2 id="evento-cabecera">Evento #{evento.id_evento}</h2><dl className="catalog-data bitacora-data-grid">
      <Dato label="Tipo de evento">{evento.tipo_evento}</Dato><Dato label="Origen">{etiquetaActor(evento.tipo_actor)}</Dato>
      <Dato label="Módulo">{evento.modulo}</Dato><Dato label="Operación">{evento.operacion}</Dato>
      <Dato label="Resultado">{etiquetaResultado(evento.resultado)}</Dato><Dato label="Causa general">{etiquetaCausa(evento.causa_codigo)}</Dato>
      <Dato label="Fecha del hecho"><Fecha valor={evento.ocurrido_en} /></Dato><Dato label="Fecha de registro"><Fecha valor={evento.registrado_en} /></Dato>
      <Dato label="ID de usuario responsable">{evento.id_usuario_responsable || 'Sin responsable autenticado'}</Dato><Dato label="Rol histórico del responsable">{evento.rol_responsable || ausente}</Dato>
      <Dato label="ID de cuenta objetivo">{evento.id_usuario_objetivo || 'Sin cuenta objetivo'}</Dato><Dato label="Rol histórico de cuenta objetivo">{evento.rol_objetivo || ausente}</Dato>
      <Dato label="Dirección IP">{evento.direccion_ip || ausente}</Dato><Dato label="Procedencia de IP">{evento.procedencia_ip === 'DIRECTA' ? 'Conexión directa' : evento.procedencia_ip === 'PROXY_CONFIABLE' ? 'Proxy confiable' : ausente}</Dato>
      <Dato label="Correlación de la solicitud">{evento.correlacion || ausente}</Dato><Dato label="ID de evento relacionado">{evento.id_evento_relacionado || 'Sin evento relacionado'}</Dato>
      <Dato label="Versión del formato">{evento.version_formato ?? ausente}</Dato>
    </dl></section>
    <section aria-labelledby="evento-referencias"><h2 id="evento-referencias">Recursos referidos</h2>
      <dl className="catalog-data">
        <Dato label="Tipo de recurso principal">{Object.hasOwn(entidades, evento.entidad_referida) ? entidades[evento.entidad_referida] : ausente}</Dato>
        <Dato label="ID del recurso principal">{evento.registro_referido || ausente}</Dato>
        {refsDisponibles.map(key => <Dato key={key} label={referencias[key]}>{refs[key]}</Dato>)}
      </dl>
      {!refsDisponibles.length && <p className="catalog-note">No hay referencias adicionales disponibles.</p>}
      <p className="catalog-note">Las referencias identifican recursos; no conceden acceso a sus fichas o documentos.</p>
    </section>
    {!formatoConocido && <p className="catalog-note">Esta versión de formato no dispone de presentación de información adicional. La cabecera del evento sigue disponible.</p>}
    <section aria-labelledby="evento-cambios"><h2 id="evento-cambios">Cambios registrados</h2><Cambios cambios={formatoConocido ? evento.cambios_seguros : null} /></section>
    <section aria-labelledby="evento-metadatos"><h2 id="evento-metadatos">Metadatos del evento</h2><Metadatos metadatos={formatoConocido ? evento.metadatos_seguros : null} /></section>
  </div>;
}
