import { useId } from 'react';
import PasswordField from '../../../components/PasswordField';
import Icon from '../../../components/Icon';
import { evaluarRequisitosClave } from '../utils/passwordValidation';
import '../styles/password-requirements.css';

export default function PasswordCreationFields({ password, confirmation, onPasswordChange, onConfirmationChange, busy, fields, label = 'Nueva contraseña', confirmationLabel = 'Confirmar nueva contraseña' }) {
  const id = useId();
  const requirements = evaluarRequisitosClave(password);
  const matches = confirmation === password;
  return <>
    <div className="password-creation-field">
      <PasswordField label={label} name="password" autoComplete="new-password" minLength={12}
        value={password} busy={busy} onChange={onPasswordChange} error={fields?.password} aria-describedby={id} />
      <div className="password-requirements" id={id}>
        <ul aria-label="Requisitos de la contraseña">
          {requirements.map(requirement => <li key={requirement.id} className={requirement.met ? 'password-requirement--met' : undefined}>
            {requirement.met ? <Icon name="check" width="16" height="16" /> : <span className="password-requirement-pending" aria-hidden="true">○</span>}
            <span>{requirement.label}<span className="password-requirement-state">: {requirement.met ? 'Cumplido' : 'Pendiente'}</span></span>
          </li>)}
        </ul>
        <p>Los espacios no cuentan como carácter especial.</p>
      </div>
    </div>
    <PasswordField label={confirmationLabel} name="password_confirmation" autoComplete="new-password" value={confirmation}
      busy={busy} onChange={onConfirmationChange}
      error={fields?.password_confirmation || (confirmation && !matches ? 'La confirmación de la contraseña no coincide.' : undefined)}
      hint={confirmation && matches ? 'Las contraseñas coinciden.' : undefined} />
  </>;
}
