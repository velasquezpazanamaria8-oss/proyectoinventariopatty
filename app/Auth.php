<?php
/**
 * Autenticación y control de acceso. RF-01, RF-02.
 *
 * En modo multiempresa el rol y los permisos NO están en el usuario:
 * dependen de la empresa activa (tabla `usuario_empresa`). Por eso
 * cambiar de empresa recarga los permisos (ver Empresa::activar).
 */
class Auth
{
    public static function intentarLogin(string $usuario, string $clave): array
    {
        $u = DB::uno('SELECT * FROM usuarios WHERE usuario = :usuario', [':usuario' => $usuario]);

        if (!$u) {
            return [false, 'Usuario o contraseña incorrectos.'];
        }
        if ((int) $u['estado'] !== 1) {
            return [false, 'El usuario se encuentra inactivo.'];
        }
        if ($u['bloqueado_hasta'] !== null && strtotime($u['bloqueado_hasta']) > time()) {
            return [false, 'Cuenta bloqueada temporalmente. Intente más tarde.'];
        }
        if (!password_verify($clave, $u['password_hash'])) {
            self::registrarFallo($u);
            return [false, 'Usuario o contraseña incorrectos.'];
        }

        $empresas = Empresa::delUsuario((int) $u['id']);
        if (!$empresas) {
            return [false, 'El usuario no tiene ninguna empresa asignada. Contacte al administrador.'];
        }

        if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT)) {
            DB::actualizar('usuarios', ['password_hash' => password_hash($clave, PASSWORD_BCRYPT)],
                'id = :id', [':id' => $u['id']]);
        }

        DB::actualizar('usuarios', [
            'ultimo_acceso'     => date('Y-m-d H:i:s'),
            'intentos_fallidos' => 0,
            'bloqueado_hasta'   => null,
        ], 'id = :id', [':id' => $u['id']]);

        Sesion::regenerar();
        Sesion::set('usuario', [
            'id'      => (int) $u['id'],
            'usuario' => $u['usuario'],
            'nombres' => $u['nombres'],
        ]);
        Sesion::set('multiempresa', count($empresas) > 1);

        // Empresa activa: la marcada por defecto o la primera disponible.
        Empresa::activar((int) $empresas[0]['id'], (int) $u['id']);

        Auditoria::registrar('LOGIN', 'usuarios', $u['id'], 'Inicio de sesión correcto');

        return [true, 'Bienvenido, ' . $u['nombres']];
    }

    private static function registrarFallo(array $u): void
    {
        $max    = (int) Config::get('sesion.max_intentos', 5);
        $intent = (int) $u['intentos_fallidos'] + 1;
        $datos  = ['intentos_fallidos' => $intent];
        if ($intent >= $max) {
            $min = (int) Config::get('sesion.bloqueo_minutos', 15);
            $datos['bloqueado_hasta']   = date('Y-m-d H:i:s', time() + $min * 60);
            $datos['intentos_fallidos'] = 0;
        }
        DB::actualizar('usuarios', $datos, 'id = :id', [':id' => $u['id']]);
    }

    public static function permisosDeRol(int $rolId): array
    {
        $filas = DB::todos(
            'SELECT p.clave FROM rol_permiso rp
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE rp.rol_id = :rol', [':rol' => $rolId]);
        return array_column($filas, 'clave');
    }

    public static function logout(): void
    {
        if (self::autenticado()) {
            Auditoria::registrar('LOGOUT', 'usuarios', self::id(), 'Cierre de sesión');
        }
        Sesion::destruir();
    }

    public static function autenticado(): bool { return Sesion::tiene('usuario'); }
    public static function usuario(): ?array   { return Sesion::get('usuario'); }
    public static function id(): ?int          { return Sesion::get('usuario')['id'] ?? null; }
    public static function rol(): ?string      { return Sesion::get('rol'); }
    public static function rolId(): ?int       { return Sesion::get('rol_id'); }

    /** ¿El rol activo ve todas las empresas? */
    public static function esSuperAdmin(): bool
    {
        return self::puede('empresas.gestionar');
    }

    public static function puede(string $permiso): bool
    {
        return in_array($permiso, Sesion::get('permisos', []), true);
    }

    public static function requiereLogin(): void
    {
        if (!self::autenticado()) {
            Sesion::flash('warning', 'Debe iniciar sesión para continuar.');
            Vista::redirigir('login.php');
        }
        if (!Empresa::hayActiva()) {
            Sesion::flash('error', 'Su sesión no tiene una empresa activa. Vuelva a ingresar.');
            Sesion::destruir();
            Vista::redirigir('login.php');
        }
    }

    public static function requierePermiso(string $permiso): void
    {
        self::requiereLogin();
        if (!self::puede($permiso)) {
            http_response_code(403);
            Sesion::flash('error', 'No tiene permisos para acceder a esa sección.');
            Vista::redirigir('index.php');
        }
    }
}
