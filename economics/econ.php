<?php
/**
 * Bono Juris economics data endpoint.
 * Source: IMF World Economic Outlook via the IMF DataMapper API (no key required).
 * Fetches five headline indicators for eight economies, caches for 12 hours,
 * and returns normalised JSON. Missing values stay null: nothing is estimated or filled.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=600');

const BASE       = 'https://www.imf.org/external/datamapper/api/v1';
const CACHE_TTL  = 43200;  // 12 h: WEO is revised twice a year
const TIMEOUT    = 15;

$INDICATORS = [
  'NGDP_RPCH'   => ['Real GDP growth',                 '% change'],
  'PCPIPCH'     => ['Inflation, average consumer prices', '% change'],
  'LUR'         => ['Unemployment rate',               '% of labour force'],
  'BCA_NGDPD'   => ['Current account balance',         '% of GDP'],
  'GGXWDG_NGDP' => ['Government gross debt',           '% of GDP'],
];

// key => [display label, IMF code or null (resolved at run time)]
$ECONOMIES = [
  'IND' => ['India',          'IND'],
  'CHN' => ['China',          'CHN'],
  'KOR' => ['South Korea',    'KOR'],
  'SGP' => ['Singapore',      'SGP'],
  'HKG' => ['Hong Kong SAR',  'HKG'],
  'TWN' => ['Taiwan',         'TWN'],
  'EA'  => ['Euro area',      null],
  'USA' => ['United States',  'USA'],
];

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0755, true);
  @file_put_contents($cacheDir . '/.htaccess', "Require all denied\n");
}

$thisYear = (int)date('Y');
$years = range($thisYear - 11, $thisYear + 2);
$periods = implode(',', $years);

function cpath($dir, $url) { return $dir . '/' . md5($url) . '.json'; }

/** Fetch URLs in parallel with a file cache and stale-on-error fallback. */
function fetch_many(array $urls, string $dir): array {
  $res = []; $mh = curl_multi_init(); $hs = [];
  foreach ($urls as $u) {
    $p = cpath($dir, $u);
    if (is_file($p) && time() - filemtime($p) < CACHE_TTL) {
      $res[$u] = ['body' => file_get_contents($p), 'status' => 'cached']; continue;
    }
    $ch = curl_init($u);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
      CURLOPT_TIMEOUT => TIMEOUT, CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_USERAGENT => 'BonoJurisEconomics/1.0 (+https://bonojuris.com)',
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    curl_multi_add_handle($mh, $ch); $hs[$u] = $ch;
  }
  if ($hs) {
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    foreach ($hs as $u => $ch) {
      $body = curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $p = cpath($dir, $u);
      if ($code === 200 && $body && json_decode($body, true) !== null) {
        @file_put_contents($p, $body, LOCK_EX); $res[$u] = ['body' => $body, 'status' => 'live'];
      } elseif (is_file($p)) {
        $res[$u] = ['body' => file_get_contents($p), 'status' => 'stale (HTTP ' . $code . ')'];
      } else {
        $res[$u] = ['body' => null, 'status' => 'failed (HTTP ' . $code . ')'];
      }
      curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
  }
  curl_multi_close($mh);
  return $res;
}

/** Find the euro-area code by label in the countries/regions/groups listings. */
function find_euro_area(array $lists) {
  foreach ($lists as $json) {
    if (!is_array($json)) continue;
    foreach ($json as $section) {
      if (!is_array($section)) continue;
      foreach ($section as $code => $meta) {
        $label = is_array($meta) ? ($meta['label'] ?? '') : '';
        if (is_string($code) && preg_match('/^euro\s*area$/i', trim($label))) return $code;
      }
    }
  }
  return null;
}

$listUrls = [BASE . '/countries', BASE . '/regions', BASE . '/groups'];
$dataUrls = [];
foreach ($INDICATORS as $code => $_) $dataUrls[$code] = BASE . '/' . $code . '?periods=' . $periods;

$got = fetch_many(array_merge($listUrls, array_values($dataUrls)), $cacheDir);

$lists = [];
foreach ($listUrls as $u) $lists[] = $got[$u]['body'] ? json_decode($got[$u]['body'], true) : null;
$euroCode = find_euro_area($lists);

$status = [];
$econOut = [];
foreach ($ECONOMIES as $key => [$label, $code]) {
  if ($key === 'EA') $code = $euroCode;
  $econOut[$key] = ['key' => $key, 'label' => $label, 'code' => $code, 'data' => []];
}
if ($euroCode === null) $status[] = ['source' => 'Euro area code lookup', 'status' => 'not found: euro area will show no data'];

$indOut = [];
foreach ($INDICATORS as $icode => [$ilabel, $unit]) {
  $indOut[] = ['code' => $icode, 'label' => $ilabel, 'unit' => $unit];
  $r = $got[$dataUrls[$icode]];
  $status[] = ['source' => $ilabel, 'status' => $r['status']];
  $vals = $r['body'] ? (json_decode($r['body'], true)['values'][$icode] ?? []) : [];
  foreach ($econOut as $key => &$e) {
    $series = ($e['code'] !== null && isset($vals[$e['code']]) && is_array($vals[$e['code']])) ? $vals[$e['code']] : [];
    $row = [];
    foreach ($years as $y) {
      $v = $series[(string)$y] ?? null;
      $row[(string)$y] = (is_numeric($v)) ? round((float)$v, 2) : null;
    }
    $e['data'][$icode] = $row;
  }
  unset($e);
}

echo json_encode([
  'generated_at' => gmdate('c'),
  'source'       => 'IMF World Economic Outlook, via IMF DataMapper API',
  'source_url'   => 'https://www.imf.org/external/datamapper',
  'years'        => $years,
  'indicators'   => $indOut,
  'economies'    => array_values($econOut),
  'status'       => $status,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
