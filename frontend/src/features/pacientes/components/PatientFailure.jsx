import Alert from '../../../components/Alert';

export default function PatientFailure({ failure, remaining = failure?.retryAfter }) {
  if (!failure) return null;
  return <Alert tabIndex="-1"><p>{failure.message}</p>{remaining > 0 && <p>Espera al menos {remaining} segundos antes de volver a intentarlo.</p>}</Alert>;
}
