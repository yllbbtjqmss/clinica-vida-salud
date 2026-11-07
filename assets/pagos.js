/* Solo referencias sintéticas: este archivo no tokeniza ni procesa tarjetas bancarias. */
(() => {
    const metodos = document.getElementById('cvp-metodos-online');
    if (metodos) {
        const actualizar = () => {
            const activo = document.querySelector('[name="modalidad"]:checked')?.value === 'demo';
            metodos.disabled = !activo;
            metodos.classList.toggle('cvp-inactivo', !activo);
        };
        document.querySelectorAll('[name="modalidad"]').forEach(r => r.addEventListener('change', actualizar));
        actualizar();
    }
    const tarjeta = document.getElementById('cvp-tarjeta');
    if (tarjeta) tarjeta.addEventListener('submit', event => {
        // Lista cerrada de valores sintéticos. Los campos no tienen name y nunca se serializan.
        const valido = [...tarjeta.querySelectorAll('[data-sintetico]')]
            .every(campo => campo.readOnly && campo.value === campo.dataset.sintetico);
        if (!valido) {
            event.preventDefault();
            document.getElementById('cvp-tarjeta-error').textContent = 'Tarjeta no admitida. Recarga para utilizar únicamente el instrumento de prueba.';
            return;
        }
        tarjeta.querySelector('button[type="submit"]').disabled = true;
        document.getElementById('cvp-procesamiento').textContent = 'Enviando solicitud para verificación…';
        tarjeta.setAttribute('aria-busy', 'true');
    });
    document.querySelectorAll('[data-cuenta-atras]').forEach(elemento => {
        const restante = Number(elemento.dataset.cuentaAtras) - Number(elemento.dataset.servidor);
        const inicio = performance.now();
        const actualizar = () => {
            const segundos = Math.max(0, Math.ceil(restante - (performance.now() - inicio) / 1000));
            elemento.textContent = segundos ? `${Math.floor(segundos / 60)}:${String(segundos % 60).padStart(2, '0')}` : 'Plazo cumplido. Actualiza el estado.';
            // El reloj solo informa; nunca autoriza ni cambia estados financieros.
            if (segundos) window.setTimeout(actualizar, 1000);
        };
        actualizar();
    });
})();
