import Icon from './Icon';

export default function QueryMessage({ title, description, loading = false, className = '' }) {
  if (loading) return <p className={`query-message query-message--loading ${className}`} role="status"><Icon name="info" />{title}</p>;
  return <div className={`query-message ${className}`}><h2>{title}</h2>{description && <p>{description}</p>}</div>;
}
