<?php
class Sesion
{
    public static function iniciar(array $cfg): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($cfg['nombre']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();

        $limite = $cfg['vida_minutos'] * 60;
        if (isset($_SESSION['_ultimo']) && (time() - $_SESSION['_ultimo']) > $limite) {
            self::destruir();
            session_start();
            $_SESSION['_expirada'] = true;
        }
        $_SESSION['_ultimo'] = time();
    }

    public static function set(string $k, $v): void { $_SESSION[$k] = $v; }
    public static function get(string $k, $def = null) { return $_SESSION[$k] ?? $def; }
    public static function tiene(string $k): bool { return isset($_SESSION[$k]); }
    public static function quitar(string $k): void { unset($_SESSION[$k]); }

    public static function regenerar(): void
    {
        session_regenerate_id(true);
    }

    public static function destruir(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // --- Mensajes flash -------------------------------------------------
    public static function flash(string $tipo, string $mensaje): void
    {
        $_SESSION['_flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
    }

    public static function flashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}
