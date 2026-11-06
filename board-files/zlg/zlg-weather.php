<?php
/*
 * zlg-weather: today's weather for NEP's pages, from Open-Meteo
 * (open-meteo.com: free, no API key; non-commercial use). Replaces NEP's
 * wea, which asked Yahoo's YQL weather service (shut down in 2019).
 * zlg-chroot mounts it over /opt/php5/bin/wea, so NEP's hourly gene.sh runs
 * it unchanged.
 *
 * Location: longitude and latitude from /root/Gateway/location (lines 2
 * and 3), which the gateway's General settings page writes; or
 * ZLG_WEATHER_LAT / ZLG_WEATHER_LON in /etc/default/zlg (passed in by
 * zlg-chroot as /m6g2c/weather.conf).
 *
 * Writes /root/Gateway/nep/static/local/weather in wea's format, with
 * temperatures in °F (the pages show °F and °C). On failure the last good
 * file stays.
 */
$weafile = '/root/Gateway/nep/static/local/weather';
$confile = '/root/Gateway/location';
$override = '/m6g2c/weather.conf';

echo date('Y-m-d H:i:s'), "\tget weather....";

$lat = $lon = null;
if (is_readable($override)) {
	$o = parse_ini_file($override);
	if (is_numeric($o['lat'] ?? null) && is_numeric($o['lon'] ?? null)) {
		$lat = (float)$o['lat'];
		$lon = (float)$o['lon'];
	}
}
if ($lat === null && is_readable($confile)) {
	$l = array_map('trim', explode("\n", file_get_contents($confile)));
	if (is_numeric($l[1] ?? null) && is_numeric($l[2] ?? null)) {
		$lon = (float)$l[1];
		$lat = (float)$l[2];
	}
}
if ($lat === null || abs($lat) > 90 || abs($lon) > 180) {
	echo "no location info.\n";
	exit(1);
}

$url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
	'latitude' => $lat, 'longitude' => $lon,
	'daily' => 'temperature_2m_max,temperature_2m_min,weather_code,sunrise,sunset',
	'temperature_unit' => 'fahrenheit', 'timezone' => 'auto', 'forecast_days' => 1,
]);
$ctx = stream_context_create([
	'http' => ['timeout' => 20, 'user_agent' => 'zlg-weather (EPC-M6G2C gateway)'],
	'ssl' => ['cafile' => '/m6g2c/ca-certificates.crt', 'verify_peer' => true],
	/* IPv4 only: PHP doesn't fall back from an IPv6 address that won't
	   connect (as on a network with NAT64 addresses but no IPv6 route) */
	'socket' => ['bindto' => '0:0'],
]);
$res = @file_get_contents($url, false, $ctx);
$w = $res ? json_decode($res, true) : null;
$d = $w['daily'] ?? null;
if (!is_array($d) || !isset($d['temperature_2m_max'][0], $d['weather_code'][0])) {
	echo 'error: ', $res === false ? (error_get_last()['message'] ?? 'no answer') : 'unexpected answer', "\n";
	exit(1);
}

/* WMO weather codes (open-meteo.com/en/docs), in Yahoo's wording */
function wmo_text(int $c): string
{
	return match (true) {
		$c === 0 => 'Sunny',
		$c === 1 => 'Mostly Sunny',
		$c === 2 => 'Partly Cloudy',
		$c === 3 => 'Cloudy',
		$c === 45, $c === 48 => 'Foggy',
		$c >= 51 && $c <= 57 => 'Drizzle',
		$c >= 61 && $c <= 65 => 'Rain',
		$c === 66, $c === 67 => 'Freezing Rain',
		$c >= 71 && $c <= 77 => 'Snow',
		$c >= 80 && $c <= 82 => 'Showers',
		$c === 85, $c === 86 => 'Snow Showers',
		$c >= 95 => 'Thunderstorms',
		default => 'Unknown',
	};
}

/* "2026-11-06T06:40" -> "6:40 am", as Yahoo wrote it */
function clock(?string $iso): string
{
	if (!$iso || !preg_match('~T(\d\d):(\d\d)~', $iso, $m))
		return '';
	$h = (int)$m[1];
	return sprintf('%d:%s %s', ($h % 12) ?: 12, $m[2], $h < 12 ? 'am' : 'pm');
}

$out = sprintf("{'sunrise':'%s','sunset':'%s','temp_high':'%d','temp_low':'%d','temp_text':'%s','description':''}",
	clock($d['sunrise'][0] ?? null), clock($d['sunset'][0] ?? null),
	round($d['temperature_2m_max'][0]), round($d['temperature_2m_min'][0] ?? $d['temperature_2m_max'][0]),
	wmo_text((int)$d['weather_code'][0]));

$tmp = "$weafile.tmp";
if (file_put_contents($tmp, $out) === false || !rename($tmp, $weafile)) {
	echo "cannot write $weafile\n";
	exit(1);
}
@chmod($weafile, 0644);
echo date('Y-m-d H:i:s'), "\tDone\n";
