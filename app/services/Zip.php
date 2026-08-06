<?php
/**
 * Escritor de archivos ZIP sin dependencias.
 *
 * La extensión ZipArchive no está disponible en todos los hostings, así que
 * el contenedor se construye byte a byte: cabecera local + datos comprimidos
 * con deflate (zlib, siempre presente) + directorio central.
 *
 * Formato: APPNOTE.TXT 6.3.3 — sólo lo necesario para un .xlsx válido.
 */
class Zip
{
    /** @var array<int,array<string,mixed>> */
    private array $entradas = [];
    private string $buffer = '';

    /** Agrega un archivo al ZIP. */
    public function agregar(string $nombre, string $contenido): void
    {
        $crc     = crc32($contenido);
        $sinComp = strlen($contenido);

        // gzdeflate produce un flujo "raw deflate", que es exactamente el
        // método 8 del formato ZIP (sin las cabeceras de gzip ni de zlib).
        $comprimido = gzdeflate($contenido, 6);
        if ($comprimido === false || strlen($comprimido) >= $sinComp) {
            $comprimido = $contenido;   // guardar sin comprimir
            $metodo     = 0;
        } else {
            $metodo = 8;
        }

        $offset = strlen($this->buffer);
        $fecha  = self::fechaDos();

        // Cabecera local
        $this->buffer .= "\x50\x4b\x03\x04"
            . pack('v', 20)              // versión mínima
            . pack('v', 0)               // banderas
            . pack('v', $metodo)
            . pack('V', $fecha)          // hora + fecha MS-DOS
            . pack('V', $crc)
            . pack('V', strlen($comprimido))
            . pack('V', $sinComp)
            . pack('v', strlen($nombre))
            . pack('v', 0)               // sin campo extra
            . $nombre
            . $comprimido;

        $this->entradas[] = [
            'nombre'  => $nombre,
            'crc'     => $crc,
            'comp'    => strlen($comprimido),
            'sin'     => $sinComp,
            'metodo'  => $metodo,
            'offset'  => $offset,
            'fecha'   => $fecha,
        ];
    }

    /** Cierra el contenedor y devuelve el binario completo. */
    public function generar(): string
    {
        $central = '';
        foreach ($this->entradas as $e) {
            $central .= "\x50\x4b\x01\x02"
                . pack('v', 20)          // versión que creó el archivo
                . pack('v', 20)          // versión mínima
                . pack('v', 0)
                . pack('v', $e['metodo'])
                . pack('V', $e['fecha'])
                . pack('V', $e['crc'])
                . pack('V', $e['comp'])
                . pack('V', $e['sin'])
                . pack('v', strlen($e['nombre']))
                . pack('v', 0)           // extra
                . pack('v', 0)           // comentario
                . pack('v', 0)           // disco
                . pack('v', 0)           // atributos internos
                . pack('V', 32)          // atributos externos (archivo normal)
                . pack('V', $e['offset'])
                . $e['nombre'];
        }

        $n = count($this->entradas);

        return $this->buffer . $central . "\x50\x4b\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', $n) . pack('v', $n)
            . pack('V', strlen($central))
            . pack('V', strlen($this->buffer))
            . pack('v', 0);
    }

    /** Fecha y hora en el formato empaquetado de MS-DOS. */
    private static function fechaDos(): int
    {
        $t = getdate();
        if ($t['year'] < 1980) {
            return (1 << 21) | (1 << 16);
        }
        return (($t['year'] - 1980) << 25) | ($t['mon'] << 21) | ($t['mday'] << 16)
             | ($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1);
    }
}
