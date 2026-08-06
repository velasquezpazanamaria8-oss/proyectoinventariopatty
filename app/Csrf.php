<?php
class Csrf
{
    public static function token(): string
    {
        if (!Sesion::tiene('_csrf')) {
            Sesion::set('_csrf', bin2hex(random_bytes(32)));
        }
        return Sesion::get('_csrf');
    }

    public static function campo(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function valido(?string $token): bool
    {
        return is_string($token) && hash_equals(self::token(), $token);
    }

    /** Valida el POST actual o aborta. */
    public static function verificar(): void
    {
        if (!self::valido($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            Sesion::flash('error', 'La sesión del formulario expiró. Vuelva a intentarlo.');
            Vista::redirigir('index.php');
        }
    }
}
