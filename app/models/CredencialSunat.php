<?php
/**
 * Credenciales SUNAT de la empresa activa.
 *
 * Los secretos (Clave SOL y client_secret) se guardan cifrados y sólo se
 * descifran en memoria, justo antes de hablar con SUNAT. Nunca se muestran en
 * pantalla ni se escriben en la auditoría.
 */
class CredencialSunat
{
    /** Fila cruda de la empresa activa, con los secretos aún cifrados. */
    public static function deEmpresa(): ?array
    {
        return DB::uno('SELECT * FROM credenciales_sunat WHERE ' . Empresa::filtro(), Empresa::param());
    }

    public static function existe(): bool
    {
        return self::deEmpresa() !== null;
    }

    /**
     * Devuelve las credenciales listas para usar, con los secretos descifrados.
     * El arreglo resultante NO debe guardarse ni registrarse en ningún log.
     */
    public static function descifradas(): array
    {
        $c = self::deEmpresa();
        if (!$c) {
            throw new RuntimeException('Esta empresa todavía no tiene credenciales SUNAT configuradas.');
        }
        return [
            'ruc'           => $c['ruc'],
            'usuario'       => $c['usuario_sol'],
            'clave'         => Crypto::descifrar((string) $c['clave_sol']),
            'client_id'     => (string) ($c['client_id'] ?? ''),
            'client_secret' => $c['client_secret'] ? Crypto::descifrar((string) $c['client_secret']) : '',
        ];
    }

    /**
     * Guarda o actualiza. Los secretos vacíos NO borran lo ya guardado: así se
     * puede corregir el usuario sin volver a escribir la clave.
     */
    public static function guardar(array $d): void
    {
        $ruc = preg_replace('/\D/', '', (string) ($d['ruc'] ?? ''));
        if (strlen($ruc) !== 11) {
            throw new InvalidArgumentException('El RUC debe tener 11 dígitos.');
        }
        $usuario = trim((string) ($d['usuario_sol'] ?? ''));
        if ($usuario === '') {
            throw new InvalidArgumentException('Falta el usuario SOL.');
        }

        $actual = self::deEmpresa();
        $clave  = (string) ($d['clave_sol'] ?? '');
        $secret = (string) ($d['client_secret'] ?? '');

        if (!$actual && $clave === '') {
            throw new InvalidArgumentException('Falta la Clave SOL.');
        }

        $datos = [
            'ruc'         => $ruc,
            'usuario_sol' => $usuario,
            'client_id'   => trim((string) ($d['client_id'] ?? '')) ?: null,
            // Cambiar cualquier dato invalida la última verificación.
            'estado'      => 'SIN_PROBAR',
            'mensaje'     => null,
        ];
        if ($clave !== '')  $datos['clave_sol']     = Crypto::cifrar($clave);
        if ($secret !== '') $datos['client_secret'] = Crypto::cifrar($secret);

        if ($actual) {
            DB::actualizar('credenciales_sunat', $datos, 'id = :id AND ' . Empresa::filtro(),
                Empresa::param() + [':id' => $actual['id']]);
            Auditoria::registrar('EDITAR', 'credenciales_sunat', $actual['id'],
                ['ruc' => $ruc, 'usuario' => $usuario]);   // sin secretos
        } else {
            $id = DB::insertar('credenciales_sunat', Empresa::sello($datos));
            Auditoria::registrar('CREAR', 'credenciales_sunat', $id,
                ['ruc' => $ruc, 'usuario' => $usuario]);
        }
    }

    public static function eliminar(): void
    {
        $c = self::deEmpresa();
        if (!$c) return;
        DB::eliminar('credenciales_sunat', 'id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':id' => $c['id']]);
        Auditoria::registrar('ELIMINAR', 'credenciales_sunat', $c['id']);
    }

    /** Anota el resultado de una prueba de conexión. */
    public static function anotarPrueba(bool $ok, string $mensaje, array $recursos = []): void
    {
        $c = self::deEmpresa();
        if (!$c) return;
        DB::actualizar('credenciales_sunat', [
            'estado'        => $ok ? 'OK' : 'ERROR',
            'mensaje'       => mb_substr($mensaje, 0, 400),
            'recursos'      => $recursos ? implode("\n", $recursos) : null,
            'verificado_en' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => $c['id']]);
    }
}
