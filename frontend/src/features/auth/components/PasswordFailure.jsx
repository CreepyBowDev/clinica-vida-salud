import Alert from '../../../components/Alert';

export default function PasswordFailure({ failure, remaining }) {
  if (!failure) return null;
  return <Alert tabIndex="-1"><p>{failure.message}</p>
    {remaining > 0 && <p>Podrás volver a intentarlo en {remaining} s.</p>}
  </Alert>;
}
