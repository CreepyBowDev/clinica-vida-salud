export default function ActionGroup({ children, className = '', ...props }) {
  return <div className={`action-group ${className}`} {...props}>{children}</div>;
}
