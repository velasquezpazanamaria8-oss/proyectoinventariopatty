<?php
/**
 * Sesión del portal SOL de SUNAT por HTTP puro (cURL), sin navegador ni Node.
 *
 * PORTADO desde ContaDocs (app/SunatSesionHttp.php), donde está verificado en
 * vivo contra SUNAT real. Se copia en lugar de reescribirse a propósito: las
 * cuatro trampas del WAF que documenta abajo cuestan una corrida fallida cada
 * una, y volver a deducirlas no aporta nada.
 *
 * Se usa sólo para el ALTA DE PERMISOS (habilitar recursos de la API en la
 * aplicación registrada del contribuyente). Las consultas normales al SIRE y a
 * consultacpe van por SunatApi con OAuth2, que no necesita nada de esto.
 *
 * Las 4 trampas, cada una con su corrida fallida detrás:
 *  1. Seguir las redirecciones escritas en JavaScript (`redirect("...")`), que
 *     no son 302 y por tanto cURL no las sigue solo.
 *  2. Rellenar `state` y `originalUrl` en el POST de login. El formulario los
 *     trae VACÍOS —los completa el JS de la página— y sin ellos el code vuelve
 *     a la raíz de api-seguridad, donde nadie lo canjea.
 *  3. Separar las cookies POR DOMINIO. Las `TS…` son del WAF y están atadas a
 *     su host; mandarlas al host equivocado se interpreta como manipulación y
 *     responde "Request Rejected".
 *  4. Mandar el juego completo de cabeceras de navegador. El WAF mira bastante
 *     más que el User-Agent.
 *
 * Regla de operación: NO reintentar el login en ráfaga. Tras varios intentos
 * seguidos SUNAT empieza a rechazar un usuario que era válido.
 */

class SunatSesionException extends Exception
{
    public string $motivo;
    public ?string $urlSunat;

    public function __construct(string $mensaje, string $motivo, ?string $url = null)
    {
        parent::__construct($mensaje);
        $this->motivo = $motivo;
        $this->urlSunat = $url;
    }

    /** ¿Vale la pena reintentar más tarde (otro turno del cron)? */
    public function esTransitorio(): bool
    {
        return $this->motivo === 'sunat-no-disponible' || $this->motivo === 'pagina-vacia';
    }
}

class SunatSesion
{
    const MENU_CLASICO = 'https://e-menu.sunat.gob.pe/cl-ti-itmenu/MenuInternet.htm';
    const MENU_PLATAFORMA = 'https://e-menu.sunat.gob.pe/cl-ti-itmenu2/MenuInternetPlataforma.htm';
    const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    /** @var array<string,array{valor:string,dominio:string}> */
    private array $cookies = [];
    private string $estadoOAuth = '';
    private string $retornoOAuth = '';
    private string $ruc = '';
    private string $urlMenu = self::MENU_CLASICO;
    private string $htmlInicial = '';
    private string $urlInicial = '';
    /** @var callable */
    private $log;
    private int $timeoutMs;

    private function __construct(callable $log, int $timeoutMs)
    {
        $this->log = $log;
        $this->timeoutMs = $timeoutMs;
    }

    public function paginaInicial(): string { return $this->htmlInicial; }
    public function urlInicial(): string { return $this->urlInicial; }

    /** @param array{ruc:string,usuarioSol:string,claveSol:string} $credenciales */
    public static function abrir(array $credenciales, array $opciones = []): self
    {
        $sesion = new self($opciones['log'] ?? function ($m) {}, $opciones['timeoutMs'] ?? 30000);
        $sesion->urlMenu = $opciones['urlMenu'] ?? self::MENU_CLASICO;
        $sesion->login($credenciales, $sesion->urlMenu);
        return $sesion;
    }

    // --- Cookies por dominio ----------------------------------------------
    private function guardarCookies(string $rawHeaders, string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!preg_match_all('/^Set-Cookie:\s*(.+)$/mi', $rawHeaders, $matches)) return;
        foreach ($matches[1] as $cabecera) {
            $partes = explode(';', trim($cabecera));
            $par = trim($partes[0]);
            $i = strpos($par, '=');
            if ($i === false || $i <= 0) continue;

            $declarado = null;
            foreach (array_slice($partes, 1) as $p) {
                if (preg_match('/^\s*domain=(.+)$/i', $p, $dm)) { $declarado = ltrim(trim($dm[1]), '.'); break; }
            }
            $dominio = $declarado ?? $host;
            $nombre = trim(substr($par, 0, $i));
            $valor = trim(substr($par, $i + 1));
            $this->cookies["$dominio|$nombre"] = ['valor' => $valor, 'dominio' => $dominio];
        }
    }

    private function cookiesPara(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $partes = [];
        foreach ($this->cookies as $clave => $g) {
            if ($host === $g['dominio'] || str_ends_with($host, '.' . $g['dominio'])) {
                $nombre = substr($clave, strpos($clave, '|') + 1);
                $partes[] = "$nombre={$g['valor']}";
            }
        }
        return implode('; ', $partes);
    }

    /** @return string[] nombres de cookies vivas, para diagnosticar sin exponer valores */
    private function nombresCookies(): array
    {
        return array_map(fn($k) => substr($k, strpos($k, '|') + 1), array_keys($this->cookies));
    }

    private function autenticada(): bool
    {
        foreach (array_keys($this->cookies) as $k) {
            if (str_ends_with($k, '|ITMENUSESSION')) return true;
            if ($this->ruc !== '' && str_contains($k, $this->ruc)) return true;
        }
        return false;
    }

    // --- Resolución de URLs relativas (sin navegador que lo haga solo) ----
    private static function resolverUrl(string $rel, string $base): string
    {
        if (preg_match('#^https?://#i', $rel)) return $rel;
        $p = parse_url($base);
        $host = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($rel[0] === '/') return $host . $rel;
        $path = $p['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return $host . self::normalizarPath($dir . $rel);
    }

    private static function normalizarPath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $parte) {
            if ($parte === '.' || $parte === '') continue;
            if ($parte === '..') { array_pop($out); continue; }
            $out[] = $parte;
        }
        return '/' . implode('/', $out);
    }

    // --- HTTP ---------------------------------------------------------------
    /** @return array{status:int,body:string,location:?string,url:string} */
    private function ir(string $url, string $metodo = 'GET', ?string $cuerpo = null, ?string $referer = null, array $extra = []): array
    {
        $headers = [
            'User-Agent' => self::UA,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'es-ES,es;q=0.9',
            'Upgrade-Insecure-Requests' => '1',
            'sec-ch-ua' => '"Chromium";v="151", "Not.A/Brand";v="24", "Google Chrome";v="151"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => $referer ? 'same-site' : 'none',
            'Sec-Fetch-User' => '?1',
        ];
        $headers = array_merge($headers, $extra);
        $cookieStr = $this->cookiesPara($url);
        if ($cookieStr !== '') $headers['Cookie'] = $cookieStr;
        if ($referer) $headers['Referer'] = $referer;
        if ($cuerpo !== null) $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        $headerLines = [];
        foreach ($headers as $k => $v) $headerLines[] = "$k: $v";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new SunatSesionException("Fallo de red hacia SUNAT: $err", 'sunat-no-disponible', $url);
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawHeaders = substr($resp, 0, $headerSize);
        $body = substr($resp, $headerSize);
        $this->guardarCookies($rawHeaders, $url);

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $m)) $location = trim($m[1]);

        return ['status' => $status, 'body' => $body, 'location' => $location, 'url' => $url];
    }

    /** Sigue 302/301/303 reales (no los de JavaScript, ver seguirJs). */
    private function irSiguiendo(string $url, string $metodo = 'GET', ?string $cuerpo = null, ?string $referer = null, int $maxSaltos = 10): array
    {
        $actual = $url;
        $r = $this->ir($url, $metodo, $cuerpo, $referer);
        $this->anotarToken($actual);
        for ($i = 0; $i < $maxSaltos && $r['status'] >= 300 && $r['status'] < 400; $i++) {
            if (!$r['location']) break;
            $actual = self::resolverUrl($r['location'], $actual);
            $this->anotarToken($actual);
            $r = $this->ir($actual, 'GET', null, $url);
        }
        $r['url'] = $actual;
        return $r;
    }

    private string $ultimoToken = '';

    /**
     * Guarda el `?token=` de cualquier URL de la cadena, no solo de la final.
     *
     * Los módulos del portal (ej. "Credenciales de API") entran por un *loader*
     * que recibe el token y acto seguido redirige a la pantalla real, que ya no
     * lo lleva. Quedarse solo con la URL final tira el token por el camino y
     * todo lo que venga después responde 401.
     */
    private function anotarToken(string $url): void
    {
        $q = [];
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) parse_str($query, $q);
        $t = $q['token'] ?? null;
        if ($t && str_starts_with($t, 'eyJ')) $this->ultimoToken = $t;
    }

    /** Token de sesión visto en la última navegación a un módulo, si lo hubo. */
    public function tokenModulo(): string { return $this->ultimoToken; }

    /**
     * SUNAT encadena páginas cuyo único contenido es `redirect("...")` dentro de
     * un <script>. No son 302, así que hay que sacar la URL del HTML y seguirla.
     */
    private function seguirJs(string $html, string $desde, int $maxSaltos = 6): array
    {
        $actual = $html;
        $url = $desde;
        for ($i = 0; $i < $maxSaltos; $i++) {
            $destino = null;
            if (preg_match('/redirect\(\s*["\']([^"\']+)["\']/i', $actual, $m)) $destino = $m[1];
            elseif (preg_match('/(?:window\.)?location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i', $actual, $m)) $destino = $m[1];
            if (!$destino) break;

            $url = self::resolverUrl($destino, $url);

            $q = [];
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) parse_str($query, $q);
            if (!empty($q['redirect_uri'])) {
                $this->retornoOAuth = $q['redirect_uri'];
                $this->estadoOAuth = $q['state'] ?? $this->estadoOAuth;
            }

            $seguido = $this->irSiguiendo($url, 'GET', null, $desde);
            $actual = $seguido['body'];
            $url = $seguido['url'];
        }
        return ['html' => $actual, 'url' => $url];
    }

    // --- Apertura de sesión -------------------------------------------------
    /** @param array{ruc:string,usuarioSol:string,claveSol:string} $credenciales */
    private function login(array $credenciales, string $urlMenu): void
    {
        $ruc = $credenciales['ruc'];
        $usuarioSol = $credenciales['usuarioSol'];
        $claveSol = $credenciales['claveSol'];
        $this->ruc = $ruc;

        $inicial = $this->irSiguiendo($urlMenu);
        $seguido = $this->seguirJs($inicial['body'], $inicial['url']);
        $htmlLogin = $seguido['html'];
        $urlLogin = $seguido['url'];

        if ($htmlLogin === '') {
            throw new SunatSesionException('La pantalla de login llegó vacía; suele ser SUNAT a medias o el WAF cortando.', 'pagina-vacia', $urlLogin);
        }

        if (!preg_match('/<form[^>]*action=["\']([^"\']+)["\']/i', $htmlLogin, $m)) {
            throw new SunatSesionException(
                'No se encontró el formulario de login. Empieza así: ' . preg_replace('/\s+/', ' ', substr($htmlLogin, 0, 160)),
                'sunat-no-disponible',
                $urlLogin
            );
        }
        $accion = $m[1];

        $ocultos = [];
        if (preg_match_all('/<input[^>]*type=["\']?hidden["\']?[^>]*>/i', $htmlLogin, $inputs)) {
            foreach ($inputs[0] as $inp) {
                if (preg_match('/name=["\']([^"\']+)["\']/i', $inp, $nm)) {
                    $val = '';
                    if (preg_match('/value=["\']([^"\']*)["\']/i', $inp, $vm)) $val = $vm[1];
                    $ocultos[$nm[1]] = $val;
                }
            }
        }

        $cuerpoArr = array_merge($ocultos, [
            'tipo' => '2',
            'dni' => '',
            // Mismo criterio que el token M2M: RUC y usuario SOL concatenados.
            'j_username' => $ruc . $usuarioSol,
            'j_password' => $claveSol,
        ]);

        $qs = [];
        $query = parse_url($urlLogin, PHP_URL_QUERY);
        if ($query) parse_str($query, $qs);
        foreach ($qs as $k => $v) {
            if (empty($cuerpoArr[$k])) $cuerpoArr[$k] = $v;
        }
        // Estos dos deciden a dónde vuelve el code. Sin ellos SUNAT autentica pero
        // lo devuelve a la raíz de api-seguridad, y la sesión queda a medias.
        if ($this->estadoOAuth !== '') $cuerpoArr['state'] = $this->estadoOAuth;
        if ($this->retornoOAuth !== '') $cuerpoArr['originalUrl'] = $this->retornoOAuth;

        $accionUrl = self::resolverUrl($accion, $urlLogin);
        $post = $this->irSiguiendo($accionUrl, 'POST', http_build_query($cuerpoArr), $urlLogin);
        $htmlPost = $post['body'];

        if ($this->autenticada()) {
            $this->htmlInicial = $htmlPost;
            $this->urlInicial = $post['url'];
            ($this->log)("sesión abierta para $ruc ($usuarioSol) · " . strlen($htmlPost) . ' bytes');
            return;
        }

        if (preg_match('/Request Rejected/i', $htmlPost)) {
            throw new SunatSesionException('El WAF de SUNAT rechazó la petición durante el canje del code.', 'sunat-no-disponible', $post['url']);
        }
        if (preg_match('/captcha/i', $htmlPost) && !preg_match('/j_password/i', $htmlPost)) {
            throw new SunatSesionException('Apareció un captcha en el login.', 'captcha', $post['url']);
        }
        if (preg_match('/[?&]code=/', $post['url'])) {
            throw new SunatSesionException('Las credenciales fueron aceptadas pero el canje del code no completó la sesión.', 'sin-token', $post['url']);
        }
        throw new SunatSesionException(
            "SUNAT no aceptó las credenciales. Ojo: tras varios intentos seguidos rechaza usuarios que sí son válidos — espacia el siguiente intento.\n" .
                '  URL final: ' . substr($post['url'], 0, 160) . "\n" .
                '  Cookies  : ' . (implode(', ', $this->nombresCookies()) ?: '(ninguna)') . "\n" .
                '  Respuesta: ' . preg_replace('/\s+/', ' ', substr($htmlPost, 0, 200)),
            'credenciales',
            $post['url']
        );
    }

    // --- Uso ------------------------------------------------------------------
    /** Abre un módulo desde el menú y devuelve la URL donde quedó. */
    public function abrirModulo(string $action, string $host = 'ww1'): string
    {
        $r = $this->irSiguiendo("{$this->urlMenu}?action=$action&s=$host", 'GET', null, $this->urlMenu);
        return $r['url'];
    }

    /** Navega a una URL cualquiera dentro de la sesión y devuelve HTML y destino. */
    public function navegar(string $url, ?string $referer = null): array
    {
        $r = $this->irSiguiendo($url, 'GET', null, $referer);
        return $this->seguirJs($r['body'], $r['url']);
    }

    /** POST de un formulario del portal (application/x-www-form-urlencoded) y devuelve HTML y destino. */
    public function postFormulario(string $url, string $cuerpoFormUrlEncoded, ?string $referer = null): array
    {
        $r = $this->irSiguiendo($url, 'POST', $cuerpoFormUrlEncoded, $referer);
        return $this->seguirJs($r['body'], $r['url']);
    }

    /** GET con las cookies y cabeceras que espera el portal para sus AJAX. */
    public function traer(string $url, string $referer): string
    {
        return $this->ir($url, 'GET', null, $referer, [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'X-Requested-With' => 'XMLHttpRequest',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'X-Ruc' => $this->ruc,
        ])['body'];
    }

    public function traerBinario(string $url, string $referer): string
    {
        return $this->ir($url, 'GET', null, $referer, [
            'Accept' => '*/*',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'X-Ruc' => $this->ruc,
        ])['body'];
    }
}
