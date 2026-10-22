import Icon from './Icon';

export default function Alert({ children, variant = 'error', className = '', ...props }) {
  return <div className={`alert alert--${variant} ${className}`} role={variant === 'error' ? 'alert' : 'status'} {...props}>
    <Icon name={variant === 'success' ? 'check' : 'info'} /><div>{children}</div>
  </div>;
}
