<?php
/*
 * pre_system hook: log uncaught exceptions (a PHP 8 TypeError in NEP's code,
 * for example) to PHP's error log, which php-fpm passes to the journal
 * (journalctl -u zlg-web-fpm). CodeIgniter's own handler only logs to files
 * and, with display_errors off, the page is just blank.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Zlg_log_hook
{
	public function install()
	{
		$prev = set_exception_handler(null);
		set_exception_handler(function ($e) use ($prev) {
			error_log(sprintf('zlg-web: %s: %s in %s:%d (%s)',
				get_class($e), $e->getMessage(), $e->getFile(),
				$e->getLine(), $_SERVER['REQUEST_URI'] ?? ''));
			if ($prev)
				$prev($e);
		});
	}
}
