<?php
/*
 * PHP 5 behaviour for the few built-ins NEP's code calls on values that can
 * be null or false (a missing data file, json_decode of an empty file). PHP 5
 * returned a warning; PHP 8 throws a TypeError and the whole page fails.
 * zlg-web-port rewrites NEP's calls of these functions to the zlg_ versions.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('zlg_count')) {
	function zlg_count($v, $mode = COUNT_NORMAL)
	{
		if (is_array($v) || $v instanceof Countable)
			return count($v, $mode);
		return $v === null ? 0 : 1;	/* PHP 5: count(scalar) == 1 */
	}

	function zlg_sort(&$a, $flags = SORT_REGULAR)
	{
		return is_array($a) ? sort($a, $flags) : false;
	}

	function zlg_rsort(&$a, $flags = SORT_REGULAR)
	{
		return is_array($a) ? rsort($a, $flags) : false;
	}

	function zlg_implode($a, $b = null)
	{
		/* PHP 5 accepted both argument orders and a null array */
		if (is_array($a))
			[$a, $b] = [$b ?? '', $a];
		return is_array($b) ? implode((string)$a, $b) : '';
	}

	function zlg_in_array($needle, $haystack, $strict = false)
	{
		return is_array($haystack) && in_array($needle, $haystack, $strict);
	}

	function zlg_array_sum($a)
	{
		return is_array($a) ? array_sum(array_map('zlg_num', $a)) : 0;
	}

	/* live values: gateway-plc's shared memory is missing while it
	   (re)starts; read zeros instead of failing the page */
	function zlg_shmop_read($id, $offset, $size)
	{
		return $id ? shmop_read($id, $offset, $size) : str_repeat("\0", $size);
	}

	function zlg_shmop_close($id)
	{
		/* a no-op since PHP 8.0 */
	}

	/* "" or "n/a" in arithmetic: 0, as PHP 5 did (PHP 8 throws) */
	function zlg_num($v)
	{
		return is_numeric($v) ? $v + 0 : (float)$v;
	}
}
