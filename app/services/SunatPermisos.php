<?php
/**
 * Alta automática de permisos en la aplicación de API del contribuyente.
 *
 * Portado de ContaDocs (app/SunatPermisos.php) y CORREGIDO tras ejecutarlo
 * contra una cuenta real el 2026-08-17.
 *
 * Qué se corrigió, y por qué importa con varios RUC:
 *
 *   SUNAT agrupa los recursos POR API (api-cpe, api-sire, api.sunat.gob.pe) y
 *   una misma ruta puede aparecer bajo varias. El PUT recibe una lista PLANA de
 *   rutas y REEMPLAZA todo, así que comparar "pares api+ruta" antes y después
 *   hace creer que se perdieron permisos: en la cuenta de prueba el PUT reportó
 *   8 pares perdidos.
 *
 *   No se perdió nada: SUNAT reasigna cada ruta a la API que le corresponde y
 *   descarta los registros redundantes (rutas de aduanas colgadas de api-cpe,
 *   donde esos endpoints ni existen). Las 10 rutas distintas seguían ahí.
 *
 *   Conclusión: la verificación debe hacerse por RUTA DISTINTA, no por par
 *   api+ruta. Si no, cada alta de cliente dispara una falsa alarma.
 *
 * El token que usa este módulo NO es el OAuth2 normal: es el token de SESIÓN
 * DEL PORTAL (vigencia 1 hora) que emite el menú "Credenciales de API"
 * (código 80.1.1.1.1), capturado con SunatSesion.
 */
class SunatPermisosException extends Exception
{
    public string $tipo;
    public int $codigoHttp;

    public function __construct(string $mensaje, string $tipo, int $codigoHttp = 0)
    {
        parent::__construct($mensaje);
        $this->tipo = $tipo;
        $this->codigoHttp = $codigoHttp;
    }
}

class SunatPermisos
{
    private const URL_APLICACIONES = 'https://api.sunat.gob.pe/v1/tecnologia/controlacceso/aplicaciones';
    private const CODIGO_MODULO    = '80.1.1.1.1';

    /** Recursos que necesita este sistema. */
    public const RECURSOS = [
        '/v1/contribuyente/migeigv',                 // SIRE: qué comprobantes hay
        '/v1/contribuyente/consultacpe',             // bajar XML / PDF / CDR
        '/v1/contribuyente/consultacpe/parametros',
        '/v1/contribuyente/controlcpe',              // vía alternativa del XML
    ];

    /**
     * Habilita los recursos necesarios en la aplicación del contribuyente.
     * Hace un solo login (SUNAT bloquea usuarios tras intentos en ráfaga).
     *
     * @return array{agregados:array,perdidas:array,rutas:array,client_id:string,app:string}
     */
    public static function habilitar(array $cred, array $recursos = self::RECURSOS): array
    {
        $sesion = SunatSesion::abrir([
            'ruc'        => $cred['ruc'],
            'usuarioSol' => $cred['usuario'],
            'claveSol'   => $cred['clave'],
        ], ['timeoutMs' => 45000]);

        $token = self::capturarToken($sesion);
        $antes = self::leerAplicacion($token);

        $rutasAntes = self::rutasDe($antes);
        $faltan     = array_values(array_diff($recursos, $rutasAntes));

        if (!$faltan) {
            return [
                'agregados' => [], 'perdidas' => [], 'rutas' => $rutasAntes,
                'client_id' => (string) ($antes['desClientId'] ?? ''),
                'app'       => (string) ($antes['nomApp'] ?? ''),
            ];
        }

        // El PUT reemplaza la lista completa: se manda la UNIÓN, nunca sólo lo nuevo.
        $union = array_values(array_unique(array_merge($rutasAntes, $recursos)));
        sort($union);

        self::peticion($token, 'PUT', json_encode([
            'id'              => $antes['id'],
            'expFlujoAutoriz' => $antes['expFlujoAutoriz'] ?? '100000',
            'nomApp'          => $antes['nomApp'],
            'desUrlApp'       => $antes['desUrlApp'],
            'recursos'        => array_map(fn($r) => ['desPathRecurso' => $r], $union),
        ]));

        // Verificación por ruta distinta (ver nota de la cabecera).
        $despues     = self::leerAplicacion($token);
        $rutasDespues = self::rutasDe($despues);
        $perdidas    = array_values(array_diff($rutasAntes, $rutasDespues));

        return [
            'agregados' => array_values(array_diff($rutasDespues, $rutasAntes)),
            'perdidas'  => $perdidas,
            'rutas'     => $rutasDespues,
            'client_id' => (string) ($despues['desClientId'] ?? ''),
            'app'       => (string) ($despues['nomApp'] ?? ''),
        ];
    }

    /** Lee la aplicación registrada sin modificar nada (para diagnóstico). */
    public static function consultar(array $cred): array
    {
        $sesion = SunatSesion::abrir([
            'ruc'        => $cred['ruc'],
            'usuarioSol' => $cred['usuario'],
            'claveSol'   => $cred['clave'],
        ], ['timeoutMs' => 45000]);

        $app = self::leerAplicacion(self::capturarToken($sesion));
        return [
            'app'           => (string) ($app['nomApp'] ?? ''),
            'client_id'     => (string) ($app['desClientId'] ?? ''),
            'client_secret' => (string) ($app['desClientSecret'] ?? ''),
            'rutas'         => self::rutasDe($app),
            'faltan'        => array_values(array_diff(self::RECURSOS, self::rutasDe($app))),
        ];
    }

    // ------------------------------------------------------------------

    private static function capturarToken(SunatSesion $sesion): string
    {
        $sesion->navegar(
            SunatSesion::MENU_CLASICO . '?action=execute&code=' . self::CODIGO_MODULO . '&s=ww1',
            SunatSesion::MENU_CLASICO . '?pestana=*&agrupacion=*'
        );
        $token = $sesion->tokenModulo();
        if ($token === '') {
            throw new SunatPermisosException(
                'El módulo "Credenciales de API" no devolvió token. Reintente en unos minutos.', 'sin-token');
        }
        return $token;
    }

    private static function leerAplicacion(string $tokenPortal): array
    {
        $texto = self::peticion($tokenPortal, 'GET');
        $json  = json_decode($texto, true);
        $app   = is_array($json) && array_is_list($json) ? ($json[0] ?? null) : $json;

        if (!is_array($app) || empty($app['desClientId'])) {
            throw new SunatPermisosException(
                'El contribuyente no tiene una aplicación de API registrada. Hay que crearla una vez '
                . 'en el portal SOL (menú Credenciales de API) antes de poder habilitar recursos.',
                'sin-aplicacion');
        }
        return $app;
    }

    /** Rutas DISTINTAS habilitadas, sin importar bajo qué API estén registradas. */
    public static function rutasDe(array $app): array
    {
        $out = [];
        foreach ($app['apis'] ?? [] as $api) {
            foreach ($api['recursos'] ?? [] as $r) {
                if (!empty($r['desPathRecurso'])) $out[] = $r['desPathRecurso'];
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    private static function peticion(string $tokenPortal, string $metodo, ?string $body = null): string
    {
        $headers = [
            'Authorization: Bearer ' . $tokenPortal,
            'Accept: application/json, text/plain, */*',
            // El portal llama a esta API desde ww1.sunat.gob.pe: sin Origin ni
            // Referer, SUNAT ha respondido 401 en pruebas.
            'Origin: https://ww1.sunat.gob.pe',
            'Referer: https://ww1.sunat.gob.pe/',
        ];
        if ($body !== null) $headers[] = 'Content-Type: application/json';

        $ch = curl_init(self::URL_APLICACIONES);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $texto = curl_exec($ch);
        if ($texto === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new SunatPermisosException('Fallo de red hablando con SUNAT: ' . $err, 'http');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $tipo  = ($status === 401 || $status === 403) ? 'sin-permiso' : ($status === 429 ? 'limite' : 'http');
            $pista = $tipo === 'sin-permiso'
                ? ' El token del portal dura 1 hora: probablemente venció.' : '';
            throw new SunatPermisosException(
                "SUNAT rechazó $metodo aplicaciones (HTTP $status): " . mb_substr($texto, 0, 250) . '.' . $pista,
                $tipo, $status);
        }
        return $texto;
    }
}
