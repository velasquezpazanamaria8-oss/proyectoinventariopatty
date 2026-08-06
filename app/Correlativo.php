<?php
/**
 * Correlativos internos por tipo de documento: E-000001, S-000001.
 * Se reinician por empresa y se calculan dentro de la transacción del movimiento.
 */
class Correlativo
{
    private const TABLAS = ['E' => 'entradas', 'S' => 'salidas'];

    public static function siguiente(string $prefijo): string
    {
        $tabla = self::TABLAS[$prefijo] ?? null;
        if ($tabla === null) {
            throw new InvalidArgumentException("Prefijo de correlativo desconocido: $prefijo");
        }
        $ultimo = (int) DB::valor(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(serie_numero, 3) AS UNSIGNED)), 0)
               FROM $tabla WHERE empresa_id = :e AND serie_numero LIKE :p FOR UPDATE",
            [':e' => Empresa::id(), ':p' => $prefijo . '-%']
        );
        return $prefijo . '-' . str_pad((string) ($ultimo + 1), 6, '0', STR_PAD_LEFT);
    }
}
