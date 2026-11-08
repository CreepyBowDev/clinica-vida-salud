import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import Icon from '../components/Icon';
import { navigationDestination } from '../constants/navigation';

export default function ModuleNavigation({ modules, active, pathname, search, onNavigate }) {
  const [expanded, setExpanded] = useState(() => active ? [active.module.id] : []);
  const activeId = active?.module.id;
  useEffect(() => {
    if (activeId) setExpanded(previous => previous.includes(activeId) ? previous : [...previous, activeId]);
  }, [pathname, activeId]);
  const available = modules.filter(module => module.items.some(item => item.kind === 'page'));
  const pending = modules.filter(module => module.items.every(item => item.kind === 'pending'));

  function moduleSection(module) {
    const selected = activeId === module.id;
    const open = selected || expanded.includes(module.id);
    const waiting = module.items.every(item => item.kind === 'pending');
    return <div key={module.id} data-module={module.id} className={`nav-module${open ? ' is-expanded' : ''}${selected ? ' is-current' : ''}`}>
      <button type="button" className="nav-module-toggle" aria-expanded={open} aria-disabled={selected || undefined} aria-controls={`module-${module.id}`}
        onClick={() => { if (!selected) setExpanded(previous => open ? previous.filter(id => id !== module.id) : [...previous, module.id]); }}>
        <Icon name={module.icon} /><span>{module.label}{waiting && <> <small>Pendiente</small></>}</span><Icon name="chevron" className="nav-chevron" />
      </button>
      <ul id={`module-${module.id}`} hidden={!open} className="nav-module-items">
        {module.items.map(item => <li key={item.id}>{item.kind === 'page'
          ? <><Link to={navigationDestination(item, pathname, search)} onClick={onNavigate} className={active?.item.id === item.id ? 'active' : undefined}
             aria-current={active?.item.id === item.id ? 'page' : undefined}>{item.label}</Link>
            {item.auxiliary && <small className="navigation-auxiliary">Acceso auxiliar</small>}</>
          : <span className="nav-pending">{item.label}<small>Pendiente</small></span>}</li>)}
      </ul>
    </div>;
  }

  return <><div className="nav-modules">{available.map(moduleSection)}</div>
    {pending.length > 0 && <div className="nav-planned"><p>Próximos módulos</p>{pending.map(moduleSection)}</div>}</>;
}
