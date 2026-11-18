<?php
// Finds a place on the map (latitude / longitude) with OpenStreetMap's free search. Used for "Customers near me".
// Fair-use rule of that service: at most one search per second, and say who we are.
function geo_lookup($q) {
  static $last = 0.0;
  $q = trim(preg_replace('/\s+/', ' ', (string) $q)); if ($q === '' || !function_exists('curl_init')) return null;
  $wait = 1.1 - (microtime(true) - $last); if ($wait > 0) usleep((int) ($wait * 1e6));
  $url = (setting('geo_url', '') ?: 'https://nominatim.openstreetmap.org/search') . '?' . http_build_query(['q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'in']);
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['User-Agent: ZestCRM/1.0 (+https://' . ($_SERVER['HTTP_HOST'] ?? 'crm.zestexhibitions.com') . ')', 'Accept-Language: en']]);
  $r = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); $last = microtime(true);
  if ($code !== 200 || !$r) return null;
  $j = json_decode($r, true); if (!is_array($j) || !isset($j[0]['lat'])) return null;
  return ['lat' => round((float) $j[0]['lat'], 6), 'lng' => round((float) $j[0]['lon'], 6), 'label' => mb_substr((string) ($j[0]['display_name'] ?? $q), 0, 200)];
}
// a client: try the full address first, then just the pincode, then the city
function geo_client($c) {
  $addr = trim((string) $c['location']); $city = trim((string) $c['city']);
  $tries = [];
  if ($addr !== '') $tries[] = [$addr . ($city && stripos($addr, $city) === false ? ', ' . $city : ''), 'address'];
  if (preg_match('/\b(\d{3})\s?(\d{3})\b/', $addr, $m)) $tries[] = [$m[1] . $m[2] . ', India', 'pincode'];
  if ($city !== '') $tries[] = [$city . ', India', 'city'];
  foreach ($tries as [$q, $prec]) { $g = geo_lookup($q); if ($g) return ['lat' => $g['lat'], 'lng' => $g['lng'], 'geo_q' => mb_substr($q, 0, 400), 'geo_prec' => $prec]; }
  return ['lat' => null, 'lng' => null, 'geo_q' => mb_substr(($tries[0][0] ?? '-'), 0, 400) ?: '-', 'geo_prec' => 'none'];
}
