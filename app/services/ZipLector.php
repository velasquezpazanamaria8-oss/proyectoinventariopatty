<?php
/**
 * Lector de archivos ZIP sin dependencias (contraparte de Zip.php).
 *
 * Recorre el directorio central del contenedor y descomprime las entradas
 * con `gzinflate`. Sólo admite los métodos 0 (almacenado) y 8 (deflate),
 * que son los que usa cualquier .xlsx real.
 */
class ZipLector
{
    private string $bin;
    /** @var array<string,array{offset:int,comp:int,sin:int,metodo:int,crc:int}> */
    private array $indice = [];

    public function __construct(string $binario)
    {
        $this->bin = $binario;
        $this->leerDirectorio();
    }

    public static function desdeArchivo(string $ruta): self
    {
        $bin = @file_get_contents($ruta);
        if ($bin === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }
        return new self($bin);
    }

    /** Nombres de las entradas contenidas. */
    public function archivos(): array
    {
        return array_keys($this->indice);
    }

    /**
     * Todas las entradas ya descomprimidas: [nombre => contenido].
     * Una entrada dañada no aborta el resto: se devuelve vacía.
     */
    public static function entradas(string $binario): array
    {
        $zip = new self($binario);
        $out = [];
        foreach ($zip->archivos() as $nombre) {
            try {
                $out[$nombre] = $zip->leer($nombre);
            } catch (Throwable $e) {
                $out[$nombre] = '';
            }
        }
        return $out;
    }

    public function existe(string $nombre): bool
    {
        return isset($this->indice[$nombre]);
    }

    /** Devuelve el contenido descomprimido de una entrada. */
    public function leer(string $nombre): string
    {
        if (!isset($this->indice[$nombre])) {
            throw new RuntimeException("El archivo no contiene la parte: $nombre");
        }
        $e = $this->indice[$nombre];

        // La cabecera local repite los tamaños de nombre y campo extra, que
        // pueden diferir de los del directorio central: hay que releerlos.
        $cab = substr($this->bin, $e['offset'], 30);
        if (strlen($cab) < 30 || substr($cab, 0, 4) !== "\x50\x4b\x03\x04") {
            throw new RuntimeException('Archivo ZIP dañado: cabecera local inválida.');
        }
        $d = unpack('vlargoNombre/vlargoExtra', substr($cab, 26, 4));
        $inicio = $e['offset'] + 30 + $d['largoNombre'] + $d['largoExtra'];

        $datos = substr($this->bin, $inicio, $e['comp']);

        if ($e['metodo'] === 8) {
            $salida = @gzinflate($datos);
            if ($salida === false) {
                throw new RuntimeException("No se pudo descomprimir: $nombre");
            }
        } elseif ($e['metodo'] === 0) {
            $salida = $datos;
        } else {
            throw new RuntimeException("Método de compresión no soportado ({$e['metodo']}) en: $nombre");
        }

        if ($e['crc'] !== 0 && crc32($salida) !== $e['crc']) {
            throw new RuntimeException("El archivo está dañado (CRC no coincide en $nombre).");
        }
        return $salida;
    }

    /** Localiza y recorre el directorio central. */
    private function leerDirectorio(): void
    {
        // El registro final está al final, pero puede llevar comentario:
        // se busca hacia atrás en los últimos 64 KB.
        $tope  = min(strlen($this->bin), 65557);
        $trozo = substr($this->bin, -$tope);
        $pos   = strrpos($trozo, "\x50\x4b\x05\x06");
        if ($pos === false) {
            throw new RuntimeException('El archivo no es un ZIP válido (falta el registro final).');
        }
        $fin = strlen($this->bin) - $tope + $pos;

        $eocd = unpack('vtotal/Vtamano/Vinicio', substr($this->bin, $fin + 10, 12));
        $p    = $eocd['inicio'];

        for ($i = 0; $i < $eocd['total']; $i++) {
            if (substr($this->bin, $p, 4) !== "\x50\x4b\x01\x02") {
                break;   // directorio truncado: se usa lo leído hasta aquí
            }
            // Los 42 bytes de campos empiezan justo después de la firma (+4).
            $c = unpack(
                'vversion/vversionMin/vbanderas/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vsin/'
                . 'vlargoNombre/vlargoExtra/vlargoComentario/vdisco/vinterno/Vexterno/Voffset',
                substr($this->bin, $p + 4, 42));

            $nombre = substr($this->bin, $p + 46, $c['largoNombre']);

            $this->indice[$nombre] = [
                'offset' => $c['offset'],
                'comp'   => $c['comp'],
                'sin'    => $c['sin'],
                'metodo' => $c['metodo'],
                'crc'    => $c['crc'],
            ];

            $p += 46 + $c['largoNombre'] + $c['largoExtra'] + $c['largoComentario'];
        }

        if (!$this->indice) {
            throw new RuntimeException('El archivo ZIP está vacío.');
        }
    }
}
