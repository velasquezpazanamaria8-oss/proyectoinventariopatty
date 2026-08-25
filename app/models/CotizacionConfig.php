<?php
/**
 * Cómo se ve la cotización de cada empresa.
 *
 * Las ocho empresas del grupo no quieren que sus cotizaciones se parezcan entre
 * sí, y por eso cada Excel salió distinto. Aquí se guarda exactamente lo que
 * cambia —logo, color, columnas, textos, cuentas— de modo que el generador de
 * PDF sea uno solo y añadir una empresa nueva sea rellenar un formulario.
 */
class CotizacionConfig
{
    /** Campos que puede llevar la tabla de ítems, con su nombre habitual. */
    public const CAMPOS = [
        'unidad'      => 'U.M',
        'cantidad'    => 'CANT.',
        'descripcion' => 'DESCRIPCIÓN',
        'precio'      => 'VALOR UNITARIO',
        'importe'     => 'VR. TOTAL',
    ];

    /** Ancho relativo por defecto de cada campo, en partes de la fila. */
    private const ANCHOS = [
        'unidad' => 8, 'cantidad' => 9, 'descripcion' => 51, 'precio' => 16, 'importe' => 16,
    ];

    public static function de(int $empresaId): array
    {
        $c = DB::uno('SELECT * FROM cotizacion_config WHERE empresa_id = :e', [':e' => $empresaId]);
        if (!$c) {
            $c = self::porDefecto();
            $c['empresa_id'] = $empresaId;
        }
        $c['columnas'] = self::columnas($c['columnas'] ?? null);
        return $c;
    }

    /** La de la empresa activa. */
    public static function actual(): array
    {
        return self::de(Empresa::id());
    }

    private static function porDefecto(): array
    {
        return [
            'logo_ruta' => null, 'logo_posicion' => 'IZQUIERDA', 'color' => '#12395B',
            'titulo' => 'COTIZACIÓN', 'prefijo' => null, 'digitos' => 4,
            'etiqueta_ref' => 'SEGÚN REQUERIMIENTO',
            'emisor_etiquetas' => 0, 'emisor_derecha' => 0,
            'mostrar_telefono' => 0, 'mostrar_fecha' => 1,
            'columnas' => null, 'condiciones' => null, 'notas' => null,
            'firma_izq' => null, 'firma_der' => 'CLIENTE', 'incluye_igv' => 1,
        ];
    }

    /**
     * Columnas de la tabla, ya normalizadas.
     *
     * La descripción y el importe no se pueden quitar: sin la una no se sabe
     * qué se cotiza y sin el otro no hay total que sumar.
     */
    public static function columnas(?string $json): array
    {
        $guardadas = $json ? json_decode($json, true) : null;
        if (!is_array($guardadas) || !$guardadas) {
            $guardadas = array_map(
                fn($campo, $titulo) => ['campo' => $campo, 'titulo' => $titulo],
                array_keys(self::CAMPOS), array_values(self::CAMPOS));
        }

        $out = [];
        foreach ($guardadas as $c) {
            $campo = $c['campo'] ?? '';
            if (!isset(self::CAMPOS[$campo]) || isset($out[$campo])) {
                continue;                       // desconocida o repetida
            }
            $out[$campo] = [
                'campo'  => $campo,
                'titulo' => trim((string) ($c['titulo'] ?? '')) ?: self::CAMPOS[$campo],
                'ancho'  => (int) ($c['ancho'] ?? self::ANCHOS[$campo]),
            ];
        }
        foreach (['descripcion', 'importe'] as $obligatoria) {
            if (!isset($out[$obligatoria])) {
                $out[$obligatoria] = ['campo' => $obligatoria,
                    'titulo' => self::CAMPOS[$obligatoria], 'ancho' => self::ANCHOS[$obligatoria]];
            }
        }
        return array_values($out);
    }

    /**
     * Deja los datos del formulario listos para guardar.
     *
     * Vive aparte de guardar() porque la vista previa necesita exactamente
     * esta normalización sin escribir nada: si el PDF de la previa se armara
     * con los datos crudos del formulario, enseñaría un documento que no es
     * el que se va a guardar.
     */
    public static function normalizar(array $d): array
    {
        $columnas = [];
        foreach ((array) ($d['col_campo'] ?? []) as $i => $campo) {
            if (empty($d['col_activa'][$i]) || !isset(self::CAMPOS[$campo])) {
                continue;
            }
            $columnas[] = [
                'campo'  => $campo,
                'titulo' => mb_substr(trim((string) ($d['col_titulo'][$i] ?? '')), 0, 40),
                'ancho'  => max(4, min(70, (int) ($d['col_ancho'][$i] ?? 10))),
            ];
        }

        return [
            'logo_posicion'    => in_array($d['logo_posicion'] ?? '', ['IZQUIERDA','CENTRO','DERECHA'], true)
                                    ? $d['logo_posicion'] : 'IZQUIERDA',
            'color'            => preg_match('/^#[0-9A-Fa-f]{6}$/', $d['color'] ?? '') ? strtoupper($d['color']) : '#12395B',
            'titulo'           => mb_substr(trim((string) ($d['titulo'] ?? '')), 0, 60) ?: 'COTIZACIÓN',
            'prefijo'          => mb_substr(trim((string) ($d['prefijo'] ?? '')), 0, 20) ?: null,
            'digitos'          => max(1, min(8, (int) ($d['digitos'] ?? 4))),
            'etiqueta_ref'     => mb_substr(trim((string) ($d['etiqueta_ref'] ?? '')), 0, 60),
            'emisor_etiquetas' => !empty($d['emisor_etiquetas']) ? 1 : 0,
            'emisor_derecha'   => !empty($d['emisor_derecha']) ? 1 : 0,
            'mostrar_telefono' => !empty($d['mostrar_telefono']) ? 1 : 0,
            'mostrar_fecha'    => !empty($d['mostrar_fecha']) ? 1 : 0,
            'columnas'         => $columnas ? json_encode($columnas, JSON_UNESCAPED_UNICODE) : null,
            'condiciones'      => trim((string) ($d['condiciones'] ?? '')) ?: null,
            'notas'            => trim((string) ($d['notas'] ?? '')) ?: null,
            'firma_izq'        => mb_substr(trim((string) ($d['firma_izq'] ?? '')), 0, 80) ?: null,
            'firma_der'        => mb_substr(trim((string) ($d['firma_der'] ?? '')), 0, 80) ?: null,
            'incluye_igv'      => !empty($d['incluye_igv']) ? 1 : 0,
        ];
    }

    /**
     * Config lista para armar un PDF con lo que hay en el formulario, sin
     * tocar la base. El logo es el que ya está guardado: uno recién elegido
     * todavía no ha subido al servidor.
     */
    public static function desdeFormulario(array $d): array
    {
        $cfg = self::normalizar($d);
        $cfg['columnas']  = self::columnas($cfg['columnas']);
        $cfg['logo_ruta'] = DB::valor(
            'SELECT logo_ruta FROM cotizacion_config WHERE empresa_id = :e',
            [':e' => Empresa::id()]);
        return $cfg;
    }

    public static function guardar(array $d, ?array $logo = null): void
    {
        $empresaId = Empresa::id();

        $datos = self::normalizar($d) + ['actualizado_en' => date('Y-m-d H:i:s')];

        if ($logo !== null) {
            $datos['logo_ruta'] = self::guardarLogo($logo);
        }

        $existe = DB::valor('SELECT empresa_id FROM cotizacion_config WHERE empresa_id = :e', [':e' => $empresaId]);
        if ($existe) {
            DB::actualizar('cotizacion_config', $datos, 'empresa_id = :e', [':e' => $empresaId]);
        } else {
            DB::insertar('cotizacion_config', ['empresa_id' => $empresaId] + $datos);
        }
        Auditoria::registrar('DISENO_COTIZACION', 'cotizacion_config', $empresaId);
    }

    /**
     * Deja el logo listo para el PDF.
     *
     * Se convierte a JPEG sobre fondo blanco: incrustar un JPEG en un PDF es
     * directo, mientras que un PNG con transparencia obliga a separar el canal
     * alfa y rearmarlo. Sobre el papel blanco de la cotización no se nota la
     * diferencia, y así cualquier logo vale.
     */
    private static function guardarLogo(array $archivo): string
    {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new RuntimeException('No se pudo recibir el logo. Inténtelo de nuevo.');
        }
        if ($archivo['size'] > 3 * 1024 * 1024) {
            throw new RuntimeException('El logo pasa de 3 MB.');
        }

        $info = @getimagesize($archivo['tmp_name']);
        if (!$info) {
            throw new RuntimeException('Ese archivo no es una imagen.');
        }
        [$ancho, $alto, $tipo] = $info;

        $dir = BASE_PATH . '/storage/logos';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $destino = $dir . '/empresa-' . Empresa::id() . '.jpg';

        if (!extension_loaded('gd')) {
            // Sin GD sólo se puede aceptar lo que ya viene en JPEG.
            if ($tipo !== IMAGETYPE_JPEG) {
                throw new RuntimeException('Este servidor no puede convertir imágenes: '
                    . 'suba el logo en formato JPG.');
            }
            copy($archivo['tmp_name'], $destino);
            return 'storage/logos/' . basename($destino);
        }

        $origen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($archivo['tmp_name']),
            IMAGETYPE_PNG  => @imagecreatefrompng($archivo['tmp_name']),
            IMAGETYPE_GIF  => @imagecreatefromgif($archivo['tmp_name']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($archivo['tmp_name']),
            default        => null,
        };
        if (!$origen) {
            throw new RuntimeException('No se pudo leer la imagen. Pruebe con un JPG o un PNG.');
        }

        // Se acota el tamaño: en el PDF el logo ocupa unos 150 puntos de ancho,
        // así que guardar 4000 píxeles sólo engorda el archivo.
        $max = 600;
        $escala = min(1, $max / max($ancho, $alto));
        $nAncho = max(1, (int) round($ancho * $escala));
        $nAlto  = max(1, (int) round($alto * $escala));

        $lienzo = imagecreatetruecolor($nAncho, $nAlto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $nAncho, $nAlto, $ancho, $alto);
        imagejpeg($lienzo, $destino, 88);
        imagedestroy($lienzo);
        imagedestroy($origen);

        return 'storage/logos/' . basename($destino);
    }

    /** Número tal como se enseña: prefijo + correlativo con ceros. */
    public static function formatoNumero(array $cfg, int $numero): string
    {
        return (string) ($cfg['prefijo'] ?? '')
            . str_pad((string) $numero, (int) ($cfg['digitos'] ?? 4), '0', STR_PAD_LEFT);
    }
}
