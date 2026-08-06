<?php
/**
 * Capa de acceso a datos: PDO puro con prepared statements.
 * Sin ORM, sin query builder mágico.
 *
 * IMPORTANTE: la conexión usa prepares NATIVOS (ATTR_EMULATE_PREPARES = false).
 * MySQL no admite repetir un mismo placeholder con nombre dentro de una
 * consulta: `LIKE :q OR otro LIKE :q` falla con "Invalid parameter number".
 * Cada ocurrencia necesita su propio nombre (:q1, :q2, ...) aunque el valor
 * sea el mismo. Se mantienen los prepares nativos a propósito: el servidor
 * separa sentencia y datos, en vez de que el driver interpole por su cuenta.
 */
class DB
{
    private static ?PDO $pdo = null;
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = self::$cfg;
        $dsn = "mysql:host={$c['host']};port={$c['puerto']};dbname={$c['nombre']};charset={$c['charset']}";
        try {
            self::$pdo = new PDO($dsn, $c['usuario'], $c['clave'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            if (Config::get('app.debug')) {
                throw $e;
            }
            http_response_code(500);
            exit('No se pudo conectar a la base de datos.');
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** Todas las filas */
    public static function todos(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Primera fila o null */
    public static function uno(string $sql, array $params = []): ?array
    {
        $fila = self::query($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    /** Primera columna de la primera fila */
    public static function valor(string $sql, array $params = [])
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT a partir de un arreglo asociativo. Devuelve el id insertado. */
    public static function insertar(string $tabla, array $datos): int
    {
        $cols = array_keys($datos);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = 'INSERT INTO ' . $tabla . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
        self::query($sql, array_combine($ph, array_values($datos)));
        return (int) self::conn()->lastInsertId();
    }

    /** UPDATE por condición simple. Devuelve filas afectadas. */
    public static function actualizar(string $tabla, array $datos, string $where, array $paramsWhere = []): int
    {
        $sets = [];
        $params = [];
        foreach ($datos as $col => $val) {
            $sets[] = "$col = :set_$col";
            $params[":set_$col"] = $val;
        }
        $sql = 'UPDATE ' . $tabla . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return self::query($sql, $params + $paramsWhere)->rowCount();
    }

    public static function eliminar(string $tabla, string $where, array $params = []): int
    {
        return self::query("DELETE FROM $tabla WHERE $where", $params)->rowCount();
    }

    // --- Transacciones -------------------------------------------------
    public static function iniciarTx(): void   { self::conn()->beginTransaction(); }
    public static function confirmarTx(): void { if (self::conn()->inTransaction()) self::conn()->commit(); }
    public static function revertirTx(): void  { if (self::conn()->inTransaction()) self::conn()->rollBack(); }

    /** Ejecuta un callable dentro de una transacción. */
    public static function transaccion(callable $fn)
    {
        self::iniciarTx();
        try {
            $r = $fn();
            self::confirmarTx();
            return $r;
        } catch (Throwable $e) {
            self::revertirTx();
            throw $e;
        }
    }
}
