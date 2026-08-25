<?php
/**
 * Cliente de las APIs OAuth2 de SUNAT.
 *
 * Detalles confirmados contra SUNAT real (2026-08-17, RUC 20613129562) y
 * documentados en la guía de ContaDocs. No son "la mejor forma": son la única
 * que responde 200.
 *
 *  · `username` = RUC + usuario SOL CONCATENADOS, sin separador.
 *  · Un token por SCOPE: el del SIRE no sirve para api-cpe y viceversa.
 *  · En los GET del SIRE se manda SOLO `Authorization`. Agregar `Accept` o
 *    `Content-Type` a un GET sin cuerpo dispara el WAF y responde 401.
 *  · api-cpe, en cambio, SÍ quiere `User-Agent` de navegador.
 *  · SUNAT devuelve ~8% de 500 transitorios (visto en vivo): hay que
 *    reintentar con espera creciente, no darlo por definitivo.
 */
class SunatApi
{
    public const SCOPE_SIRE = 'https://api-sire.sunat.gob.pe';
    public const SCOPE_CPE  = 'https://api-cpe.sunat.gob.pe';

    private const URL_TOKEN = 'https://api-seguridad.sunat.gob.pe/v1/clientessol/%s/oauth2/token/';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                     . '(KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36';

    /** Caché de tokens por (client_id|ruc|usuario|scope) durante la petición. */
    private static array $tokens = [];

    // ------------------------------------------------------------------
    // Token
    // ------------------------------------------------------------------

    public static function token(array $cred, string $scope): string
    {
        $cid    = trim((string) ($cred['client_id'] ?? ''));
        $secret = (string) ($cred['client_secret'] ?? '');
        $ruc    = preg_replace('/\D/', '', (string) ($cred['ruc'] ?? ''));
        $user   = trim((string) ($cred['usuario'] ?? ''));
        $pass   = (string) ($cred['clave'] ?? '');

        if ($cid === '' || $secret === '') {
            throw new RuntimeException('Faltan el ID y la clave de API (client_id / client_secret).');
        }
        if ($ruc === '' || $user === '' || $pass === '') {
            throw new RuntimeException('Faltan el RUC, el usuario SOL o la Clave SOL.');
        }

        $ck = "$cid|$ruc$user|$scope";
        if (isset(self::$tokens[$ck])) {
            return self::$tokens[$ck];
        }

        $post = http_build_query([
            'grant_type'    => 'password',
            'scope'         => $scope,
            'client_id'     => $cid,
            'client_secret' => $secret,
            'username'      => $ruc . $user,   // pegados, sin separador
            'password'      => $pass,
        ]);

        [$code, $body] = self::http('POST', sprintf(self::URL_TOKEN, rawurlencode($cid)), $post,
            ['Content-Type: application/x-www-form-urlencoded']);

        $j = json_decode($body, true);
        if ($code === 200 && !empty($j['access_token'])) {
            return self::$tokens[$ck] = $j['access_token'];
        }

        if ($code === 0) {
            throw new RuntimeException('No se pudo contactar con SUNAT para obtener el token. ' . $body);
        }

        $msg = is_array($j) ? ($j['error_description'] ?? $j['error'] ?? $j['message'] ?? '') : '';
        if ($code === 401 || stripos((string) $msg, 'credential') !== false) {
            throw new RuntimeException('SUNAT rechazó las credenciales. Revise el ID/clave de API, '
                . 'el usuario y la Clave SOL. ' . mb_substr((string) $msg, 0, 150));
        }
        throw new RuntimeException("SUNAT no entregó el token (HTTP $code). " . mb_substr((string) $msg, 0, 150));
    }

    /**
     * Recursos incluidos en un token.
     * El claim `aud` viene como STRING con JSON dentro: hay que decodificar dos veces.
     */
    public static function recursosDelToken(string $jwt): array
    {
        $partes = explode('.', $jwt);
        if (count($partes) < 2) return [];

        $payload = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);
        $aud = $payload['aud'] ?? [];
        if (is_string($aud)) {
            $aud = json_decode($aud, true) ?: [];
        }
        $out = [];
        foreach ((array) $aud as $a) {
            foreach ((array) ($a['recurso'] ?? []) as $r) {
                if (!empty($r['id'])) $out[] = $r['id'];
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** ¿El token incluye este recurso? Compara por fragmento: los ids son rutas. */
    public static function tieneRecurso(array $recursos, string $clave): bool
    {
        foreach ($recursos as $r) {
            if (str_contains($r, $clave)) return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Prueba de conexión
    // ------------------------------------------------------------------

    /**
     * Comprueba las credenciales de punta a punta y devuelve un diagnóstico
     * legible: qué funciona y qué falta habilitar.
     */
    public static function probar(array $cred): array
    {
        $r = ['ok' => false, 'mensaje' => '', 'recursos' => [], 'sire' => false, 'cpe' => false, 'periodos' => []];

        $tk = self::token($cred, self::SCOPE_SIRE);          // lanza si falla
        $r['recursos'] = self::recursosDelToken($tk);
        $r['sire'] = self::tieneRecurso($r['recursos'], 'migeigv');
        $r['cpe']  = self::tieneRecurso($r['recursos'], 'consultacpe');

        if (!$r['sire']) {
            $r['mensaje'] = 'Las credenciales son válidas, pero el token no incluye el SIRE (migeigv). '
                . 'Habilite el recurso "MIGE RCE y RVIE - SIRE" en el portal SOL y pulse GUARDAR.';
            return $r;
        }

        // El check más barato de que el RUC está incorporado al SIRE.
        [$code, $body] = self::getSire('/v1/contribuyente/migeigv/libros/rvierce/padron/web/omisos/140000/periodos', $tk);
        if ($code !== 200) {
            $r['mensaje'] = "El token es válido pero SUNAT no devolvió los períodos del SIRE (HTTP $code). "
                . 'Puede que el RUC todavía no esté incorporado al SIRE.';
            return $r;
        }

        foreach ((array) json_decode($body, true) as $ejercicio) {
            foreach ((array) ($ejercicio['lisPeriodos'] ?? []) as $p) {
                if (!empty($p['perTributario'])) {
                    $r['periodos'][$p['perTributario']] = $p['desEstado'] ?? '';
                }
            }
        }
        krsort($r['periodos']);

        $r['ok'] = true;
        $r['mensaje'] = 'Conexión correcta. SIRE disponible con ' . count($r['periodos']) . ' período(s).'
            . ($r['cpe'] ? ' Descarga de comprobantes (consultacpe) habilitada.'
                         : ' Falta el recurso consultacpe: se podrá leer el SIRE pero no bajar PDF ni CDR.');
        return $r;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /** GET del SIRE: SOLO la cabecera Authorization (ver nota del WAF arriba). */
    public static function getSire(string $ruta, string $token, int $timeout = 20): array
    {
        return self::http('GET', self::SCOPE_SIRE . $ruta, null, ['Authorization: Bearer ' . $token], $timeout);
    }

    /** GET de api-cpe: aquí sí hacen falta Accept y User-Agent de navegador. */
    public static function getCpe(string $ruta, string $token, int $timeout = 30): array
    {
        return self::http('GET', self::SCOPE_CPE . $ruta, null, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . self::UA,
            'sec-fetch-mode: cors',
        ], $timeout);
    }

    /** @return array{0:int,1:string} código HTTP y cuerpo */
    public static function http(string $metodo, string $url, ?string $cuerpo, array $headers, int $timeout = 20): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // Un fallo de transporte (timeout, DNS, conexión cortada) se devuelve
        // como código 0 en vez de lanzar: es la falla transitoria MÁS común y
        // lanzando aquí el reintento del llamador nunca llegaba a ejecutarse.
        if ($resp === false) {
            return [0, 'Sin respuesta de SUNAT: ' . $err];
        }
        return [$code, $resp];
    }

    /** ¿El fallo es transitorio y conviene reintentar? */
    public static function esTransitorio(int $code): bool
    {
        return $code === 0 || $code === 429 || $code >= 500;
    }
}
