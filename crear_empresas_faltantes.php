<?php
/**
 * Crea en esta base las empresas que faltan (mismos datos que en local),
 * usando Empresa::guardar() para que queden con sus catálogos base
 * (almacén, unidades, categoría, marca) igual que si se crearan desde
 * el formulario "Agregar empresa". No crea productos ni nada de SUNAT.
 *
 *   Navegador: https://tudominio.com/crear_empresas_faltantes.php
 *   Consola:   php crear_empresas_faltantes.php
 *
 * Idempotente: si el RUC ya existe, se omite esa fila.
 * Eliminar este archivo una vez aplicado.
 */
require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
function paso(string $t = ''): void { echo $t . PHP_EOL; }

$empresas = [
    ['ruc' => '20613129562', 'razon_social' => 'EMPRESA PERULOGISTIC S.A.C.', 'nombre_corto' => 'EMPRESA PERULOGISTIC S.A.C.', 'direccion' => null, 'telefono' => null, 'email' => null],
    ['ruc' => '20613023331', 'razon_social' => 'COMPAÑÍA PROVESUR S.A.C.', 'nombre_corto' => 'Provesur', 'direccion' => 'URB. DANIEL HOYLE CAL. ALEXANDER FLEMING Nro 223 TRUJILLO - TRUJILLO - LA LIBERTAD', 'telefono' => null, 'email' => 'companiaprovesur@gmail.com'],
    ['ruc' => '20614175738', 'razon_social' => 'MULTIMATERIALES S.A.C.', 'nombre_corto' => 'Multimateriales', 'direccion' => 'C.P. ALTO TRUJILLO BARRIO 3A PJ. MZ F LOTE 29 S/N LA LIBERTAD-TRUJILLO-EL PORVENIR', 'telefono' => null, 'email' => 'materialesmulti0@gmail.com'],
    ['ruc' => '20614182751', 'razon_social' => 'OADEY S.A.C.', 'nombre_corto' => 'Oadey', 'direccion' => 'JR. FRANCISCO DE ZELA 0088 SEC. RIO SECO BARRIO 3 EL PORVENIR- TRUJILLO- LA LIBERTAD', 'telefono' => null, 'email' => 'oadeysac@gmail.com'],
    ['ruc' => '20614878593', 'razon_social' => 'SOLUCIONES CONSTRUCTIVAS Y AGREGADOS S.A.C.', 'nombre_corto' => 'Soluciones', 'direccion' => 'P.J. FLORENCIA DE MORA BA. 2 CAL. 12 DE NOVIEMBRE Nro. 810 FLORENCIA DE MORA - TRUJILLO - LA LIBERTAD', 'telefono' => '986319167', 'email' => 'soluciones593sac@gmail.com'],
    ['ruc' => '20615383954', 'razon_social' => 'INVERSIONES & SERVICIOS GLOBAL S.A.C.', 'nombre_corto' => 'Inv. & Ser. Global', 'direccion' => 'URB. LA NORIA CAL. JAIME BALMES Nro. 443 TRUJILLO - LA LIBERTAD', 'telefono' => null, 'email' => 'inversionglobal145@gmail.com'],
    ['ruc' => '20615091848', 'razon_social' => 'SUMINOR ANDINA S.A.C.', 'nombre_corto' => 'Suminor Andina', 'direccion' => 'URB. MIRAFLORES AV. HERMANOS UCEDA MEZA H Nro. 5A Int. 0201 TRUJILLO - LA LIBERTAD', 'telefono' => null, 'email' => 'suminorandina@gmail.com'],
];

function soloDigitos(string $s): string { return preg_replace('/\D+/', '', $s); }

try {
    paso('Creando empresas que faltan...');
    paso('');

    $existentes = DB::todos('SELECT ruc FROM empresas');
    $rucsExistentes = array_map(fn($e) => soloDigitos($e['ruc']), $existentes);

    $creadas = 0;
    $omitidas = 0;
    foreach ($empresas as $d) {
        if (in_array(soloDigitos($d['ruc']), $rucsExistentes, true)) {
            paso("[--] {$d['razon_social']} (RUC {$d['ruc']}) ya existe, se omite");
            $omitidas++;
            continue;
        }
        $d['moneda'] = 'PEN';
        $d['simbolo'] = 'S/';
        $id = Empresa::guardar($d, null);
        paso("[OK] creada {$d['razon_social']} (RUC {$d['ruc']}) -> empresa_id $id");
        $creadas++;
    }

    paso('');
    paso("Listo: $creadas empresa(s) creada(s), $omitidas omitida(s) por ya existir.");
    paso('Ahora corre restaurar_disenos.php para traer sus diseños de cotización.');

} catch (Throwable $e) {
    http_response_code(500);
    paso('[ERROR] ' . $e->getMessage());
}
