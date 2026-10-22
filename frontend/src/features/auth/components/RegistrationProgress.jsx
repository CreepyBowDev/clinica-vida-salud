import '../styles/registration.css';
import WorkflowProgress from '../../../components/WorkflowProgress';

const steps = [{ id: 'datos', label: 'Datos y acceso' }, { id: 'correo', label: 'Verificar correo' }];

export default function RegistrationProgress({ verification = false }) {
  return <WorkflowProgress label="Pasos del registro web" steps={steps} current={verification ? 'correo' : 'datos'} />;
}
