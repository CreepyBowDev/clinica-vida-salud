import { useState } from 'react';
import { Outlet } from 'react-router-dom';

const empty = { fecha_desde: '', fecha_hasta: '', id_usuario_responsable: '', id_usuario_objetivo: '',
  tipo_actor: '', modulo: '', operacion: '', tipo_evento: '', resultado: '', direccion_ip: '',
  entidad_referida: '', registro_referido: '', per_page: '20' };

// Solo criterios de consulta en memoria; no se comparten ni persisten eventos.
export default function BitacoraWorkspace() {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const clear = () => { setDraft(empty); setFilters({ ...empty, page: 1 }); };
  return <Outlet context={{ draft, setDraft, filters, setFilters, clear }} />;
}
