<?php
/**
 * Marca de "este período se está bajando".
 *
 * La descarga tarda mucho —SUNAT da entre 2 y 50 segundos por comprobante— y la
 * empuja el navegador lote a lote. Si el usuario recarga o cierra la pestaña, el
 * navegador deja de pedir lotes y el trabajo se quedaría parado a medias sin que
 * nada lo indique.
 *
 * Con esta marca la pantalla sabe, al abrirse, que ese período seguía en marcha
 * y continúa sola desde donde iba. Lo ya bajado nunca se pierde: cada
 * comprobante se guarda al terminarlo.
 */
class DescargaEnMarcha
{
    /** Sin noticias durante este rato, se considera abandonada. */
    private const MINUTOS_MUERTA = 15;

    public static function marcar(string $periodo): void
    {
        // ON DUPLICATE: si ya estaba marcada sólo se refresca la hora.
        DB::query(
            'INSERT INTO sunat_descargas_activas (empresa_id, periodo, iniciada_en, ultimo_lote)
                  VALUES (:e, :p, NOW(), NOW())
             ON DUPLICATE KEY UPDATE ultimo_lote = NOW()',
            [':e' => Empresa::id(), ':p' => $periodo]);
    }

    public static function tocar(string $periodo): void
    {
        DB::query(
            'UPDATE sunat_descargas_activas SET ultimo_lote = NOW()
              WHERE empresa_id = :e AND periodo = :p',
            [':e' => Empresa::id(), ':p' => $periodo]);
    }

    public static function quitar(string $periodo): void
    {
        DB::query(
            'DELETE FROM sunat_descargas_activas WHERE empresa_id = :e AND periodo = :p',
            [':e' => Empresa::id(), ':p' => $periodo]);
    }

    /**
     * ¿Hay que seguir bajando este período?
     *
     * Una marca vieja se descarta: si nadie ha pedido un lote en un buen rato,
     * es que el navegador se fue y no volvió, y no tiene sentido que la pantalla
     * arranque sola una descarga que el usuario ya dio por terminada.
     */
    public static function activa(string $periodo): bool
    {
        return (bool) DB::valor(
            'SELECT COUNT(*) FROM sunat_descargas_activas
              WHERE empresa_id = :e AND periodo = :p
                AND COALESCE(ultimo_lote, iniciada_en) > DATE_SUB(NOW(), INTERVAL '
                . self::MINUTOS_MUERTA . ' MINUTE)',
            [':e' => Empresa::id(), ':p' => $periodo]);
    }
}
