import { useEffect, useState } from 'react';
import FormField from './FormField';
import Icon from './Icon';

export default function PasswordField({ label, value, busy, ...props }) {
  const [visible, setVisible] = useState(false);
  useEffect(() => { if (!value) setVisible(false); }, [value]);
  return <FormField {...props} label={label} value={value} type={visible ? 'text' : 'password'}
    required maxLength={1024} readOnly={busy}
    trailing={<button type="button" className="password-toggle" disabled={busy}
      aria-label={`${visible ? 'Ocultar' : 'Mostrar'} ${label.toLowerCase()}`} aria-pressed={visible}
      onClick={() => setVisible(!visible)}><Icon name={visible ? 'eyeOff' : 'eye'} /></button>} />;
}
