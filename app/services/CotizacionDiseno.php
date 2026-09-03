<?php
/**
 * El diseño libre de la cotización: bloques colocados a mano sobre la hoja.
 *
 * El modo SIMPLE —el formulario de opciones— cubre a casi todas las empresas,
 * pero el contador cambia el orden de las cosas de una empresa a otra, y eso
 * no se resuelve con más casillas. En modo LIBRE la hoja se divide en tres
 * zonas:
 *
 *   CABECERA  bloques con coordenadas propias (logo, emisor, franja, cliente)
 *   CUERPO    la tabla de ítems, anclada: crece hacia abajo y parte de página
 *   PIE       bloques colocados a partir de donde terminó la tabla
 *
 * La tabla no se arrastra a propósito. Una cotización de 3 líneas y otra de
 * 40 no ocupan lo mismo, así que si todo llevara coordenadas fijas las firmas
 * acabarían impresas encima de los ítems en cuanto la cotización creciera.
 *
 * Las coordenadas van en puntos desde la ESQUINA SUPERIOR IZQUIERDA, que es
 * como se piensa una hoja; el PDF cuenta desde abajo y la conversión se hace
 * al pintar.
 */
class CotizacionDiseno
{
    public const ANCHO_HOJA = 595.28;   // A4 vertical, en puntos
    public const ALTO_HOJA  = 841.89;
    public const MARGEN     = 30.0;

    /** Datos que se pueden arrastrar a la hoja, agrupados como se enseñan. */
    public const DATOS = [
        'Documento' => [
            'doc.titulo'       => 'Título (COTIZACIÓN)',
            'doc.numero'       => 'Número',
            'doc.fecha'        => 'Fecha',
            'doc.valida_hasta' => 'Válida hasta',
            'doc.referencia'   => 'Referencia al requerimiento',
        ],
        'Empresa que cotiza' => [
            'empresa.razon_social' => 'Razón social',
            'empresa.ruc'          => 'RUC',
            'empresa.direccion'    => 'Dirección',
            'empresa.email'        => 'E-mail',
            'empresa.telefono'     => 'Teléfono',
        ],
        'Cliente' => [
            'cliente.nombre'    => 'Nombre / razón social',
            'cliente.ruc'       => 'RUC',
            'cliente.direccion' => 'Dirección',
            'cliente.email'     => 'E-mail',
        ],
    ];

    /** Bloques que no son un dato suelto, con la zona donde tienen sentido. */
    public const PIEZAS = [
        'texto'   => ['nombre' => 'Texto fijo',        'zonas' => ['cabecera', 'pie']],
        'logo'    => ['nombre' => 'Logo',              'zonas' => ['cabecera', 'pie']],
        'caja'    => ['nombre' => 'Recuadro de color', 'zonas' => ['cabecera', 'pie']],
        'linea'   => ['nombre' => 'Línea',             'zonas' => ['cabecera', 'pie']],
        'cliente' => ['nombre' => 'Ficha del cliente', 'zonas' => ['cabecera']],
        'totales' => ['nombre' => 'Totales',           'zonas' => ['pie']],
        'firmas'  => ['nombre' => 'Firmas (dos, izq/der)', 'zonas' => ['pie']],
        'firma1'  => ['nombre' => 'Firma (una, con imagen opcional)', 'zonas' => ['pie']],
        'parrafo' => ['nombre' => 'Texto del pie',     'zonas' => ['pie']],
    ];

    /**
     * Rótulos que imprime cada pieza y que se pueden cambiar desde el lienzo.
     *
     * Son textos fijos del documento —«CLIENTE», «SUBTOTAL»— que hasta ahora
     * estaban escritos en el código. Cada empresa los llama a su manera, y no
     * poder tocarlos era justo lo que obligaba a mantener plantillas aparte.
     * Vacío significa: no imprimir ese rótulo (o, en las firmas, usar el
     * nombre que ya está en las opciones).
     */
    public const ROTULOS = [
        'cliente' => [
            'rotulo'    => ['Título del recuadro', 'CLIENTE'],
            'empresa'   => ['Etiqueta de la empresa', 'Empresa'],
            'direccion' => ['Etiqueta de la dirección', 'Dirección'],
            'ruc'       => ['Etiqueta del RUC', 'RUC'],
            'email'     => ['Etiqueta del e-mail', 'E-mail'],
            'telefono'  => ['Etiqueta del teléfono', 'Teléfono'],
        ],
        'totales' => [
            'subtotal' => ['Subtotal', 'SUBTOTAL'],
            'igv'      => ['IGV', 'IGV (18%)'],
            'total'    => ['Total', 'TOTAL'],
        ],
        'firmas' => [
            'izq' => ['Firma izquierda', ''],
            'der' => ['Firma derecha', ''],
        ],
        'firma1' => [
            'nombre' => ['Nombre debajo de la línea', ''],
        ],
        'parrafo' => [
            'titulo' => ['Título encima del texto', ''],
        ],
    ];

    private const TIPOS = ['dato', 'texto', 'logo', 'caja', 'linea', 'cliente', 'totales', 'firmas', 'firma1', 'parrafo'];

    /** Piezas que aceptan un fondo de color opcional para "enmarcarlas". */
    private const CON_FONDO = ['dato', 'texto', 'parrafo', 'firmas', 'firma1', 'cliente'];
    private const ZONAS = ['cabecera', 'pie'];
    private const ALINS = ['izq', 'centro', 'der'];

    // ------------------------------------------------------------------

    /** Todas las claves de dato válidas, en una sola lista. */
    public static function claves(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::DATOS)));
    }

    public static function etiquetaDe(string $clave): string
    {
        foreach (self::DATOS as $grupo) {
            if (isset($grupo[$clave])) return $grupo[$clave];
        }
        return $clave;
    }

    /**
     * Deja la lista de bloques en un estado en el que se pueda pintar.
     *
     * Llega de un formulario, así que se valida todo: un tipo desconocido o
     * una coordenada fuera de la hoja no puede llegar al generador de PDF.
     */
    public static function normalizar($bloques): array
    {
        if (is_string($bloques)) {
            $bloques = json_decode($bloques, true);
        }
        if (!is_array($bloques)) {
            return [];
        }

        $claves = self::claves();
        $out = [];

        foreach ($bloques as $b) {
            if (!is_array($b)) continue;

            $tipo = (string) ($b['tipo'] ?? '');
            if (!in_array($tipo, self::TIPOS, true)) continue;

            $zona = in_array($b['zona'] ?? '', self::ZONAS, true) ? $b['zona'] : 'cabecera';
            if ($tipo !== 'dato' && !in_array($zona, self::PIEZAS[$tipo]['zonas'], true)) {
                $zona = self::PIEZAS[$tipo]['zonas'][0];
            }

            $n = [
                'tipo'    => $tipo,
                'zona'    => $zona,
                'x'       => self::acotar($b['x'] ?? 0, 0, self::ANCHO_HOJA),
                'y'       => self::acotar($b['y'] ?? 0, 0, self::ALTO_HOJA),
                'w'       => self::acotar($b['w'] ?? 200, 8, self::ANCHO_HOJA),
                'h'       => self::acotar($b['h'] ?? 20, 2, self::ALTO_HOJA),
                'tam'     => self::acotar($b['tam'] ?? 9, 5, 40),
                'negrita' => !empty($b['negrita']) ? 1 : 0,
                'alin'    => in_array($b['alin'] ?? '', self::ALINS, true) ? $b['alin'] : 'izq',
                'color'   => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($b['color'] ?? ''))
                                ? strtoupper($b['color']) : '#1F2A36',
            ];

            if ($tipo === 'dato') {
                $clave = (string) ($b['clave'] ?? '');
                if (!in_array($clave, $claves, true)) continue;
                $n['clave'] = $clave;
            } elseif ($tipo === 'parrafo') {
                $n['clave'] = ($b['clave'] ?? '') === 'notas' ? 'notas' : 'condiciones';
            } elseif ($tipo === 'texto') {
                $n['texto'] = mb_substr(trim((string) ($b['texto'] ?? '')), 0, 1000);
                if ($n['texto'] === '') continue;      // un texto vacío es un bloque invisible
            } elseif ($tipo === 'caja') {
                // El recuadro puede llevar texto adentro (opcional) y un
                // marco (borde), además del relleno de color de siempre.
                $n['contenido']  = mb_substr(trim((string) ($b['contenido'] ?? '')), 0, 500);
                $n['marco']      = !empty($b['marco']) ? 1 : 0;
                $ct = (string) ($b['colorTexto'] ?? '');
                $n['colorTexto'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $ct) ? strtoupper($ct) : '#FFFFFF';
            }

            // Fondo opcional, para "enmarcar" el bloque con un color detrás.
            // Vacío = como siempre, sin cuadro. No aplica a 'caja' (su color YA
            // es el fondo) ni a 'linea' (no tiene área que rellenar).
            if (in_array($tipo, self::CON_FONDO, true)) {
                $fondo = (string) ($b['fondo'] ?? '');
                $n['fondo'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $fondo) ? strtoupper($fondo) : null;
            }

            if ($tipo === 'firma1') {
                // Sólo se acepta una ruta que de verdad viva en storage/firmas
                // y que ya exista en disco: lo que venga del cliente aquí es
                // JSON suelto, no algo que el generador de PDF deba fiarse a ciegas.
                $ruta = (string) ($b['imagen'] ?? '');
                $n['imagen'] = self::rutaFirmaValida($ruta) ? $ruta : null;
            }

            if (isset(self::ROTULOS[$tipo])) {
                $dados = is_array($b['textos'] ?? null) ? $b['textos'] : [];
                $n['textos'] = [];
                foreach (self::ROTULOS[$tipo] as $k => [, $porDefecto]) {
                    // Las condiciones venían con su título puesto desde
                    // siempre; las notas nunca lo llevaron.
                    if ($tipo === 'parrafo' && $k === 'titulo' && $n['clave'] === 'condiciones') {
                        $porDefecto = 'TÉRMINOS Y CONDICIONES';
                    }
                    $n['textos'][$k] = mb_substr(
                        trim((string) ($dados[$k] ?? $porDefecto)), 0, 40);
                }
            }

            $out[] = $n;
        }

        return $out;
    }

    private static function acotar($v, float $min, float $max): float
    {
        return round(max($min, min($max, (float) $v)), 1);
    }

    /** ¿Esta ruta es una imagen de firma que de verdad está en disco, en su carpeta? */
    private static function rutaFirmaValida(string $ruta): bool
    {
        if ($ruta === '') return false;
        $abs  = realpath(BASE_PATH . '/' . $ruta);
        $raiz = realpath(BASE_PATH . '/storage/firmas');
        return $abs && $raiz && str_starts_with($abs, $raiz) && is_file($abs);
    }

    /**
     * Guarda la foto de una firma escaneada, lista para el PDF.
     *
     * Mismo tratamiento que el logo (JPEG sobre fondo blanco, ver
     * CotizacionConfig::guardarLogo): aquí no hay "el" logo de la empresa,
     * sino una imagen por cada bloque de firma que se suba, así que cada una
     * recibe un nombre propio en vez de pisar siempre el mismo archivo.
     */
    public static function guardarImagenFirma(array $archivo): string
    {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new RuntimeException('No se pudo recibir la imagen. Inténtelo de nuevo.');
        }
        if ($archivo['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('La imagen pasa de 2 MB.');
        }

        $info = @getimagesize($archivo['tmp_name']);
        if (!$info) {
            throw new RuntimeException('Ese archivo no es una imagen.');
        }
        [$ancho, $alto, $tipo] = $info;

        $dir = BASE_PATH . '/storage/firmas';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $nombre = 'empresa-' . Empresa::id() . '-' . bin2hex(random_bytes(6)) . '.jpg';
        $destino = $dir . '/' . $nombre;

        if (!extension_loaded('gd')) {
            if ($tipo !== IMAGETYPE_JPEG) {
                throw new RuntimeException('Este servidor no puede convertir imágenes: '
                    . 'suba la firma en formato JPG.');
            }
            copy($archivo['tmp_name'], $destino);
            return 'storage/firmas/' . $nombre;
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

        // Una firma no necesita más resolución que un logo: se acota igual.
        $max = 500;
        $escala = min(1, $max / max($ancho, $alto));
        $nAncho = max(1, (int) round($ancho * $escala));
        $nAlto  = max(1, (int) round($alto * $escala));

        $lienzo = imagecreatetruecolor($nAncho, $nAlto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $nAncho, $nAlto, $ancho, $alto);
        imagejpeg($lienzo, $destino, 88);
        imagedestroy($lienzo);
        imagedestroy($origen);

        return 'storage/firmas/' . $nombre;
    }

    /**
     * Punto de partida del lienzo: los bloques que reproducen el diseño que la
     * empresa ya tiene en modo simple.
     *
     * Nadie empieza con una hoja en blanco: se entra al lienzo viendo lo de
     * siempre y se mueve lo que estorbe.
     */
    public static function porDefecto(array $cfg): array
    {
        $m     = self::MARGEN;
        $util  = self::ANCHO_HOJA - $m * 2;
        $color = $cfg['color'] ?? '#12395B';
        $gris  = '#64748B';

        $b = [];
        $poner = function (array $extra) use (&$b) { $b[] = $extra; };

        // Franja de color con el título, el número y las fechas.
        $poner(['tipo' => 'caja', 'zona' => 'cabecera', 'x' => $m, 'y' => $m,
                 'w' => $util, 'h' => 62, 'color' => $color]);
        $poner(['tipo' => 'dato', 'zona' => 'cabecera', 'clave' => 'doc.titulo',
                 'x' => $m, 'y' => $m + 5, 'w' => $util - 8, 'tam' => 13,
                 'negrita' => 1, 'alin' => 'der', 'color' => '#FFFFFF']);
        $poner(['tipo' => 'dato', 'zona' => 'cabecera', 'clave' => 'doc.numero',
                 'x' => $m, 'y' => $m + 23, 'w' => $util - 8, 'tam' => 10,
                 'negrita' => 1, 'alin' => 'der', 'color' => '#FFFFFF']);
        $poner(['tipo' => 'dato', 'zona' => 'cabecera', 'clave' => 'doc.referencia',
                 'x' => $m, 'y' => $m + 37, 'w' => $util - 8, 'tam' => 8,
                 'alin' => 'der', 'color' => '#FFFFFF']);
        if (!empty($cfg['mostrar_fecha'])) {
            $poner(['tipo' => 'dato', 'zona' => 'cabecera', 'clave' => 'doc.fecha',
                     'x' => $m, 'y' => $m + 48, 'w' => $util - 8, 'tam' => 8,
                     'alin' => 'der', 'color' => '#FFFFFF']);
        }

        // Logo y datos del emisor, cada uno a un lado.
        $izquierda = ($cfg['logo_posicion'] ?? 'IZQUIERDA') !== 'DERECHA';
        $yLogo   = $m + 74;
        $xLogo   = $izquierda ? $m : self::ANCHO_HOJA - $m - 150;
        $xEmisor = $izquierda ? $m + 166 : $m;
        $poner(['tipo' => 'logo', 'zona' => 'cabecera',
                 'x' => $xLogo, 'y' => $yLogo, 'w' => 150, 'h' => 58]);

        $anchoEmisor = $util - 166;
        $poner(['tipo' => 'dato', 'zona' => 'cabecera', 'clave' => 'empresa.razon_social',
                 'x' => $xEmisor, 'y' => $yLogo, 'w' => $anchoEmisor, 'tam' => 10.5,
                 'negrita' => 1, 'color' => '#1F2A36']);

        $y = $yLogo + 18;
        foreach (['empresa.direccion', 'empresa.ruc', 'empresa.email'] as $clave) {
            $poner(['tipo' => 'dato', 'zona' => 'cabecera', 'clave' => $clave,
                     'x' => $xEmisor, 'y' => $y, 'w' => $anchoEmisor, 'tam' => 8, 'color' => $gris]);
            $y += 11;
        }
        if (!empty($cfg['mostrar_telefono'])) {
            $poner(['tipo' => 'dato', 'zona' => 'cabecera', 'clave' => 'empresa.telefono',
                     'x' => $xEmisor, 'y' => $y, 'w' => $anchoEmisor, 'tam' => 8, 'color' => $gris]);
        }

        // Ficha del cliente, pegada al final de la cabecera.
        $poner(['tipo' => 'cliente', 'zona' => 'cabecera', 'x' => $m, 'y' => $m + 150,
                 'w' => $util, 'color' => $color]);

        // Pie: los totales pegados a la tabla, luego los textos y las firmas.
        $poner(['tipo' => 'totales', 'zona' => 'pie', 'x' => $m + $util - 190, 'y' => 8,
                 'w' => 190, 'color' => $color]);
        $poner(['tipo' => 'parrafo', 'zona' => 'pie', 'clave' => 'notas',
                 'x' => $m, 'y' => 70, 'w' => $util, 'tam' => 7.5, 'color' => $gris]);
        $poner(['tipo' => 'parrafo', 'zona' => 'pie', 'clave' => 'condiciones',
                 'x' => $m, 'y' => 100, 'w' => $util, 'tam' => 7.5, 'color' => $gris]);
        $poner(['tipo' => 'firmas', 'zona' => 'pie', 'x' => $m, 'y' => 200,
                 'w' => $util, 'color' => $gris]);

        return self::normalizar($b);
    }

    /**
     * El texto que le toca a un bloque de dato.
     *
     * Devuelve cadena vacía cuando el dato no existe —una empresa sin teléfono,
     * una cotización sin referencia— y entonces el bloque sencillamente no se
     * pinta, en lugar de dejar un hueco con dos puntos sueltos.
     */
    public static function valor(string $clave, array $cot, array $emp, array $cfg): string
    {
        return match ($clave) {
            'doc.titulo'       => (string) ($cfg['titulo'] ?? 'COTIZACIÓN'),
            'doc.numero'       => 'N° ' . CotizacionConfig::formatoNumero($cfg, (int) $cot['numero']),
            'doc.fecha'        => 'Fecha: ' . Vista::fecha($cot['fecha']),
            'doc.valida_hasta' => !empty($cot['valida_hasta'])
                                    ? 'Válida hasta: ' . Vista::fecha($cot['valida_hasta']) : '',
            'doc.referencia'   => trim(((string) ($cfg['etiqueta_ref'] ?? ''))
                                    . ' ' . (string) ($cot['referencia'] ?? '')),

            'empresa.razon_social' => (string) $emp['razon_social'],
            'empresa.ruc'          => self::conEtiqueta($cfg, 'RUC', (string) $emp['ruc'], 'RUC '),
            'empresa.direccion'    => self::conEtiqueta($cfg, 'Dirección', (string) ($emp['direccion'] ?? '')),
            'empresa.email'        => self::conEtiqueta($cfg, 'E-mail', (string) ($emp['email'] ?? '')),
            'empresa.telefono'     => self::conEtiqueta($cfg, 'Teléfono', (string) ($emp['telefono'] ?? '')),

            'cliente.nombre'    => (string) ($cot['cliente_nombre'] ?? ''),
            'cliente.ruc'       => self::conEtiqueta($cfg, 'RUC', (string) ($cot['cliente_ruc'] ?? ''), 'RUC '),
            'cliente.direccion' => self::conEtiqueta($cfg, 'Dirección', (string) ($cot['cliente_direccion'] ?? '')),
            'cliente.email'     => self::conEtiqueta($cfg, 'E-mail', (string) ($cot['cliente_email'] ?? '')),

            default => '',
        };
    }

    /** Unas empresas ponen «RUC:» delante del dato y otras el dato solo. */
    private static function conEtiqueta(array $cfg, string $etiqueta, string $valor, string $suelto = ''): string
    {
        if ($valor === '') return '';
        return !empty($cfg['emisor_etiquetas']) ? $etiqueta . ': ' . $valor : $suelto . $valor;
    }

    /** Datos de muestra para dibujar el lienzo sin pedir nada a la base. */
    public static function ejemplo(): array
    {
        return [
            'numero'            => 25,
            'fecha'             => date('Y-m-d'),
            'valida_hasta'      => date('Y-m-d', strtotime('+15 days')),
            'referencia'        => 'EJEMPLO-2026',
            'cliente_nombre'    => 'CLIENTE DE EJEMPLO S.A.C.',
            'cliente_direccion' => 'Av. Los Constructores 1234, La Molina - Lima',
            'cliente_ruc'       => '20100000001',
            'cliente_email'     => 'compras@ejemplo.com.pe',
            'cliente_telefono'  => '01 234 5678',
        ];
    }
}
