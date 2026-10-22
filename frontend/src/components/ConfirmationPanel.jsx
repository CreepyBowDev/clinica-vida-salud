import { useId } from 'react';
import Icon from './Icon';

export default function ConfirmationPanel({ title, children, label }) {
  const id = useId();
  return <section className="confirmation-panel" aria-label={label} aria-labelledby={label ? undefined : id}><div className="confirmation-heading"><Icon name="info" /><h2 id={id}>{title}</h2></div>{children}</section>;
}
