<?php
/**
 * Cifrado simétrico para secretos en reposo (Clave SOL, client_secret).
 *
 * AES-256-GCM: además de cifrar, autentica. Si alguien altera un byte del texto
 * cifrado en la base de datos, el descifrado falla en vez de devolver basura.
 *
 * Formato guardado:  "v1:" + base64( iv[12] | tag[16] | textoCifrado )
 *
 * La clave maestra vive en storage/clave_maestra.php, FUERA de la base de datos
 * y fuera del control de versiones: quien consiga un volcado de la base no
 * puede descifrar nada sin ese archivo.
 *
 * IMPORTANTE: si se pierde el archivo de clave, los secretos guardados son
 * irrecuperables y hay que volver a capturarlos. Incluirlo en las copias de
 * seguridad, pero nunca en el mismo lugar que el volcado de la base.
 */
class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;
    private const PREFIJO = 'v1:';

    private static ?string $clave = null;

    /** Clave maestra binaria (32 bytes). Se genera sola la primera vez. */
    private static function clave(): string
    {
        if (self::$clave !== null) {
            return self::$clave;
        }

        $archivo = BASE_PATH . '/storage/clave_maestra.php';

        if (is_file($archivo)) {
            $valor = require $archivo;
            $bin = base64_decode((string) $valor, true);
            if ($bin === false || strlen($bin) !== 32) {
                throw new RuntimeException(
                    'La clave maestra de storage/clave_maestra.php es inválida. ' .
                    'Si se perdió, hay que volver a capturar las credenciales guardadas.');
            }
            return self::$clave = $bin;
        }

        // Primera vez: se genera y se guarda como PHP, para que ni sirviéndose
        // por error el archivo muestre su contenido.
        $bin = random_bytes(32);
        $dir = dirname($archivo);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $contenido = "<?php\n"
            . "// Clave maestra de cifrado. NO subir al control de versiones.\n"
            . "// Si se pierde, los secretos guardados no se pueden descifrar.\n"
            . "// Generada el " . date('Y-m-d H:i:s') . "\n"
            . 'return ' . var_export(base64_encode($bin), true) . ";\n";

        if (file_put_contents($archivo, $contenido, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo crear storage/clave_maestra.php (¿permisos de escritura?).');
        }
        @chmod($archivo, 0600);

        return self::$clave = $bin;
    }

    public static function cifrar(string $plano): string
    {
        if ($plano === '') {
            return '';
        }
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt($plano, self::CIPHER, self::clave(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new RuntimeException('Falló el cifrado del secreto.');
        }
        return self::PREFIJO . base64_encode($iv . $tag . $ct);
    }

    public static function descifrar(string $guardado): string
    {
        if ($guardado === '') {
            return '';
        }
        if (!str_starts_with($guardado, self::PREFIJO)) {
            throw new RuntimeException('El secreto guardado no tiene el formato esperado.');
        }
        $bin = base64_decode(substr($guardado, strlen(self::PREFIJO)), true);
        if ($bin === false || strlen($bin) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('El secreto guardado está corrupto.');
        }
        $iv  = substr($bin, 0, self::IV_LEN);
        $tag = substr($bin, self::IV_LEN, self::TAG_LEN);
        $ct  = substr($bin, self::IV_LEN + self::TAG_LEN);

        $plano = openssl_decrypt($ct, self::CIPHER, self::clave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plano === false) {
            throw new RuntimeException(
                'No se pudo descifrar el secreto: la clave maestra no corresponde o el dato fue alterado.');
        }
        return $plano;
    }

    /** ¿Ya existe la clave maestra? (para avisar en pantalla). */
    public static function hayClaveMaestra(): bool
    {
        return is_file(BASE_PATH . '/storage/clave_maestra.php');
    }
}
