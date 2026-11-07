<?php
declare(strict_types=1);

/** Contrato local, no representa endpoints ni autenticación de un banco. */
interface CvsPasarelaPagos {
    public function simular(string $escenario): array;
}

final class CvsPasarelaDemostracion implements CvsPasarelaPagos {
    public function simular(string $escenario): array {
        if (!in_array($escenario, ['exitoso', 'fallido', 'pendiente', 'vencido'], true)) {
            throw new DomainException('Selecciona un resultado de demostración válido.');
        }
        return ['resultado'=>$escenario, 'referencia'=>'DEMO-' . strtoupper(bin2hex(random_bytes(16)))];
    }
}
