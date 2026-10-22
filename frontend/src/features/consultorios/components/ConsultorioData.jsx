export default function ConsultorioData({ consultorio }) {
  return <dl className="catalog-data record-data">
    <div><dt>Código</dt><dd>{consultorio.codigo_consultorio}</dd></div>
    <div><dt>Nombre</dt><dd>{consultorio.nombre}</dd></div>
    <div><dt>Estado</dt><dd><span className={`catalog-status${consultorio.activo ? '' : ' catalog-status--inactive'}`}>{consultorio.activo ? 'Activo' : 'Inactivo'}</span></dd></div>
    {Number.isInteger(consultorio.medicos_asignados) && <div><dt>Médicos asignados</dt><dd>{consultorio.medicos_asignados}</dd></div>}
    {Number.isInteger(consultorio.citas_vigentes) && <div><dt>Citas vigentes</dt><dd>{consultorio.citas_vigentes}</dd></div>}
  </dl>;
}
