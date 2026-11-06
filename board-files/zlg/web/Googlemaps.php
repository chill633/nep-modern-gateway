<?php
/*
 * Drop-in replacement for NEP's Googlemaps library (zlg-web-port installs it
 * in its place). NEP's asked Google's Static Maps API at maps.google.cn
 * without an API key, which Google no longer serves, so the Info page showed
 * a broken image. This one embeds an OpenStreetMap map instead (no key).
 *
 * Same interface as NEP's: initialize($config), add_marker($marker),
 * create_map(). The position is the weather location from /etc/default/zlg
 * (ZLG_WEATHER_LAT/LON, as /m6g2c/weather.conf) if set, else what the
 * controller passes (the gateway's General settings).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

#[\AllowDynamicProperties]
class Googlemaps
{
	public $center = '';
	public $zoom = 10;
	public $map_width = 500;
	public $map_height = 500;
	public $map_id = 'map_canvas';
	public $markers = array();

	public function __construct($config = array())
	{
		$this->initialize($config);
	}

	public function initialize($config = array())
	{
		foreach ((array)$config as $k => $v)
			if (property_exists($this, $k))
				$this->$k = $v;
	}

	public function add_marker($params = array())
	{
		if (!empty($params['position']))
			$this->markers[] = $params['position'];
	}

	private static function latlon($s)
	{
		$p = array_map('trim', explode(',', (string)$s));
		if (count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1]) &&
		    abs($p[0]) <= 90 && abs($p[1]) <= 180)
			return array((float)$p[0], (float)$p[1]);
		return null;
	}

	public function create_map()
	{
		$pos = null;
		$conf = '/m6g2c/weather.conf';
		if (is_readable($conf)) {
			$o = @parse_ini_file($conf);
			if (is_numeric($o['lat'] ?? null) && is_numeric($o['lon'] ?? null))
				$pos = array((float)$o['lat'], (float)$o['lon']);
		}
		if (!$pos)
			$pos = self::latlon($this->center);
		if (!$pos && $this->markers)
			$pos = self::latlon($this->markers[0]);
		if (!$pos)
			return '<p>No location set (General settings).</p>';

		list($lat, $lon) = $pos;
		$w = (int)$this->map_width ?: 500;
		$h = (int)$this->map_height ?: 500;
		/* the bounding box that zoom level shows at this size */
		/* NEP asked for 7 (most of a state); 10 shows the town */
		$z = max(10, min(18, (int)$this->zoom));
		$dlon = $w * 360 / (256 * pow(2, $z)) / 2;
		$dlat = $h * 360 / (256 * pow(2, $z)) / 2 * cos(deg2rad($lat));
		$bbox = sprintf('%.5f,%.5f,%.5f,%.5f', $lon - $dlon, $lat - $dlat, $lon + $dlon, $lat + $dlat);
		$q = http_build_query(array('bbox' => $bbox, 'layer' => 'mapnik',
					    'marker' => sprintf('%.5f,%.5f', $lat, $lon)));
		$big = sprintf('https://www.openstreetmap.org/?mlat=%.5f&mlon=%.5f#map=%d/%.5f/%.5f',
			       $lat, $lon, $z, $lat, $lon);
		return '<iframe id="' . htmlspecialchars($this->map_id) . '" width="' . $w .
			'" height="' . $h . '" style="border:1px solid #ccc" loading="lazy" ' .
			'referrerpolicy="no-referrer" title="Map" ' .
			'src="https://www.openstreetmap.org/export/embed.html?' . htmlspecialchars($q) .
			'"></iframe><br><small><a href="' . htmlspecialchars($big) .
			'" target="_blank" rel="noopener">View larger map</a> &copy; ' .
			'<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">' .
			'OpenStreetMap contributors</a></small>';
	}
}
