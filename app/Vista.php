<?php
/**
 * Render de vistas y utilidades de presentación.
 */
class Vista
{
    /**
     * Renderiza views/{plantilla}.php dentro del layout.
     *
     * Las variables locales van con prefijo `__` a propósito: extract() usa
     * EXTR_SKIP y no sobrescribe lo que ya existe, así que un nombre corriente
     * como $datos o $titulo haría que la vista recibiera la variable local del
     * método en lugar de su propio dato.
     */
    public static function render(string $__vista, array $__datos = [], ?string $__titulo = null): void
    {
        $__archivo = BASE_PATH . '/views/' . $__vista . '.php';
        if (!is_file($__archivo)) {
            throw new RuntimeException("Vista no encontrada: $__vista");
        }

        extract($__datos, EXTR_SKIP);

        ob_start();
        require $__archivo;

        // Se definen después de la vista: son las que consume el layout.
        $contenido    = ob_get_clean();
        $tituloPagina = $__titulo ?? Config::get('app.nombre');

        require BASE_PATH . '/views/layout.php';
    }

    /** Vista sin layout (login, impresión). */
    public static function renderPlano(string $__vista, array $__datos = []): void
    {
        extract($__datos, EXTR_SKIP);
        require BASE_PATH . '/views/' . $__vista . '.php';
    }

    /** Ruta base resuelta una sola vez por petición. */
    private static ?string $base = null;

    /**
     * Prefijo de todas las URL del sistema.
     *
     * Si `app.base_url` es null se deduce comparando la carpeta del proyecto
     * con la raíz del servidor web. Así el mismo código funciona en
     * `localhost/proyectoinventariopatty/` (raíz = www) y en un dominio propio
     * como `proyectoinventariopatty.test` (raíz = la carpeta del proyecto),
     * sin tocar la configuración.
     */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $configurada = Config::get('app.base_url');
        if ($configurada !== null) {
            return self::$base = rtrim($configurada, '/');
        }

        $raiz = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $proy = realpath(BASE_PATH);

        if ($raiz === false || $proy === false) {
            return self::$base = '';        // consola: sin servidor web
        }

        $norm = fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
        $raiz = $norm($raiz);
        $proy = $norm($proy);

        // En Windows las rutas no distinguen mayúsculas.
        $prefijo = DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp(substr($proy, 0, strlen($raiz)), $raiz) === 0
            : str_starts_with($proy, $raiz);

        // El prefijo tiene que caer en un límite de carpeta: /var/www/proyecto
        // es prefijo textual de /var/www/proyectoinventariopatty sin ser su
        // padre, y aceptarlo devolvería 'inventariopatty' —sin barra inicial—,
        // es decir URLs relativas que se rompen fuera de la raíz.
        $resto = substr($proy, strlen($raiz));
        $iguales = $prefijo && ($resto === '' || $resto[0] === '/');

        return self::$base = $iguales ? $resto : '';
    }

    public static function url(string $ruta = ''): string
    {
        return self::base() . '/' . ltrim($ruta, '/');
    }

    public static function redirigir(string $ruta): never
    {
        header('Location: ' . self::url($ruta));
        exit;
    }

    /** Escape para HTML. Usar SIEMPRE al imprimir datos. */
    public static function e($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json($datos, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function num($valor, int $dec = 2): string
    {
        return number_format((float) $valor, $dec, '.', ',');
    }

    public static function fecha(?string $f, bool $conHora = false): string
    {
        if (!$f) return '';
        $ts = strtotime($f);
        return date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
    }
}

/** Atajo de escape usado en las vistas. */
function e($v): string { return Vista::e($v); }
function url(string $r = ''): string { return Vista::url($r); }
