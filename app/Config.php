<?php
class Config
{
    private static array $datos = [];

    public static function cargar(array $datos): void
    {
        self::$datos = $datos;
    }

    /** Acceso por ruta con punto: Config::get('app.nombre') */
    public static function get(string $ruta, $porDefecto = null)
    {
        $valor = self::$datos;
        foreach (explode('.', $ruta) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return $porDefecto;
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }
}
