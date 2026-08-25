<?php
/**
 * Contexto multiempresa (multi-tenant).
 *
 * TODA consulta de negocio debe filtrar por Empresa::id(). Para no repetir
 * el filtro a mano en cada consulta —y que un olvido filtre datos entre
 * empresas— los modelos usan Empresa::filtro() y Empresa::sello().
 */
class Empresa
{
    /** Empresa activa en la sesión. */
    public static function id(): int
    {
        $id = Sesion::get('empresa_id');
        if (!$id) {
            // Sin empresa activa no puede ejecutarse ninguna consulta de negocio.
            throw new RuntimeException('No hay una empresa activa en la sesión.');
        }
        return (int) $id;
    }

    public static function hayActiva(): bool
    {
        return (bool) Sesion::get('empresa_id');
    }

    public static function actual(): ?array
    {
        return Sesion::get('empresa');
    }

    /**
     * Ficha completa de la empresa activa, leída de la base.
     *
     * En la sesión sólo va lo que se necesita en cada pantalla —nombre, RUC,
     * método de valorización—, y quien imprime un documento con membrete
     * necesita también la dirección, el correo y el teléfono. Se lee una vez
     * por petición.
     */
    private static ?array $ficha = null;

    public static function ficha(): array
    {
        if (self::$ficha === null) {
            self::$ficha = DB::uno('SELECT * FROM empresas WHERE id = :id',
                [':id' => self::id()]) ?: (self::actual() ?? []);
        }
        return self::$ficha;
    }

    public static function nombre(): string
    {
        return self::actual()['nombre_corto'] ?? '';
    }

    public static function simbolo(): string
    {
        return self::actual()['simbolo'] ?? 'S/';
    }

    /**
     * Fragmento SQL de filtro. Usar SIEMPRE en el WHERE de las consultas:
     *   'WHERE ' . Empresa::filtro('pr') . ' AND ...'
     * y añadir Empresa::param() a los parámetros.
     */
    public static function filtro(string $alias = ''): string
    {
        $pre = $alias === '' ? '' : $alias . '.';
        return $pre . 'empresa_id = :__empresa';
    }

    /** Parámetro que acompaña a filtro(). */
    public static function param(): array
    {
        return [':__empresa' => self::id()];
    }

    /** Añade empresa_id a un arreglo de datos antes de un INSERT. */
    public static function sello(array $datos): array
    {
        return ['empresa_id' => self::id()] + $datos;
    }

    // --- Gestión ---------------------------------------------------------

    /** Empresas a las que el usuario tiene acceso. */
    public static function delUsuario(int $usuarioId): array
    {
        if (self::esGlobal($usuarioId)) {
            // Un rol global opera en todas las empresas activas con ese mismo rol.
            $rol = DB::uno(
                'SELECT r.id, r.nombre FROM usuario_empresa ue
                   JOIN roles r ON r.id = ue.rol_id
                  WHERE ue.usuario_id = :u AND r.global = 1 LIMIT 1', [':u' => $usuarioId]);

            // Su propia empresa va primero: es la que se activa al iniciar
            // sesión, y de otro modo dependería del orden alfabético.
            $propia = (int) DB::valor(
                'SELECT empresa_id FROM usuario_empresa
                  WHERE usuario_id = :u ORDER BY por_defecto DESC, empresa_id LIMIT 1',
                [':u' => $usuarioId]);

            $empresas = DB::todos(
                'SELECT * FROM empresas WHERE estado = 1
                  ORDER BY (id = :propia) DESC, razon_social', [':propia' => $propia]);

            foreach ($empresas as &$e) {
                $e['rol_id'] = (int) $rol['id'];
                $e['rol']    = $rol['nombre'];
            }
            return $empresas;
        }
        return DB::todos(
            'SELECT e.*, ue.rol_id, r.nombre AS rol, ue.por_defecto
               FROM usuario_empresa ue
               JOIN empresas e ON e.id = ue.empresa_id
               JOIN roles    r ON r.id = ue.rol_id
              WHERE ue.usuario_id = :u AND e.estado = 1
              ORDER BY ue.por_defecto DESC, e.razon_social', [':u' => $usuarioId]);
    }

    /** ¿El usuario tiene algún rol global (superadmin)? */
    public static function esGlobal(int $usuarioId): bool
    {
        return (int) DB::valor(
            'SELECT COUNT(*) FROM usuario_empresa ue
               JOIN roles r ON r.id = ue.rol_id
              WHERE ue.usuario_id = :u AND r.global = 1', [':u' => $usuarioId]) > 0;
    }

    /**
     * Cambia la empresa activa, revalidando el acceso del usuario.
     * Nunca confía en el id que llega del navegador.
     */
    public static function activar(int $empresaId, int $usuarioId): bool
    {
        $empresas = self::delUsuario($usuarioId);
        foreach ($empresas as $e) {
            if ((int) $e['id'] === $empresaId) {
                Sesion::set('empresa_id', (int) $e['id']);
                Sesion::set('empresa', [
                    'id'           => (int) $e['id'],
                    'razon_social' => $e['razon_social'],
                    'nombre_corto' => $e['nombre_corto'],
                    'ruc'          => $e['ruc'],
                    'simbolo'      => $e['simbolo'],
                    // El motor de valorización lee estos dos de la sesión.
                    'metodo_valorizacion' => $e['metodo_valorizacion'] ?? Valorizacion::PROMEDIO,
                    'ambito_costo'        => $e['ambito_costo'] ?? Valorizacion::AMBITO_GLOBAL,
                ]);
                // El rol —y por tanto los permisos— puede diferir por empresa.
                Sesion::set('rol_id', (int) $e['rol_id']);
                Sesion::set('rol', $e['rol']);
                Sesion::set('permisos', Auth::permisosDeRol((int) $e['rol_id']));
                return true;
            }
        }
        return false;
    }

    public static function listar(string $q = ''): array
    {
        $where = $q === '' ? '1=1' : '(razon_social LIKE :q1 OR ruc LIKE :q2 OR nombre_corto LIKE :q3)';
        $p     = $q === '' ? [] : [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"];
        return DB::todos(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM usuario_empresa ue WHERE ue.empresa_id = e.id) AS usuarios,
                    (SELECT COUNT(*) FROM productos p WHERE p.empresa_id = e.id) AS productos
               FROM empresas e WHERE ' . $where . ' ORDER BY e.razon_social', $p);
    }

    public static function buscar(int $id): ?array
    {
        return DB::uno('SELECT * FROM empresas WHERE id = :id', [':id' => $id]);
    }

    public static function guardar(array $d, ?int $id = null): int
    {
        $datos = [
            'ruc'          => trim($d['ruc']),
            'razon_social' => trim($d['razon_social']),
            'nombre_corto' => trim($d['nombre_corto']) ?: trim($d['razon_social']),
            'direccion'    => trim($d['direccion'] ?? '') ?: null,
            'telefono'     => trim($d['telefono'] ?? '') ?: null,
            'email'        => trim($d['email'] ?? '') ?: null,
            'moneda'       => trim($d['moneda'] ?? 'PEN'),
            'simbolo'      => trim($d['simbolo'] ?? 'S/'),
            'estado'       => isset($d['estado']) ? (int) $d['estado'] : 1,
        ];

        // El método de valorización sólo se acepta si la empresa aún no tiene
        // movimientos: cambiarlo después mezclaría criterios en el kardex.
        if ($id === null || Valorizacion::puedeCambiarMetodo($id)) {
            $metodo = $d['metodo_valorizacion'] ?? Valorizacion::PROMEDIO;
            $datos['metodo_valorizacion'] = isset(Valorizacion::METODOS[$metodo])
                ? $metodo : Valorizacion::PROMEDIO;
            $datos['ambito_costo'] = ($d['ambito_costo'] ?? '') === Valorizacion::AMBITO_ALMACEN
                ? Valorizacion::AMBITO_ALMACEN : Valorizacion::AMBITO_GLOBAL;
        }

        if ($id !== null) {
            DB::actualizar('empresas', $datos, 'id = :id', [':id' => $id]);
            Auditoria::registrar('EDITAR', 'empresas', $id, $datos);
            return $id;
        }

        // Empresa nueva: se crea con sus catálogos mínimos para que sea operable.
        return DB::transaccion(function () use ($datos) {
            $nuevaId = DB::insertar('empresas', $datos);

            foreach ([['UND','Unidad',0],['CJA','Caja',0],['KG','Kilogramo',3],['LT','Litro',3]] as $u) {
                DB::insertar('unidades', ['empresa_id' => $nuevaId, 'codigo' => $u[0], 'nombre' => $u[1], 'decimales' => $u[2]]);
            }
            DB::insertar('almacenes',  ['empresa_id' => $nuevaId, 'codigo' => 'ALM-01', 'nombre' => 'Almacén Principal']);
            DB::insertar('categorias', ['empresa_id' => $nuevaId, 'nombre' => 'General', 'descripcion' => 'Categoría por defecto']);
            DB::insertar('marcas',     ['empresa_id' => $nuevaId, 'nombre' => 'SIN MARCA']);

            Auditoria::registrar('CREAR', 'empresas', $nuevaId, $datos);
            return $nuevaId;
        });
    }

    /** Las empresas no se eliminan: se desactivan (conservan su kardex). */
    public static function desactivar(int $id): void
    {
        if ($id === self::id()) {
            throw new RuntimeException('No puede desactivar la empresa en la que está trabajando.');
        }
        DB::actualizar('empresas', ['estado' => 0], 'id = :id', [':id' => $id]);
        Auditoria::registrar('DESACTIVAR', 'empresas', $id);
    }

    public static function activarDeNuevo(int $id): void
    {
        DB::actualizar('empresas', ['estado' => 1], 'id = :id', [':id' => $id]);
        Auditoria::registrar('REACTIVAR', 'empresas', $id);
    }

    /**
     * Qué se destruiría al eliminar la empresa.
     *
     * Se enseña antes de confirmar: nadie debería borrar a ciegas, y leer
     * "1.284 movimientos de kardex" hace pensar dos veces mejor que cualquier
     * advertencia genérica.
     */
    public static function contenido(int $id): array
    {
        $contar = fn(string $sql): int => (int) DB::valor($sql, [':e' => $id]);

        return [
            'productos'    => $contar('SELECT COUNT(*) FROM productos WHERE empresa_id = :e'),
            'movimientos'  => $contar('SELECT COUNT(*) FROM kardex WHERE empresa_id = :e'),
            'entradas'     => $contar('SELECT COUNT(*) FROM entradas WHERE empresa_id = :e'),
            'salidas'      => $contar('SELECT COUNT(*) FROM salidas WHERE empresa_id = :e'),
            'comprobantes' => $contar('SELECT COUNT(*) FROM sunat_comprobantes WHERE empresa_id = :e'),
            'usuarios'     => $contar('SELECT COUNT(*) FROM usuario_empresa WHERE empresa_id = :e'),
            'archivos'     => self::archivosDe($id),
        ];
    }

    /** Comprobantes descargados en disco (storage/cpe/{empresa}/...). */
    private static function archivosDe(int $id): int
    {
        $dir = BASE_PATH . '/storage/cpe/' . $id;
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                     $dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) $n++;
        }
        return $n;
    }

    /**
     * Elimina la empresa y TODO lo suyo. No tiene vuelta atrás.
     *
     * Existe para deshacerse de empresas de prueba o creadas por error. Para una
     * empresa que operó de verdad lo correcto es desactivarla: su kardex
     * respalda declaraciones ya presentadas, y borrarlo deja a la contabilidad
     * sin sustento.
     *
     * Se pide teclear el RUC porque un botón —por mucho "¿está seguro?" que
     * muestre— se pulsa por inercia; escribir el RUC obliga a mirar cuál es.
     *
     * El orden de borrado importa: las tablas del inventario están en NO ACTION,
     * así que hay que vaciar los hijos antes que los padres o la base lo rechaza.
     */
    public static function eliminar(int $id, string $rucTecleado): void
    {
        $empresa = self::buscar($id);
        if (!$empresa) {
            throw new RuntimeException('Esa empresa no existe.');
        }
        if (self::hayActiva() && $id === self::id()) {
            throw new RuntimeException('No puede eliminar la empresa en la que está trabajando. '
                . 'Cambie antes a otra desde el selector de arriba.');
        }
        if ((int) DB::valor('SELECT COUNT(*) FROM empresas') <= 1) {
            throw new RuntimeException('Es la única empresa del sistema: si la elimina no quedaría dónde trabajar.');
        }
        if (trim($rucTecleado) !== $empresa['ruc']) {
            throw new RuntimeException('El RUC tecleado no coincide con el de la empresa. No se eliminó nada.');
        }

        // El detalle se guarda ANTES: después no habrá de dónde sacarlo. La
        // auditoría no tiene clave foránea a empresas precisamente para que el
        // rastro sobreviva al borrado.
        Auditoria::registrar('ELIMINAR', 'empresas', $id, [
            'ruc'          => $empresa['ruc'],
            'razon_social' => $empresa['razon_social'],
            'contenido'    => self::contenido($id),
        ]);

        DB::transaccion(function () use ($id) {
            $p = [':e' => $id];

            // Hijos que no llevan empresa_id: se alcanzan a través de su padre.
            DB::query('DELETE kc FROM kardex_capa kc
                         JOIN kardex k ON k.id = kc.kardex_id WHERE k.empresa_id = :e', $p);
            DB::query('DELETE d FROM entrada_detalle d
                         JOIN entradas e ON e.id = d.entrada_id WHERE e.empresa_id = :e', $p);
            DB::query('DELETE d FROM salida_detalle d
                         JOIN salidas s ON s.id = d.salida_id WHERE s.empresa_id = :e', $p);
            DB::query('DELETE d FROM inventario_detalle d
                         JOIN inventarios i ON i.id = d.inventario_id WHERE i.empresa_id = :e', $p);
            DB::query('DELETE s FROM stock s
                         JOIN productos pr ON pr.id = s.producto_id WHERE pr.empresa_id = :e', $p);

            // Lo de SUNAT antes que los productos: sus líneas apuntan a ellos.
            foreach (['sunat_cpe_items', 'sunat_comprobantes', 'sunat_stock_inicial',
                      'sunat_producto_mapa', 'sunat_descargas_activas', 'sunat_tareas',
                      'credenciales_sunat'] as $t) {
                DB::query("DELETE FROM $t WHERE empresa_id = :e", $p);
            }

            // Movimientos, luego el catálogo, luego aquello de lo que depende.
            foreach (['kardex', 'capas_costo', 'ajustes', 'entradas', 'salidas', 'inventarios',
                      'productos', 'categorias', 'marcas', 'unidades', 'proveedores',
                      'almacenes', 'usuario_empresa'] as $t) {
                DB::query("DELETE FROM $t WHERE empresa_id = :e", $p);
            }

            DB::query('DELETE FROM empresas WHERE id = :e', $p);
        });

        // Los archivos se borran después de confirmar la transacción: si algo
        // hubiera fallado en la base, haberlos borrado antes dejaría sin
        // respaldo a comprobantes que seguirían registrados.
        self::borrarArchivos($id);
    }

    /** Borra storage/cpe/{empresa} con todo lo que tenga dentro. */
    private static function borrarArchivos(int $id): void
    {
        $raiz = realpath(BASE_PATH . '/storage/cpe');
        $dir  = realpath(BASE_PATH . '/storage/cpe/' . $id);

        // Comprobación de cordura: sólo se borra dentro de storage/cpe.
        if (!$raiz || !$dir || !str_starts_with($dir, $raiz) || $dir === $raiz) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
