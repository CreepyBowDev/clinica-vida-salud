export default function FormSection({ title, children, disabled, className = '' }) {
  return <fieldset className={`form-section ${className}`} disabled={disabled}><legend>{title}</legend>{children}</fieldset>;
}
