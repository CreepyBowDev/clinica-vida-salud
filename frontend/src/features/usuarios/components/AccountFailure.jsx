import Alert from '../../../components/Alert';

export default function AccountFailure({ failure, remaining = 0 }) {
  if (!failure) return null;
  return <Alert tabIndex="-1"><p>{failure.message}</p>{remaining > 0 && <p>Podrás volver a intentarlo en {remaining} s.</p>}</Alert>;
}
