/* Interfaz de selección. La autorización, el importe y la reserva se deciden en PHP/PostgreSQL. */
(() => {
    const form = document.getElementById('cvp-seleccion');
    if (form) {
        const esp = document.getElementById('cvp-especialidad');
        const med = document.getElementById('cvp-medico');
        const fecha = document.getElementById('cvp-fecha');
        const bloques = document.getElementById('cvp-bloques');
        const precio = document.getElementById('cvp-precio');
        const error = document.getElementById('cvp-error');
        let revisionMedicos = 0, revisionBloques = 0;
        const consultar = async (params) => {
            const res = await fetch('reservar_cita.php?' + new URLSearchParams(params), {headers: {'Accept': 'application/json'}});
            if (!(res.headers.get('Content-Type') || '').includes('application/json')) {
                throw new Error('La sesión venció o el servicio no está disponible. Vuelve a iniciar sesión antes de reservar.');
            }
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.error || 'No se pudo consultar la disponibilidad. Revisa la sesión.');
            }
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            return data;
        };
        async function cargarBloques() {
            const revision = ++revisionBloques;
            bloques.replaceChildren(); error.textContent = ''; precio.textContent = 'Selecciona una consulta con tarifa configurada.';
            if (!med.value || !fecha.value) return;
            bloques.textContent = 'Consultando...';
            try {
                const data = await consultar({consulta: 'bloques', id_especialidad: esp.value, id_medico: med.value, fecha: fecha.value});
                if (revision !== revisionBloques) return;
                bloques.replaceChildren(); precio.textContent = 'Importe: Bs ' + Number(data.tarifa).toLocaleString('es-BO', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                for (const b of data.bloques) {
                    const label = document.createElement('label'); label.className = 'cvp-turno';
                    const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'hora_inicio'; radio.value = b.hora_inicio; radio.required = true;
                    radio.checked = b.hora_inicio === form.dataset.hora;
                    const texto = document.createElement('span'); texto.textContent = b.hora_inicio + ' – ' + b.hora_fin;
                    label.append(radio, texto); bloques.append(label);
                }
                if (!data.bloques.length) bloques.textContent = 'No hay bloques disponibles en esta fecha.';
            } catch (ex) { if (revision === revisionBloques) { bloques.textContent = ''; error.textContent = ex.message; } }
        }
        async function cargarMedicos() {
            const revision = ++revisionMedicos; ++revisionBloques;
            med.replaceChildren(new Option('Selecciona...', '')); bloques.replaceChildren(); error.textContent = '';
            precio.textContent = 'Selecciona una consulta para ver su tarifa.';
            if (!esp.value) return;
            try {
                const data = await consultar({consulta: 'medicos', id_especialidad: esp.value});
                if (revision !== revisionMedicos) return;
                for (const m of data) {
                    const opt = new Option(m.apellidos + ', ' + m.nombres + (m.importe === null ? ' · Sin tarifa' : ' · Bs ' + Number(m.importe).toLocaleString('es-BO', {minimumFractionDigits: 2, maximumFractionDigits: 2})), m.id_medico);
                    opt.disabled = m.importe === null; med.add(opt);
                }
                med.value = form.dataset.medico || ''; form.dataset.medico = '';
                await cargarBloques();
            } catch (ex) { if (revision === revisionMedicos) error.textContent = ex.message; }
        }
        esp.addEventListener('change', cargarMedicos); med.addEventListener('change', cargarBloques); fecha.addEventListener('change', cargarBloques);
        cargarMedicos();
    }
    document.querySelectorAll('.cvp-envio').forEach(f => f.addEventListener('submit', () => {
        f.querySelectorAll('button[type="submit"]').forEach(b => { b.disabled = true; });
    }));
    document.querySelectorAll('[data-imprimir]').forEach(b => b.addEventListener('click', () => window.print()));
})();
