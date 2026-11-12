<?php
/**
 * Bono Juris monthly economics layer.
 * Source: Bank for International Settlements (BIS) open statistics, SDMX REST API v2, no key.
 *   - WS_CBPOL    central bank policy rates (covers 6 of the 8 economies; NOT Singapore, Taiwan; Taiwan comes from the CBC DataAPI)
 *   - WS_LONG_CPI consumer price index, monthly (covers 7 of 8; NOT Taiwan)
 * Inflation is computed here as the 12-month change of the BIS index. Nothing is estimated:
 * where BIS has no series, the economy simply has no value.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=600');

const API      = 'https://stats.bis.org/api/v2/data/dataflow/BIS';
const TTL      = 21600;  // 6 h
const TIMEOUT  = 20;

// economy key => [label, BIS area code]
$ECON = [
  'IND' => ['India', 'IN'], 'CHN' => ['China', 'CN'], 'KOR' => ['South Korea', 'KR'],
  'SGP' => ['Singapore', 'SG'], 'HKG' => ['Hong Kong SAR', 'HK'], 'TWN' => ['Taiwan', 'TW'],
  'EA'  => ['Euro area', 'XM'], 'USA' => ['United States', 'US'],
];
$NOTES = [
  'SGP' => 'Not in the BIS policy-rate dataset (monetary policy is run through the exchange rate, not a policy rate).',
  'TWN' => 'Policy rate: Central Bank of the Republic of China (Taiwan) discount rate. No monthly inflation series from a source this page can read automatically.',
];

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); @file_put_contents($cacheDir . '/.htaccess', "Require all denied\n"); }

$ymNow   = date('Y-m');
$dailyFrom = date('Y-m-d', strtotime('-120 days'));
$histFrom  = (date('Y') - 5) . '-01';
$cpiFrom   = (date('Y') - 3) . '-01';

$polAreas = 'IN+CN+KR+HK+US+XM';
$cpiAreas = 'IN+CN+KR+SG+HK+US+XM';
$URL = [
  'pol_d' => API . '/WS_CBPOL/1.0/D.' . $polAreas . '?startPeriod=' . $dailyFrom . '&format=csv',
  'pol_m' => API . '/WS_CBPOL/1.0/M.' . $polAreas . '?startPeriod=' . $histFrom . '&format=csv',
  'cpi_m' => API . '/WS_LONG_CPI/1.0/M.' . $cpiAreas . '.?startPeriod=' . $cpiFrom . '&format=csv',
];

function cpath($d, $u) { return $d . '/' . md5($u) . '.csv'; }

function fetch_many(array $urls, string $dir): array {
  $res = []; $mh = curl_multi_init(); $hs = [];
  foreach ($urls as $k => $u) {
    $p = cpath($dir, $u);
    if (is_file($p) && time() - filemtime($p) < TTL) { $res[$k] = ['body' => file_get_contents($p), 'status' => 'cached']; continue; }
    $ch = curl_init($u);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
      CURLOPT_TIMEOUT => TIMEOUT, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_USERAGENT => 'BonoJurisEconomics/1.0 (+https://bonojuris.com)',
      CURLOPT_HTTPHEADER => ['Accept: text/csv'],
    ]);
    curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
  }
  if ($hs) {
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    foreach ($hs as $k => $ch) {
      $body = curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $p = cpath($dir, $urls[$k]);
      if ($code === 200 && $body && stripos($body, 'OBS_VALUE') !== false) { @file_put_contents($p, $body, LOCK_EX); $res[$k] = ['body' => $body, 'status' => 'live']; }
      elseif (is_file($p)) { $res[$k] = ['body' => file_get_contents($p), 'status' => 'stale (HTTP ' . $code . ')']; }
      else { $res[$k] = ['body' => null, 'status' => 'failed (HTTP ' . $code . ')']; }
      curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
  }
  curl_multi_close($mh);
  return $res;
}

/** Parse SDMX-CSV into [area => [period => value]]. Handles "IN" and "IN: India" style codes. */
function parse_sdmx_csv(?string $csv, ?string $unitContains = null): array {
  $out = []; if (!$csv) return $out;
  $fh = fopen('php://memory', 'r+'); fwrite($fh, $csv); rewind($fh);
  $hdr = fgetcsv($fh, 0, ',', '"', ''); if (!$hdr) return $out;
  $hdr = array_map(fn($h) => strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))), $hdr);
  $iA = array_search('REF_AREA', $hdr); $iT = array_search('TIME_PERIOD', $hdr);
  $iV = array_search('OBS_VALUE', $hdr); $iU = array_search('UNIT_MEASURE', $hdr);
  if ($iA === false || $iT === false || $iV === false) return $out;
  while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if (count($r) <= max($iA, $iT, $iV)) continue;
    if ($unitContains !== null && $iU !== false && strpos((string)$r[$iU], $unitContains) === false) continue;
    $area = trim(explode(':', (string)$r[$iA])[0]);
    $per  = trim((string)$r[$iT]);
    $val  = $r[$iV];
    if ($area === '' || $per === '' || !is_numeric($val)) continue;
    $out[$area][$per] = (float)$val;
  }
  fclose($fh);
  return $out;
}

/** Taiwan discount rate from the Central Bank of the Republic of China (Taiwan) DataAPI (item EG2AM01en). */
function fetch_cbc(string $dir): array {
  $u = 'https://cpx.cbc.gov.tw/API/DataAPI/Get?FileName=EG2AM01en'; $p = $dir . '/cbc_' . md5($u) . '.json';
  if (is_file($p) && time() - filemtime($p) < TTL) return [json_decode(file_get_contents($p), true), 'cached'];
  $ch = curl_init($u);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => TIMEOUT, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_USERAGENT => 'BonoJurisEconomics/1.0 (+https://bonojuris.com)']);
  $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = $body ? json_decode($body, true) : null;
  if ($code === 200 && isset($j['data']['dataSets'])) { @file_put_contents($p, $body, LOCK_EX); return [$j, 'live']; }
  if (is_file($p)) return [json_decode(file_get_contents($p), true), 'stale (HTTP ' . $code . ')'];
  return [null, 'failed (HTTP ' . $code . ')'];
}
// Column 1 of that table is the discount rate (confirmed from the table's own structure block).
[$cbcJ, $cbcStatus] = fetch_cbc($cacheDir);
$twPol = [];
if ($cbcJ && ($cbcJ['data']['dataSets'] ?? null)) {
  foreach ($cbcJ['data']['dataSets'] as $r) {
    if (isset($r[0], $r[1]) && preg_match('/^(\d{4})M(\d{2})$/', $r[0], $m) && is_numeric($r[1])) $twPol[$m[1] . '-' . $m[2]] = (float)$r[1];
  }
}

$got = fetch_many($URL, $cacheDir);
$polD = parse_sdmx_csv($got['pol_d']['body']);
$polM = parse_sdmx_csv($got['pol_m']['body']);
$cpiM = parse_sdmx_csv($got['cpi_m']['body'], '628');
if (!$cpiM) $cpiM = parse_sdmx_csv($got['cpi_m']['body']); // unit filter matched nothing: accept as returned

function last_obs(array $s): ?array {
  if (!$s) return null; ksort($s); $k = array_key_last($s); return ['date' => $k, 'value' => round($s[$k], 2)];
}
function ym_minus(string $ym, int $m): string { return date('Y-m', strtotime($ym . '-01 -' . $m . ' months')); }

$econ = [];
foreach ($ECON as $key => [$label, $area]) {
  $row = ['key' => $key, 'label' => $label, 'area' => $area, 'note' => $NOTES[$key] ?? null,
          'policy' => null, 'policy_series' => [], 'cpi_latest' => null, 'cpi_series' => []];

  // Policy rate: latest daily observation; monthly history for the chart.
  if (!empty($polD[$area])) { $lo = last_obs($polD[$area]); $row['policy'] = $lo + ['freq' => 'daily']; }
  if (!empty($polM[$area])) {
    $m = $polM[$area]; ksort($m);
    foreach ($m as $per => $v) $row['policy_series'][] = [substr($per, 0, 7), round($v, 2)];
    if ($row['policy'] === null) $row['policy'] = last_obs($m) + ['freq' => 'monthly'];
  }

  if ($key === 'TWN' && $twPol) {
    ksort($twPol);
    foreach ($twPol as $per => $v) if ($per >= $histFrom) $row['policy_series'][] = [$per, round($v, 2)];
    $k = array_key_last($twPol); $row['policy'] = ['date' => $k, 'value' => round($twPol[$k], 2), 'freq' => 'monthly'];
  }

  // Inflation: 12-month change of the BIS CPI index.
  if (!empty($cpiM[$area])) {
    $idx = []; foreach ($cpiM[$area] as $per => $v) { if (preg_match('/^\d{4}-\d{2}$/', $per)) $idx[$per] = $v; }
    ksort($idx);
    foreach ($idx as $per => $v) {
      $prev = $idx[ym_minus($per, 12)] ?? null;
      if ($prev && $prev > 0) $row['cpi_series'][] = [$per, round(($v / $prev - 1) * 100, 1)];
    }
    if ($row['cpi_series']) { $l = end($row['cpi_series']); $row['cpi_latest'] = ['date' => $l[0], 'value' => $l[1]]; }
  }
  $econ[] = $row;
}

echo json_encode([
  'generated_at' => gmdate('c'),
  'source'       => 'Bank for International Settlements (BIS): central bank policy rates; long consumer prices series',
  'source_url'   => 'https://data.bis.org',
  'attribution'  => 'Sources: National sources; BIS long consumer prices database; BIS central bank policy rates; Taiwan policy rate from the Central Bank of the Republic of China (Taiwan), Open Government Data License v1.0.',
  'status'       => [
    ['source' => 'Policy rates (daily)',   'status' => $got['pol_d']['status']],
    ['source' => 'Policy rates (monthly)', 'status' => $got['pol_m']['status']],
    ['source' => 'Consumer prices',        'status' => $got['cpi_m']['status']],
    ['source' => 'Taiwan discount rate (CBC)', 'status' => $cbcStatus],
  ],
  'economies'    => $econ,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
