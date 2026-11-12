<?php
/**
 * Bono Juris headline aggregator.
 * Fetches a fixed list of public RSS/RDF feeds server-side, caches the result,
 * and returns JSON: headline + title + link-out only (no full text, no images).
 * Usage: feed.php?cat=india|world|business
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=120');

const CACHE_TTL   = 300;   // seconds before a feed is refetched
const FETCH_TIMEOUT = 8;   // seconds per feed
const MAX_ITEMS   = 12;    // per source
const SNIPPET_LEN = 0;     // titles and links only; no excerpts, until each publisher's reuse terms are confirmed

// Fixed allow-list: no user-supplied URLs (prevents SSRF).
$FEEDS = [
  'india' => [
    ['The Hindu — National',  'https://www.thehindu.com/news/national/feeder/default.rss'],
    ['The Hindu — Business',  'https://www.thehindu.com/business/feeder/default.rss'],
  ],
  'world' => [
    ['BBC News — World',      'https://feeds.bbci.co.uk/news/world/rss.xml'],
    ['Deutsche Welle',        'https://rss.dw.com/rdf/rss-en-all'],
  ],
  'business' => [
    ['BBC News — Business',   'https://feeds.bbci.co.uk/news/business/rss.xml'],
    ['The Hindu — Business',  'https://www.thehindu.com/business/feeder/default.rss'],
  ],
];

$cat = $_GET['cat'] ?? 'world';
if (!isset($FEEDS[$cat])) { http_response_code(400); echo json_encode(['error' => 'unknown category']); exit; }

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0755, true);
  @file_put_contents($cacheDir . '/.htaccess', "Require all denied\n");
}

function cache_path($dir, $url) { return $dir . '/' . md5($url) . '.xml'; }

/** Fetch all stale feeds in parallel; fall back to stale cache on failure. */
function load_feeds(array $feeds, string $dir): array {
  $out = []; $handles = []; $mh = curl_multi_init();
  foreach ($feeds as [$name, $url]) {
    $p = cache_path($dir, $url);
    $fresh = is_file($p) && (time() - filemtime($p) < CACHE_TTL);
    $out[$url] = ['name' => $name, 'xml' => $fresh ? file_get_contents($p) : null, 'status' => $fresh ? 'cached' : 'pending'];
    if (!$fresh) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => FETCH_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'BonoJurisHeadlines/1.0 (+https://bonojuris.com)',
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml, */*'],
      ]);
      curl_multi_add_handle($mh, $ch);
      $handles[$url] = $ch;
    }
  }
  if ($handles) {
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    foreach ($handles as $url => $ch) {
      $body = curl_multi_getcontent($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $p = cache_path($dir, $url);
      if ($code === 200 && $body && strlen($body) > 200) {
        @file_put_contents($p, $body, LOCK_EX);
        $out[$url]['xml'] = $body; $out[$url]['status'] = 'live';
      } elseif (is_file($p)) {
        $out[$url]['xml'] = file_get_contents($p); $out[$url]['status'] = 'stale (HTTP ' . $code . ')';
      } else {
        $out[$url]['status'] = 'failed (HTTP ' . $code . ')';
      }
      curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
  }
  curl_multi_close($mh);
  return $out;
}

function clean($s, $max = 0) {
  $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = trim(preg_replace('/\s+/u', ' ', $s));
  if ($max && mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
  return $s;
}

/** Parse RSS 2.0 and RSS 1.0/RDF with namespace-agnostic XPath. */
function parse_items(string $xml, string $source): array {
  $items = [];
  $prev = libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA)) { libxml_clear_errors(); libxml_use_internal_errors($prev); return []; }
  libxml_use_internal_errors($prev);
  $xp = new DOMXPath($dom);
  $first = function ($node, $name) use ($xp) {
    $r = $xp->query('./*[local-name()="' . $name . '"]', $node);
    return $r->length ? trim($r->item(0)->textContent) : '';
  };
  foreach ($xp->query('//*[local-name()="item"]') as $n) {
    if (count($items) >= MAX_ITEMS) break;
    $title = clean($first($n, 'title'));
    $link  = trim($first($n, 'link'));
    if ($link === '') { $link = trim($first($n, 'guid')); }
    if ($title === '' || !preg_match('#^https?://#i', $link)) continue;
    $date = $first($n, 'pubDate') ?: $first($n, 'date') ?: $first($n, 'published') ?: $first($n, 'updated');
    $ts = $date ? strtotime($date) : false;
    $items[] = [
      'title'   => $title,
      'link'    => $link,
      'snippet' => SNIPPET_LEN > 0 ? clean($first($n, 'description'), SNIPPET_LEN) : '',
      'ts'      => $ts ?: null,
      'source'  => $source,
    ];
  }
  return $items;
}

$feeds = load_feeds($FEEDS[$cat], $cacheDir);
$all = []; $status = [];
foreach ($feeds as $url => $f) {
  $status[] = ['source' => $f['name'], 'status' => $f['status']];
  if ($f['xml']) $all = array_merge($all, parse_items($f['xml'], $f['name']));
}
// De-duplicate by link, newest first; items with no date sink to the bottom.
$seen = []; $uniq = [];
foreach ($all as $it) { if (!isset($seen[$it['link']])) { $seen[$it['link']] = 1; $uniq[] = $it; } }
usort($uniq, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));

echo json_encode([
  'category'     => $cat,
  'generated_at' => gmdate('c'),
  'sources'      => $status,
  'items'        => array_slice($uniq, 0, 60),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
