<?php
/**
 * Registro de auditoría. RF-15.
 * Cada evento queda asociado a la empresa activa (NULL en eventos de sistema).
 */
class Auditoria
{
    public static function registrar(string $accion, string $entidad, $entidadId = null, $detalle = null): void
    {
        try {
            DB::insertar('auditoria', [
                'empresa_id' => Empresa::hayActiva() ? Empresa::id() : null,
                'usuario_id' => Auth::autenticado() ? Auth::id() : null,
                'accion'     => $accion,
                'entidad'    => $entidad,
                'entidad_id' => $entidadId !== null ? (string) $entidadId : null,
                'detalle'    => is_string($detalle) || $detalle === null
                                    ? $detalle
                                    : json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable $e) {
            // La auditoría nunca debe romper la operación principal.
            error_log('Auditoria: ' . $e->getMessage());
        }
    }

    public static function listar(array $filtros = [], int $limite = 200): array
    {
        // Se ven los eventos de la empresa activa y los de sistema del propio usuario.
        $where = ['(a.empresa_id = :__empresa OR (a.empresa_id IS NULL AND a.usuario_id = :__yo))'];
        $p = Empresa::param() + [':__yo' => Auth::id()];

        if (!empty($filtros['usuario_id'])) { $where[] = 'a.usuario_id = :u'; $p[':u'] = $filtros['usuario_id']; }
        if (!empty($filtros['desde']))      { $where[] = 'a.creado_en >= :d'; $p[':d'] = $filtros['desde'] . ' 00:00:00'; }
        if (!empty($filtros['hasta']))      { $where[] = 'a.creado_en <= :h'; $p[':h'] = $filtros['hasta'] . ' 23:59:59'; }
        if (!empty($filtros['accion']))     { $where[] = 'a.accion = :a';     $p[':a'] = $filtros['accion']; }

        return DB::todos(
            'SELECT a.*, u.usuario, u.nombres
               FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.id DESC LIMIT ' . (int) $limite, $p);
    }
}
