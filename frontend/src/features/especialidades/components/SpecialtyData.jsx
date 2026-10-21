export default function SpecialtyData({ specialty }) {
  return <dl className="catalog-data"><div><dt>Nombre</dt><dd>{specialty.nombre}</dd></div>
    <div><dt>Descripción</dt><dd>{specialty.descripcion || 'Sin descripción'}</dd></div>
    <div><dt>Estado del catálogo</dt><dd>{specialty.activa ? 'Activa' : 'Inactiva'}</dd></div></dl>;
}
