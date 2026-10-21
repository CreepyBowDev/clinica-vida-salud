import '../styles/registration.css';

export default function RegistrationProgress({ verification = false }) {
  return <ol className="registration-progress" aria-label="Pasos del registro web">
    <li aria-current={!verification ? 'step' : undefined}><span aria-hidden="true">1</span>Datos y acceso</li>
    <li aria-current={verification ? 'step' : undefined}><span aria-hidden="true">2</span>Verificar correo</li>
  </ol>;
}
