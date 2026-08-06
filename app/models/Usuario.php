<?php
/**
 * Gestión de usuarios. RF-02.
 *
 * El usuario es una entidad global (su login es único en el sistema), pero
 * sólo se listan y editan los que pertenecen a la empresa activa, salvo que
 * quien opera tenga un rol global (superadmin).
 */
class Usuario
{
    /** Usuarios con acceso a la empresa activa (o todos, si es superadmin). */
    public static function listar(string $q = ''): array
    {
        $where = [];
        $p = [];

        if (Auth::esSuperAdmin()) {
            $join = 'LEFT JOIN usuario_empresa ue ON ue.usuario_id = u.id AND ue.empresa_id = :__empresa';
            $p   += Empresa::param();
        } else {
            $join    = 'JOIN usuario_empresa ue ON ue.usuario_id = u.id';
            $where[] = 'ue.empresa_id = :__empresa';
            $p      += Empresa::param();
        }

        if ($q !== '') {
            $where[] = '(u.usuario LIKE :q1 OR u.nombres LIKE :q2 OR u.email LIKE :q3)';
            $p[':q1'] = $p[':q2'] = $p[':q3'] = "%$q%";
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return DB::todos(
            'SELECT u.id, u.usuario, u.nombres, u.email, u.estado, u.ultimo_acceso,
                    ue.rol_id, r.nombre AS rol,
                    (SELECT COUNT(*) FROM usuario_empresa x WHERE x.usuario_id = u.id) AS empresas
               FROM usuarios u
               ' . $join . '
               LEFT JOIN roles r ON r.id = ue.rol_id
               ' . $sqlWhere . '
              ORDER BY u.nombres', $p);
    }

    /** Sólo devuelve el usuario si tiene acceso a la empresa activa. */
    public static function buscar(int $id): ?array
    {
        $u = DB::uno('SELECT * FROM usuarios WHERE id = :id', [':id' => $id]);
        if (!$u) return null;

        if (!Auth::esSuperAdmin()) {
            $enEmpresa = (int) DB::valor(
                'SELECT COUNT(*) FROM usuario_empresa WHERE usuario_id = :u AND empresa_id = :e',
                [':u' => $id, ':e' => Empresa::id()]);
            if (!$enEmpresa) return null;
        }

        $u['rol_id']   = DB::valor(
            'SELECT rol_id FROM usuario_empresa WHERE usuario_id = :u AND empresa_id = :e',
            [':u' => $id, ':e' => Empresa::id()]);
        $u['empresas'] = DB::todos(
            'SELECT ue.empresa_id, ue.rol_id, e.nombre_corto, r.nombre AS rol
               FROM usuario_empresa ue
               JOIN empresas e ON e.id = ue.empresa_id
               JOIN roles    r ON r.id = ue.rol_id
              WHERE ue.usuario_id = :u ORDER BY e.razon_social', [':u' => $id]);
        return $u;
    }

    /**
     * Crea o actualiza un usuario y su vínculo con la EMPRESA ACTIVA.
     * No toca los vínculos del usuario con otras empresas.
     */
    public static function guardar(array $d, ?int $id = null): int
    {
        $empresaId = Empresa::id();
        $rolId     = (int) $d['rol_id'];

        // Un rol global sólo puede ser asignado por quien ya lo tiene.
        $esGlobal = (int) DB::valor('SELECT global FROM roles WHERE id = :r', [':r' => $rolId]) === 1;
        if ($esGlobal && !Auth::esSuperAdmin()) {
            throw new RuntimeException('No tiene permisos para asignar un rol de alcance global.');
        }

        $datos = [
            'usuario' => trim($d['usuario']),
            'nombres' => trim($d['nombres']),
            'email'   => trim($d['email'] ?? '') ?: null,
            'estado'  => isset($d['estado']) ? (int) $d['estado'] : 1,
        ];
        $clave = (string) ($d['clave'] ?? '');

        return DB::transaccion(function () use ($datos, $clave, $id, $empresaId, $rolId) {
            if ($id === null) {
                if (strlen($clave) < 6) {
                    throw new InvalidArgumentException('La contraseña debe tener al menos 6 caracteres.');
                }
                $datos['password_hash'] = password_hash($clave, PASSWORD_BCRYPT);
                $nuevo = DB::insertar('usuarios', $datos);
                DB::insertar('usuario_empresa', [
                    'usuario_id' => $nuevo, 'empresa_id' => $empresaId,
                    'rol_id' => $rolId, 'por_defecto' => 1,
                ]);
                Auditoria::registrar('CREAR', 'usuarios', $nuevo, ['usuario' => $datos['usuario']]);
                return $nuevo;
            }

            if (!self::buscar($id)) {
                throw new RuntimeException('El usuario no pertenece a la empresa activa.');
            }
            if ($clave !== '') {
                if (strlen($clave) < 6) {
                    throw new InvalidArgumentException('La contraseña debe tener al menos 6 caracteres.');
                }
                $datos['password_hash']     = password_hash($clave, PASSWORD_BCRYPT);
                $datos['intentos_fallidos'] = 0;
                $datos['bloqueado_hasta']   = null;
            }
            DB::actualizar('usuarios', $datos, 'id = :id', [':id' => $id]);

            // El rol es por empresa: se actualiza o se crea el vínculo.
            $existe = (int) DB::valor(
                'SELECT COUNT(*) FROM usuario_empresa WHERE usuario_id = :u AND empresa_id = :e',
                [':u' => $id, ':e' => $empresaId]);
            if ($existe) {
                DB::actualizar('usuario_empresa', ['rol_id' => $rolId],
                    'usuario_id = :u AND empresa_id = :e', [':u' => $id, ':e' => $empresaId]);
            } else {
                DB::insertar('usuario_empresa', [
                    'usuario_id' => $id, 'empresa_id' => $empresaId, 'rol_id' => $rolId, 'por_defecto' => 0,
                ]);
            }

            Auditoria::registrar('EDITAR', 'usuarios', $id, ['usuario' => $datos['usuario']]);
            return $id;
        });
    }

    /** Da acceso a un usuario existente a la empresa activa. */
    public static function vincularAEmpresa(int $usuarioId, int $rolId): void
    {
        $existe = (int) DB::valor(
            'SELECT COUNT(*) FROM usuario_empresa WHERE usuario_id = :u AND empresa_id = :e',
            [':u' => $usuarioId, ':e' => Empresa::id()]);
        if ($existe) {
            throw new RuntimeException('El usuario ya tiene acceso a esta empresa.');
        }
        DB::insertar('usuario_empresa', [
            'usuario_id' => $usuarioId, 'empresa_id' => Empresa::id(),
            'rol_id' => $rolId, 'por_defecto' => 0,
        ]);
        Auditoria::registrar('VINCULAR', 'usuario_empresa', $usuarioId, ['empresa' => Empresa::id()]);
    }

    /**
     * Quita el acceso del usuario a la empresa activa. Si era su única
     * empresa, además se desactiva o elimina la cuenta.
     */
    public static function eliminar(int $id): string
    {
        if ($id === Auth::id()) {
            throw new RuntimeException('No puede eliminar su propio usuario.');
        }
        if (!self::buscar($id)) {
            throw new RuntimeException('El usuario no pertenece a la empresa activa.');
        }

        return DB::transaccion(function () use ($id) {
            DB::eliminar('usuario_empresa', 'usuario_id = :u AND empresa_id = :e',
                [':u' => $id, ':e' => Empresa::id()]);

            $otras = (int) DB::valor('SELECT COUNT(*) FROM usuario_empresa WHERE usuario_id = :u', [':u' => $id]);
            if ($otras > 0) {
                Auditoria::registrar('DESVINCULAR', 'usuarios', $id, ['empresa' => Empresa::id()]);
                return 'Se retiró el acceso del usuario a esta empresa. Mantiene acceso a otras.';
            }

            $tieneMovs = (int) DB::valor('SELECT COUNT(*) FROM kardex WHERE usuario_id = :u', [':u' => $id]) > 0;
            if ($tieneMovs) {
                DB::actualizar('usuarios', ['estado' => 0], 'id = :id', [':id' => $id]);
                Auditoria::registrar('DESACTIVAR', 'usuarios', $id);
                return 'El usuario tiene movimientos registrados: se desactivó en lugar de eliminarse.';
            }
            DB::eliminar('usuarios', 'id = :id', [':id' => $id]);
            Auditoria::registrar('ELIMINAR', 'usuarios', $id);
            return 'Usuario eliminado correctamente.';
        });
    }

    /** Roles asignables: los globales sólo aparecen para un superadmin. */
    public static function roles(): array
    {
        $where = Auth::esSuperAdmin() ? '1=1' : 'global = 0';
        $filas = DB::todos("SELECT id, nombre FROM roles WHERE $where ORDER BY id");
        return array_column($filas, 'nombre', 'id');
    }
}
