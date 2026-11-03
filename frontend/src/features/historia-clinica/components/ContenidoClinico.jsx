import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export const etiquetasConsulta = { tipo_consulta: 'Tipo de consulta', motivo_consulta: 'Motivo de consulta', sintomas: 'Síntomas', observaciones: 'Observaciones', diagnostico: 'Diagnóstico', indicaciones_tratamiento: 'Indicaciones y tratamiento' };
export const etiquetasAntecedentes = { tipo_sangre: 'Tipo de sangre', alergias: 'Alergias', enfermedades_cronicas: 'Enfermedades crónicas', medicamentos: 'Medicamentos', enfermedades_familiares: 'Enfermedades familiares', antecedentes_relevantes: 'Antecedentes relevantes' };

export function FechaClinica({ value }) {
  return value === null ? <>No registrado</> : <time dateTime={value}>{formatearInstanteCita(value)}</time>;
}
export function IdentidadClinica({ paciente }) {
  return <header className="clinical-identity"><h2>{paciente.nombres} {paciente.apellidos}</h2><p>Código de paciente: {paciente.codigo_paciente}</p></header>;
}
export function ContenidoClinico({ version, tipo }) {
  if (version === null) return <p>No hay antecedentes registrados.</p>;
  return <dl className="clinical-data clinical-content" data-content={tipo}>{Object.entries(tipo === 'consulta' ? etiquetasConsulta : etiquetasAntecedentes).map(([campo, label]) =>
    <div key={campo}><dt>{label}</dt><dd>{version[campo] === null || version[campo] === '' ? 'No registrado' : version[campo]}</dd></div>)}</dl>;
}
