import Icon from './Icon';

export default function Alert({ children, variant = 'error', ...props }) {
  return <div className={`alert alert--${variant}`} role={variant === 'error' ? 'alert' : 'status'} {...props}>
    <Icon name={variant === 'success' ? 'check' : 'info'} /><div>{children}</div>
  </div>;
}
