export const paciente = { id_paciente: '9007199254740993', codigo_paciente: 'PAC-FICTICIO', nombres: 'Paciente', apellidos: 'Clínico ficticio' };
export const medico = { id_medico: '12', codigo_medico: 'MED-FICTICIO', nombres: 'Médica', apellidos: 'De prueba' };
export const instante = '2026-10-30T02:30:00.123456Z';
export const meta = (total = 1, page = 1, per_page = 20) => ({ total, current_page: page, per_page, last_page: Math.max(1, Math.ceil(total / per_page)), zona_horaria: 'America/La_Paz' });
export const version = (tipo = 'consulta') => ({
  ...(tipo === 'consulta' ? { id_version_consulta: '9007199254740995', tipo_consulta: 'Control', motivo_consulta: 'Seguimiento ficticio', sintomas: null,
    observaciones: 'Observación ficticia\nSegunda línea', diagnostico: null, indicaciones_tratamiento: null }
    : { id_version_antecedente: '9007199254740996', id_consulta_origen: '31', tipo_sangre: null, alergias: null,
      enfermedades_cronicas: null, medicamentos: 'Medicación ficticia', enfermedades_familiares: null, antecedentes_relevantes: null }),
  numero_version: 1, registrada_en: instante, id_autor: '50', motivo_cambio: 'MOTIVO-INTERNO',
});
export const resumen = () => ({ id_consulta: '31', codigo_consulta: 'CON-FICTICIA', id_cita: '41', inicio_at: instante, tipo_consulta: 'Control', medico });
export const historia = () => ({ data: { paciente, antecedentes: version('antecedentes'), consultas: [resumen()] }, meta: meta() });
export const detalle = () => ({ data: { paciente, antecedentes: version('antecedentes'), cita: { id_cita: '41', id_paciente: paciente.id_paciente,
  id_medico: medico.id_medico, codigo_cita: 'CITA-FICTICIA', inicio_at: instante, fin_at: '2026-10-30T02:45:00.123456Z', estado: 'Programada',
  modalidad: 'Presencial', sede_atencion: 'Sede ficticia', consultorio_atencion: 'Sala ficticia', medico, version_programacion: 1, actualizada_en: instante },
  consulta: { id_consulta: '31', codigo_consulta: 'CON-FICTICIA', creada_en: instante, iniciada_en: null, version_vigente: version() },
  estudios: [{ id_solicitud: '61', estudio_solicitado: 'Estudio ficticio', solicitada_en: instante, estado: 'Pendiente', resultado: null }],
  edicion: { originales: { version_programacion: 1, actualizada_en: instante, id_consulta: '31', id_version_consulta: '9007199254740995', id_version_antecedente: '9007199254740996', secreto: 'NO-CONSERVAR' },
    consultado_en: instante, acciones: { registrar: false, actualizar: true }, impedimentos: { registrar: ['CLINICA_CONSULTA_EXISTENTE'], actualizar: [] } },
}, meta: meta() });
export const sesion = rol => ({ id_usuario: '7', rol, nombre_visible: 'Persona ficticia', correo_acceso: 'ficticia@example.test' });
export const contextoAlta = () => {
  const d = detalle(); d.data.consulta = null; d.data.estudios = []; d.meta = meta(0);
  d.data.edicion.originales.id_consulta = null; d.data.edicion.originales.id_version_consulta = null;
  d.data.edicion.acciones = { registrar: true, actualizar: false };
  d.data.edicion.impedimentos = { registrar: [], actualizar: ['RECURSO_NO_ENCONTRADO'] };
  return d;
};
export const altaConfirmada = (payload, idActor = '7') => {
  const d = detalle(); d.data.estudios = []; d.meta = meta(0);
  d.data.consulta.version_vigente = { ...version(), ...payload.consulta, id_autor: idActor, motivo_cambio: null };
  for (const k of ['sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento']) if (!Object.hasOwn(payload.consulta, k)) d.data.consulta.version_vigente[k] = null;
  if (payload.antecedentes) d.data.antecedentes = { ...d.data.antecedentes, ...payload.antecedentes, id_version_antecedente: '9007199254740998', numero_version: 2, id_autor: idActor, motivo_cambio: null };
  return d;
};
export const correccionConfirmada = (payload, original = detalle(), idActor = '7') => {
  const d = structuredClone(original), c = d.data.consulta.version_vigente, a = d.data.antecedentes;
  const cambioC = Object.entries(payload.consulta ?? {}).some(([k,v]) => v !== c[k]);
  const cambioA = Object.entries(payload.antecedentes ?? {}).some(([k,v]) => v !== (a?.[k] ?? null));
  if (cambioC) d.data.consulta.version_vigente = { ...c, ...payload.consulta, id_version_consulta: '9007199254740997', numero_version: c.numero_version + 1, id_autor: idActor, motivo_cambio: payload.motivo_cambio };
  if (cambioA) d.data.antecedentes = { ...(a ?? { ...version('antecedentes'), medicamentos: null }), ...payload.antecedentes,
    id_version_antecedente: '9007199254740998', numero_version: (a?.numero_version ?? 0) + 1, id_autor: idActor, motivo_cambio: payload.motivo_cambio, id_consulta_origen: d.data.consulta.id_consulta };
  d.data.modificado = cambioC || cambioA;
  return d;
};
