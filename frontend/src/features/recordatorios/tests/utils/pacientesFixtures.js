export const paciente = (id_paciente = '21', nombres = 'Paciente') => ({
  id_paciente, nombres, apellidos: 'De prueba', codigo_paciente: `PAC-${id_paciente}`,
  documento_identidad: null, activo: false,
});
export const pacientes = () => ({ data: [paciente(), paciente('22', 'Otra persona')],
  meta: { current_page: 1, per_page: 20, total: 2, last_page: 1 } });
