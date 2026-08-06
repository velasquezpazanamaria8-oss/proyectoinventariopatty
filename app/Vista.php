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

    public static function url(string $ruta = ''): string
    {
        return rtrim(Config::get('app.base_url', ''), '/') . '/' . ltrim($ruta, '/');
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
