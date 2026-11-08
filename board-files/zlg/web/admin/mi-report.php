<?php
/*
 * mi-report.php: the Advanced page's grid report (zlg-admin --mi-report-job).
 *
 * Reads every inverter module's settings (MI Setup's basic and extended
 * reads), decodes them as firmware 20230903's MI Setup page does (proc() and
 * proc_extend() in misetup/js/orig/proxy.js, ported here and checked against
 * NEP's JavaScript on the same replies), and writes a CSV and a PDF.
 *
 * For grid codes 6 and 7 (IEEE 1547A) firmware 20210303's MI Setup used
 * another encoding (as grid 1: 220 +- b*4/AC_V_Gain, f +- b/32); which one
 * the inverters use isn't known, so the report shows both.
 */

/* ---- NEP's tables (proc(), firmware 20230903), index = grid code - 1 --- */

const MI_T = [
	'UP'    => [220, 210, 220, 220, 255, 250, 220, 250, 220, 220, 220, 240, 240, 250, 250, 240, 245],
	'UPUP'  => [220, 220, 220, 220, 260, 270, 240, 270, 220, 220, 220, 250, 245, 260, 260, 240, 255],
	'LW'    => [220, 175, 220, 220, 205, 215, 190, 200, 220, 220, 220, 200, 200, 200, 200, 195, 210],
	'LWLW'  => [220, 175, 220, 220, 190, 200, 200, 110, 220, 220, 220, 190, 190, 70, 200, 195, 190],
	'UP_G'   => [10, 10, 1, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'UPUP_G' => [10, 10, 1, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'LW_G'   => [10, 10, 1, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'LWLW_G' => [10, 10, 1, 10, 10, 1, 1, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MAXV3' => [10, 10, 10, 10, 10, 1, 1, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MINV3' => [10, 10, 10, 10, 10, 1, 1, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MAXF3' => [10, 10, 10, 10, 10, 0.5, 0.5, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MINF3' => [10, 10, 10, 10, 10, 0.5, 0.5, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MAXV2' => [10, 10, 10, 10, 10, 100, 100, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MINV2' => [10, 10, 10, 10, 10, 100, 100, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MAXF2' => [10, 10, 10, 10, 10, 100, 100, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
	'TF_MINF2' => [10, 10, 10, 10, 10, 100, 100, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10],
];

const MI_GRIDS = [1 => 'IEEE 1547', 2 => 'JAPAN', 3 => 'DEFAULT', 5 => 'G83', 6 => '1547.1 240/120V',
	7 => '1547.1 208V', 8 => 'VDE 4105', 12 => 'EN50438-1', 13 => 'EN50438-2', 14 => 'TOR-A',
	15 => 'CEI 021', 16 => 'ABNT NBR 16149/16150', 17 => 'AS 4777'];

/* the fields, in the order and with the names of NEP's page */
const MI_BASIC = [
	'grid' => 'Grid code',
	'smaxv' => '1.VAC_START_MAX (V)', 'maxv' => '2.VAC_MAX (V)',
	'maxv2' => '3.VAC_MAX_2 (V)', 'maxtv2' => 'Time_VAC_MAX_2 (s)',
	'maxv3' => '4.VAC_MAX_3 (V)', 'maxtv3' => 'Time_VAC_MAX_3 (s)',
	'minv3' => '5.VAC_MIN_3 (V)', 'mintv3' => 'Time_VAC_MIN_3 (s)',
	'minv2' => '6.VAC_MIN_2 (V)', 'mintv2' => 'Time_VAC_MIN_2 (s)',
	'minv' => '7.VAC_MIN (V)', 'sminv' => '8.VAC_START_MIN (V)',
	'smaxf' => '1.FREQ_START_MAX (Hz)', 'maxf' => '2.FREQ_MAX (Hz)',
	'maxf2' => '3.FREQ_MAX_2 (Hz)', 'maxtf2' => 'Time_FREQ_MAX_2 (s)',
	'maxf3' => '4.FREQ_MAX_3 (Hz)', 'maxtf3' => 'Time_FREQ_MAX_3 (s)',
	'minf3' => '5.FREQ_MIN_3 (Hz)', 'mintf3' => 'Time_FREQ_MIN_3 (s)',
	'minf2' => '6.FREQ_MIN_2 (Hz)', 'mintf2' => 'Time_FREQ_MIN_2 (s)',
	'minf' => '7.FREQ_MIN (Hz)', 'sminf' => '8.FREQ_START_MIN (Hz)',
	'rect' => 'Reconnect Time (s)', 'pwr' => 'Power (W)', 'fref' => 'Freq offset',
	'suppressv_100' => 'Suppressing voltage 100 (V)', 'suppressv_200' => 'Suppressing voltage 200 (V)',
	'antiland' => 'Anti-islanding', 'unbalance' => 'Unbalance',
];
const MI_OLD = [
	'smaxv' => '1.VAC_START_MAX (V)', 'maxv' => '2.VAC_MAX (V)', 'maxv2' => '3.VAC_MAX_2 (V)',
	'maxv3' => '4.VAC_MAX_3 (V)', 'minv3' => '5.VAC_MIN_3 (V)', 'minv2' => '6.VAC_MIN_2 (V)',
	'minv' => '7.VAC_MIN (V)', 'sminv' => '8.VAC_START_MIN (V)',
	'smaxf' => '1.FREQ_START_MAX (Hz)', 'maxf' => '2.FREQ_MAX (Hz)', 'maxf2' => '3.FREQ_MAX_2 (Hz)',
	'maxf3' => '4.FREQ_MAX_3 (Hz)', 'minf3' => '5.FREQ_MIN_3 (Hz)', 'minf2' => '6.FREQ_MIN_2 (Hz)',
	'minf' => '7.FREQ_MIN (Hz)', 'sminf' => '8.FREQ_START_MIN (Hz)',
];
const MI_EXT = [
	'flag_disable' => 'DISABLE OUTPUT', 'flag_gfdi' => 'DISABLE GFDI', 'flag_lvrt' => 'LVRT',
	'flag_mppt' => 'DISABLE MPPT', 'flag_island' => 'DISABLE ANTI-ISLAND', 'flag_tenk' => 'TENK',
	'vnorm' => 'V_Norm (Vac)', 'ramp_delay_low' => 'Ramp Delay (x10 s)', 'temperature_max' => 'Temperature MAX',
	'freq_watt' => 'FREQ-WATT', 'db_offset_low' => 'Freq_Db_Over (Hz)', 'db_offset_high' => 'Freq_Db_Under (Hz)',
	'pwrf_ctrl_low_03' => 'Power Decreasing Rate (%)', 'pwrf_ctrl_low_47' => 'Power Increasing Rate (%)',
	'pwrf_ctrl_high' => 'FREQ-WATT Open Loop Delay (s)',
	'volt_watt' => 'VOLT-WATT', 'avwatt_v1v2_high_47' => 'VOLT-WATT V1 (%)', 'avwatt_v1v2_high_03' => 'VOLT-WATT V2 (%)',
	'vwat_ctrl_low' => 'VOLT-WATT P2 (%)', 'vwat_ctrl_high' => 'VOLT-WATT Open Loop Delay (s)',
	'var_ctrl' => 'VAR-CTRL', 'var_mode' => 'VAR-CTRL mode',
	'pf' => '1. FIXED POWER FACTOR', 'fixed_var' => '2. FIXED VAR (%)',
	'wvar_p1q1_high' => 'WATT-PF P1 (%)', 'wvar_p1q1_low' => 'WATT-PF Q1',
	'wvar_p2q2_high' => 'WATT-PF P2 (%)', 'wvar_p2q2_low' => 'WATT-PF Q2',
	'wvar_p3q3_high' => 'WATT-PF P3 (%)', 'wvar_p3q3_low' => 'WATT-PF Q3',
	'ac_ref' => 'VOLT-VAR Vref (%)', 'vvar_offset_v1_high' => 'VOLT-VAR V1 (%)', 'vvar_offset_v2_high' => 'VOLT-VAR V2 (%)',
	'vvar_offset_v3_low' => 'VOLT-VAR V3 (%)', 'vvar_offset_v4_low' => 'VOLT-VAR V4 (%)',
	'vvar_q1q2_high' => 'VOLT-VAR Q1 (%)', 'vvar_q1q2_low' => 'VOLT-VAR Q2 (%)',
	'vvar_ctrl' => 'VOLT-VAR Open Loop Delay (s)',
];

/* JavaScript's Math.round() and toFixed() */
function js_round(float $x): float { return floor($x + 0.5); }
function js_fixed(float $x, int $d): string
{
	$s = number_format(round($x, $d), $d, '.', '');
	return $s === '-0' || preg_match('/^-0\.0+$/', $s) ? substr($s, 1) : $s;
}
/* conv16() then parseInt(.., 16): 0..255 as is, anything else "00" */
function js_conv16(float $v): int
{
	$v = (int)js_fixed($v, 0);
	$h = $v < 0 ? '-' . dechex(-$v) : dechex($v);
	if (strlen($h) == 1) $h = "0$h";
	if (strlen($h) > 2) $h = '00';
	return intval($h, 16);
}

/* the reply as bytes; null for no answer (proc() returns 0 then) */
function mi_bytes(?string $data): ?array
{
	if ($data === null)
		return null;
	$h = preg_split('/\s+/', trim($data));
	if (count($h) != 44)
		return null;
	$b = array_map('hexdec', $h);
	return ($b[0] == 0 && $b[1] == 0) ? null : $b;
}

/* model parameters from the module ID's first two hex digits (proc()) */
function mi_model(string $id, array $gains): array
{
	$m = ['gain' => 1.0, 'power_gain' => 2, 'mid' => 220, 'lv' => 0];
	switch (strtoupper(substr(str_pad($id, 8, '0', STR_PAD_LEFT), 0, 2))) {
	case '00': $m['gain'] = $gains[0]; break;
	case '10': $m['gain'] = $gains[1]; $m['power_gain'] = 4; break;
	case '20': $m['gain'] = $gains[2]; $m['mid'] = 120; $m['lv'] = 1; break;
	case '36': $m['mid'] = 270; /* falls through */
	case '12': case '15': case '30': case '32': case '33': case '37':
		$m['gain'] = $gains[3]; $m['power_gain'] = 4; break;
	case '80': case '86': $m['gain'] = $gains[3]; $m['power_gain'] = 20; break;
	case '31': $m['gain'] = $gains[4]; $m['mid'] = 120; $m['lv'] = 1; $m['power_gain'] = 4; break;
	case '11': $m['gain'] = $gains[5]; $m['mid'] = 120; $m['lv'] = 1; break;
	case '40': case '41': $m['gain'] = $gains[6]; $m['power_gain'] = 4; break;
	case '42': $m['gain'] = $gains[6]; $m['power_gain'] = 20; break;
	case '43': $m['gain'] = $gains[6]; $m['power_gain'] = 40; break;
	case '45': $m['gain'] = $gains[6]; $m['mid'] = 120; $m['lv'] = 1; $m['power_gain'] = 20; break;
	}
	return $m;
}

/* proc() of firmware 20230903's MI Setup, for one basic (0001) reply */
function mi_decode_basic(array $d, array $m, float $freq, float $reconn_offset): array
{
	$grid = $d[14] ?: 1;
	$r = ['grid' => $grid];
	if (!isset(MI_T['UP'][$grid - 1]))
		return $r + ['error' => "grid code $grid is unknown to MI Setup"];
	$g = $grid - 1;
	$adj = $m['mid'] - 220;
	$T = [];
	foreach (['UP', 'UPUP', 'LW', 'LWLW'] as $k)
		$T[$k] = array_map(fn($v) => $v + $adj, MI_T[$k]);
	foreach (['UP_G', 'UPUP_G', 'LW_G', 'LWLW_G'] as $k)
		$T[$k] = MI_T[$k];
	if ($m['lv']) {
		$T['UP'][2] = $m['mid']; $T['UP'][5] = $m['mid']; $T['UPUP'][5] = $m['mid'];
		$T['LW'][2] = $m['mid']; $T['LW'][5] = $m['mid'];
		$T['LWLW'][2] = $m['mid'] - 30; $T['LWLW'][5] = $m['mid'] - 30;
		$T['LW_G'][5] = 1;
	}
	$v = [
		'maxv' => $T['UPUP'][$g] + $d[18] / $T['UPUP_G'][$g],
		'maxv2' => $T['UPUP'][$g] + $d[20] / $T['UPUP_G'][$g],
		'maxv3' => $T['UP'][$g] + $d[22] / $T['UP_G'][$g],
		'smaxv' => $T['UP'][$g] + $d[38] / $T['UP_G'][$g],
		'minv' => $T['LWLW'][$g] - $d[19] / $T['LWLW_G'][$g],
		'minv2' => $T['LWLW'][$g] - $d[21] / $T['LWLW_G'][$g],
		'minv3' => $T['LW'][$g] - $d[23] / $T['LW_G'][$g],
		'sminv' => $T['LW'][$g] - $d[39] / $T['LW_G'][$g],
	];
	$v = array_map(fn($x) => (float)js_fixed($x, 1), $v);
	$t = [
		'mintv2' => js_fixed($d[31] / MI_T['TF_MINV2'][$g], 2),
		'mintv3' => js_fixed($d[30] / MI_T['TF_MINV3'][$g], 1),
		'maxtv2' => js_fixed($d[33] / MI_T['TF_MAXV2'][$g], 2),
		'maxtv3' => js_fixed($d[32] / MI_T['TF_MAXV3'][$g], 1),
		'mintf2' => js_fixed($d[35] / MI_T['TF_MINF2'][$g], 2),
		'mintf3' => js_fixed($d[34] / MI_T['TF_MINF3'][$g], 1),
		'maxtf2' => js_fixed($d[37] / MI_T['TF_MAXF2'][$g], 2),
		'maxtf3' => js_fixed($d[36] / MI_T['TF_MAXF3'][$g], 1),
	];
	$f = [
		'maxf3' => $freq + $d[28] / 10, 'smaxf' => $freq + $d[40] / 10, 'sminf' => $freq - $d[41] / 10,
		'maxf' => $freq + $d[24] / 10, 'minf' => $freq - $d[25] / 10,
		'maxf2' => $freq + $d[26] / 10, 'minf2' => $freq - $d[27] / 10, 'minf3' => $freq - $d[29] / 10,
	];
	$extra = [];
	$vs = null;	/* voltage strings, when a grid rounds them itself */
	switch ($grid) {
	case 1:
		$gn = $m['gain'];
		$mid = $m['mid'];
		$v = ['maxv' => $mid + $d[18] * 4 / $gn, 'minv' => $mid - $d[19] * 4 / $gn,
		      'maxv2' => $mid + $d[20] * 4 / $gn, 'minv2' => $mid - $d[21] * 4 / $gn,
		      'maxv3' => $mid + $d[22] * 4 / $gn, 'minv3' => $mid - $d[23] * 4 / $gn,
		      'smaxv' => $mid + $d[38] * 4 / $gn, 'sminv' => $mid - $d[39] * 4 / $gn];
		$f['maxf'] = $freq + $d[24] / 32; $f['minf'] = $freq - $d[25] / 32;
		$f['maxf2'] = $freq + $d[26] / 32; $f['minf2'] = $freq - $d[27] / 32;
		$f['minf3'] = $freq - $d[29] / 32;
		$vs = array_map(fn($x) => js_fixed(js_round($x * 2) / 2, 1), $v);
		break;
	case 2:
		unset($f['maxf2'], $f['minf2']);	/* proc() leaves them unset */
		$v['maxv3'] = 100 + $d[22] / 10;
		$v['minv3'] = 100 - $d[23] / 10;
		$extra['suppressv_100'] = js_fixed(js_round((100 + $d[27]) * 2) / 2, 0);
		$extra['suppressv_200'] = js_fixed(js_round((200 + $d[26]) * 2) / 2, 0);
		$extra['antiland'] = $d[37] == 1 ? '0' : (string)($d[37] - 15);
		$extra['unbalance'] = js_fixed(js_round(110 + $d[35] / 10), 0);
		$vs = array_map(fn($x) => js_fixed($x, 1), $v);
		$vs['maxv3'] = js_fixed(js_round($v['maxv3'] * 2) / 2, 1);
		$vs['minv3'] = js_fixed(js_round($v['minv3'] * 2) / 2, 1);
		break;
	}
	$vs ??= array_map(fn($x) => js_fixed($x, 1), $v);
	$fs = array_map(fn($x) => js_fixed(js_round($x * 20) / 20, 2), $f);
	if (in_array($grid, [6, 7, 8, 14], true))
		$rect = js_round($d[16] * 4.096 * 0.2) / 0.2;
	else
		$rect = js_round($d[16] * 2.048 * 0.4) / 0.4;
	$rect = (float)js_fixed($rect, 0) + $reconn_offset;
	return $r + $vs + $t + $fs + $extra + [
		'rect' => (string)$rect,
		'pwr' => js_fixed($d[17] * $m['power_gain'], 0),
		'fref' => js_fixed($d[15] / 32, 1),
		'version' => sprintf('%02x%02x', $d[8], $d[9]),
	];
}

/* firmware 20210303's MI Setup for grids it handled like grid 1 (6, 7 among
   them): 220 +- b*4/gain and f +- b/32, rounded as there */
function mi_decode_old(array $d, array $m, float $freq): array
{
	$gn = $m['gain'];
	$mid = $m['mid'];
	$v = ['smaxv' => $mid + $d[38] * 4 / $gn, 'maxv' => $mid + $d[18] * 4 / $gn,
	      'maxv2' => $mid + $d[20] * 4 / $gn, 'maxv3' => $mid + $d[22] * 4 / $gn,
	      'minv3' => $mid - $d[23] * 4 / $gn, 'minv2' => $mid - $d[21] * 4 / $gn,
	      'minv' => $mid - $d[19] * 4 / $gn, 'sminv' => $mid - $d[39] * 4 / $gn];
	$f = ['smaxf' => $freq + $d[40] / 32, 'maxf' => $freq + $d[24] / 32, 'maxf2' => $freq + $d[26] / 32,
	      'maxf3' => $freq + $d[28] / 32, 'minf3' => $freq - $d[29] / 32, 'minf2' => $freq - $d[27] / 32,
	      'minf' => $freq - $d[25] / 32, 'sminf' => $freq - $d[41] / 32];
	return array_map(fn($x) => js_fixed(js_round($x * 2) / 2, 1), $v) +
	       array_map(fn($x) => js_fixed(js_round($x * 20) / 20, 2), $f);
}

/* proc_extend(), for one extended (0002) reply */
function mi_decode_ext(array $d): array
{
	$yn = fn($b) => $b ? 'on' : 'off';
	$q = fn($b) => $b < 100 ? js_fixed(-$b * 0.01, 2) : js_fixed(2.0 - $b * 0.01, 2);
	$c = $d[14];
	$r = [
		'flag_gfdi' => $yn($c & 0x01), 'flag_disable' => $yn($c & 0x02), 'flag_lvrt' => $yn($c & 0x04),
		'flag_mppt' => $yn($c & 0x08), 'flag_island' => $yn($c & 0x10), 'flag_tenk' => $yn($c & 0x20),
		'vnorm' => (string)js_conv16($d[23] + 100),
		'ramp_delay_low' => js_fixed($d[24], 0),
		'temperature_max' => (string)js_conv16($d[15] + 40),
		'avwatt_v1v2_high_47' => (string)js_conv16((($d[25] >> 4) & 15) + 100),
		'avwatt_v1v2_high_03' => (string)js_conv16(($d[25] & 15) + 100),
		'volt_watt' => $yn($d[31] & 0x80),
		'vwat_ctrl_low' => (string)js_conv16(100 - $d[30]),
		'vwat_ctrl_high' => (string)js_conv16($d[31] & 0x7f),
		'db_offset_low' => js_fixed($d[32] * 0.004, 3),
		'db_offset_high' => js_fixed($d[33] * 0.004, 3),
		'pwrf_ctrl_low_47' => (string)js_conv16(($d[34] >> 4) & 15),
		'pwrf_ctrl_low_03' => (string)js_conv16($d[34] & 15),
		'freq_watt' => $yn($d[35] & 0x80),
		'pwrf_ctrl_high' => js_fixed(($d[35] & 0x7f) * 0.1, 1),
		'var_ctrl' => $yn($d[17] & 0x80),
	];
	if ($d[17] & 0x80) {
		$mode = ($d[17] >> 4) & 7;
		$r['var_mode'] = [1 => '1. FIXED POWER FACTOR', 2 => '2. FIXED VAR', 3 => '3. WATT-PF',
				  4 => '4. VOLT-VAR'][$mode] ?? "($mode)";
		switch ($mode) {
		case 1: $r['pf'] = $q($d[16]); break;
		case 2: $r['fixed_var'] = js_fixed($d[16] - 100, 0); break;
		case 3:
			$r += ['wvar_p1q1_high' => (string)$d[37], 'wvar_p1q1_low' => $q($d[36]),
			       'wvar_p2q2_high' => (string)$d[39], 'wvar_p2q2_low' => $q($d[38]),
			       'wvar_p3q3_high' => (string)$d[41], 'wvar_p3q3_low' => $q($d[40])];
			break;
		case 4:
			$r += ['ac_ref' => (string)js_conv16(100 + $d[22] - 16),
			       'vvar_offset_v1_high' => (string)js_conv16(100 + $d[19] - 16),
			       'vvar_offset_v2_high' => (string)js_conv16(100 + $d[18] - 16),
			       'vvar_offset_v3_low' => (string)js_conv16(100 + $d[21] - 16),
			       'vvar_offset_v4_low' => (string)js_conv16(100 + $d[20] - 16),
			       'vvar_q1q2_low' => (string)$d[26], 'vvar_q1q2_high' => (string)$d[27],
			       'vvar_ctrl' => (string)($d[29] * 256 + $d[28])];
			break;
		}
	}
	return $r;
}

/* ---- the files ----------------------------------------------------------- */

function mi_csv_line(array $f): string
{
	return implode(',', array_map(function ($v) {
		$v = (string)$v;
		return preg_match('/[",\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
	}, $f)) . "\r\n";
}

/* frequency limits are offsets from the nominal frequency; unknown, they
   are shown as offsets */
function mi_label(array $meta, string $n): string
{
	return $meta['freq'] === null ? str_replace(' (Hz)', ' (Hz from nominal)', $n) : $n;
}

function mi_report_csv(array $meta, array $mods): string
{
	$head = ['Module', 'ID', 'Basic read', 'Extended read', 'Version'];
	foreach (MI_BASIC as $k => $n)
		$head[] = mi_label($meta, $n);
	foreach (MI_OLD as $k => $n)
		$head[] = mi_label($meta, $n) . ' [2021 encoding]';
	foreach (MI_EXT as $k => $n)
		$head[] = $n;
	$head[] = 'Raw basic (0001)';
	$head[] = 'Raw extended (0002)';
	$out = mi_csv_line($head);
	foreach ($mods as $m) {
		$row = [$m['n'], $m['id'], $m['basic_state'], $m['ext_state'], $m['basic']['version'] ?? ''];
		foreach (MI_BASIC as $k => $n)
			$row[] = $k === 'grid' && isset($m['basic']['grid'])
				? $m['basic']['grid'] . ' ' . (MI_GRIDS[$m['basic']['grid']] ?? '')
				: ($m['basic'][$k] ?? '');
		foreach (MI_OLD as $k => $n)
			$row[] = $m['old'][$k] ?? '';
		foreach (MI_EXT as $k => $n)
			$row[] = $m['ext'][$k] ?? '';
		$row[] = $m['raw_basic'] ?? '';
		$row[] = $m['raw_ext'] ?? '';
		$out .= mi_csv_line($row);
	}
	return $out;
}

/* a plain PDF: Courier text on landscape Letter pages */
class MiPdf {
	private $pages = [];
	private $lines = [];
	const SIZE = 7.0, LEAD = 9.0, TOP = 580.0, LEFT = 30.0, ROWS = 61;

	public function line(string $s = '', bool $bold = false): void
	{
		if (count($this->lines) >= self::ROWS)
			$this->page();
		$this->lines[] = [$s, $bold];
	}

	public function page(): void
	{
		if ($this->lines)
			$this->pages[] = $this->lines;
		$this->lines = [];
	}

	public function room(int $n): void
	{
		if (count($this->lines) + $n > self::ROWS)
			$this->page();
	}

	private static function esc(string $s): string
	{
		$s = preg_replace('/[^\x20-\x7e]/', '?', $s);
		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
	}

	public function output(string $title, string $foot): string
	{
		$this->page();
		$objs = [];
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
		$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>';
		$objs[5] = '<< /Title (' . self::esc($title) . ') /Producer (zlg-admin) >>';
		$kids = [];
		$n = 6;
		$total = count($this->pages);
		foreach ($this->pages as $i => $lines) {
			$s = "BT\n";
			$y = self::TOP;
			foreach ($lines as [$text, $bold]) {
				$s .= sprintf("/%s %.1f Tf 1 0 0 1 %.1f %.1f Tm (%s) Tj\n", $bold ? 'F2' : 'F1',
					self::SIZE, self::LEFT, $y, self::esc($text));
				$y -= self::LEAD;
			}
			$s .= sprintf("/F1 6.0 Tf 1 0 0 1 %.1f 18.0 Tm (%s) Tj\nET\n", self::LEFT,
				self::esc(sprintf('%s    page %d of %d', $foot, $i + 1, $total)));
			$objs[$n] = '<< /Length ' . strlen($s) . " >>\nstream\n$s\nendstream";
			$objs[$n + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] ' .
				'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $n . ' 0 R >>';
			$kids[] = ($n + 1) . ' 0 R';
			$n += 2;
		}
		$objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
		ksort($objs);
		$pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
		$off = [];
		foreach ($objs as $i => $o) {
			$off[$i] = strlen($pdf);
			$pdf .= "$i 0 obj\n$o\nendobj\n";
		}
		$x = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
		foreach ($off as $o)
			$pdf .= sprintf("%010d 00000 n \n", $o);
		$pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$x\n%%EOF\n";
		return $pdf;
	}
}

/* modules with identical settings, as "1-2, 5" */
function mi_ranges(array $ns): string
{
	sort($ns);
	$out = [];
	for ($i = 0; $i < count($ns); $i++) {
		$a = $ns[$i];
		while ($i + 1 < count($ns) && $ns[$i + 1] == $ns[$i] + 1)
			$i++;
		$out[] = $a == $ns[$i] ? "$a" : "$a-{$ns[$i]}";
	}
	return implode(', ', $out);
}

function mi_table(MiPdf $p, string $title, array $fields, array $groups, string $key, array $meta): void
{
	$fields = array_map(fn($n) => mi_label($meta, $n), $fields);
	$cols = array_slice($groups, 0, 4);	/* fits the page width */
	$w = 31;
	$p->room(count($fields) + 4);
	$p->line($title, true);
	$hdr = str_pad('Setting', $w);
	foreach ($cols as $g)
		$hdr .= str_pad('Modules ' . mi_ranges($g['n']), 26);
	$p->line($hdr, true);
	foreach ($fields as $k => $name) {
		$l = str_pad($name, $w);
		$any = false;
		foreach ($cols as $g) {
			$v = $g[$key][$k] ?? '';
			if ($k === 'grid' && $v !== '')
				$v .= ' ' . (MI_GRIDS[$v] ?? '');
			$any = $any || $v !== '';
			$l .= str_pad((string)$v, 26);
		}
		if ($any)
			$p->line(rtrim($l));
	}
	$p->line();
}

function mi_report_pdf(array $meta, array $mods): string
{
	$p = new MiPdf();
	$p->line('Inverter grid settings report', true);
	$p->line();
	foreach ([
		'Generated' => $meta['time'],
		'Gateway' => $meta['host'] . ', NEP firmware ' . $meta['firmware'],
		'Modules' => count($mods) . ' configured; read: ' . $meta['read_ok'] . ' basic, ' . $meta['ext_ok'] . ' extended',
		'Grid frequency' => $meta['freq'] === null
			? 'unknown (no recent readings): frequency limits are shown as offsets from nominal'
			: $meta['freq'] . ' Hz nominal, ' . $meta['freq_src'],
		'Decoding' => "as firmware 20230903's MI Setup page shows the settings",
	] as $k => $v)
		$p->line(str_pad("$k:", 17) . $v);
	$p->line();
	foreach ([
		'For grid codes 6 and 7 (IEEE 1547A), firmware 20210303\'s MI Setup used another encoding',
		'(220 +- b x 4 / AC_V_Gain volts, frequency +- b / 32 Hz); which one the inverters use is not',
		'confirmed, so those modules also show the voltages and frequencies in that encoding.',
		'Raw replies are at the end and in the CSV file.',
	] as $l)
		$p->line($l);
	$p->line();

	/* group modules with the same settings */
	$groups = [];
	foreach ($mods as $m) {
		if (!$m['basic'])
			continue;
		$sig = json_encode([$m['basic'], $m['ext']]);
		$groups[$sig]['n'][] = $m['n'];
		$groups[$sig]['basic'] = $m['basic'];
		$groups[$sig]['old'] = $m['old'];
		$groups[$sig]['ext'] = $m['ext'];
	}
	$groups = array_values($groups);
	$p->line('Modules', true);
	$p->line(str_pad('Module', 8) . str_pad('ID', 11) . str_pad('Version', 9) . str_pad('Basic', 12) .
		str_pad('Extended', 12) . 'Settings', true);
	foreach ($mods as $m) {
		$gi = '';
		foreach ($groups as $i => $g)
			if (in_array($m['n'], $g['n'], true))
				$gi = 'as modules ' . mi_ranges($g['n']);
		$p->line(str_pad((string)$m['n'], 8) . str_pad($m['id'], 11) . str_pad($m['basic']['version'] ?? '', 9) .
			str_pad($m['basic_state'], 12) . str_pad($m['ext_state'], 12) . $gi);
	}
	$p->line();
	for ($i = 0; $i < count($groups); $i += 4) {
		$set = array_slice($groups, $i, 4);
		mi_table($p, 'Basic settings (MI Setup 20230903)', MI_BASIC, $set, 'basic', $meta);
		$old = array_values(array_filter($set, fn($g) => in_array((int)($g['basic']['grid'] ?? 0), [6, 7], true)));
		if ($old)
			mi_table($p, 'Grid 6/7 voltages and frequencies in the 2021 encoding (not confirmed)', MI_OLD, $old, 'old', $meta);
		$ext = array_values(array_filter($set, fn($g) => $g['ext']));
		if ($ext)
			mi_table($p, 'Extended settings (IEEE 1547 / Rule 21 functions)', MI_EXT, $ext, 'ext', $meta);
	}
	$p->room(6);
	$p->line('Raw replies', true);
	foreach ($mods as $m) {
		$p->line(sprintf('%-3d %-8s 0001 %s', $m['n'], $m['id'], $m['raw_basic'] ?? '(' . $m['basic_state'] . ')'));
		$p->line(sprintf('%-3s %-8s 0002 %s', '', '', $m['raw_ext'] ?? '(' . $m['ext_state'] . ')'));
	}
	return $p->output('Inverter grid settings report', 'Grid report ' . $meta['host'] . ' ' . $meta['time']);
}
