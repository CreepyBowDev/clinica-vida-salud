import { useRef, useState } from 'react';
import Pagination from '../../../components/Pagination';
import QueryMessage from '../../../components/QueryMessage';
import { ROLES } from '../../../constants/roles';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { consultarVersionesResultado } from '../services/laboratorio.service';
import { useLaboratorioQuery } from '../hooks/useLaboratorioQuery';
import LaboratorioQuery from './LaboratorioQuery';
import '../../../styles/version-history.css';

export default function VersionesResultado({ id, rol }) {
  const [page, setPage] = useState(1), results = useRef(null);
  const query = useLaboratorioQuery(consultarVersionesResultado, { id, rol, page });
  return <section className="lab-section version-history" aria-label="Versiones internas del resultado"><h2>Versiones internas del resultado</h2>
    <p>Metadatos históricos, sin acceso a archivos anteriores ni a solicitudes de origen.</p>
    <div className="version-results" ref={results} tabIndex={-1} role="region" aria-label="Histórico de versiones del resultado"><LaboratorioQuery query={query}>{d => <>
      {d.versiones.length ? <ol className="lab-versions version-list">{d.versiones.map(v => <li key={v.id_version_resultado}><header className="version-heading"><h3>Versión {v.numero_version}</h3>
        <p><time dateTime={v.registrada_en}>{formatearInstanteCita(v.registrada_en)}</time>. Usuario autor: {v.id_autor}.</p></header>
        <div className="version-meta">
        <p className="lab-text">{Object.hasOwn(v, 'motivo_cambio') ? v.motivo_cambio ?? 'Sin motivo de corrección registrado.' : 'Motivo no compartido en este ámbito.'}</p>
        {rol === ROLES.ADMINISTRADOR && v.id_version_origen && <p>Referencia de versión de origen: {v.id_version_origen}. Esta referencia no concede acceso al documento de origen.</p>}</div>
      </li>)}</ol> : <QueryMessage title="No hay versiones registradas" />}
      <Pagination meta={d.meta} label="versiones" onPage={p => { setPage(p); results.current?.focus(); }} />
    </>}</LaboratorioQuery></div>
  </section>;
}
