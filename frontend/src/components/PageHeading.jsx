import ActionGroup from './ActionGroup';

export default function PageHeading({ title, description, actions, titleRef }) {
  return <header className="page-heading page-heading--actions"><div className="page-heading-copy"><h1 ref={titleRef} tabIndex={titleRef ? -1 : undefined}>{title}</h1>
    {description && <p>{description}</p>}</div>{actions && <ActionGroup>{actions}</ActionGroup>}</header>;
}
