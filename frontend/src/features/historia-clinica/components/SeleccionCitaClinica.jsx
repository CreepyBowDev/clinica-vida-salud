import AgendaWorkspace from '../../agenda/components/AgendaWorkspace';
import AgendaPage from '../../agenda/pages/AgendaPage';

export default function SeleccionCitaClinica({ recorrido }) {
  return <AgendaWorkspace><AgendaPage recorrido={recorrido} /></AgendaWorkspace>;
}
