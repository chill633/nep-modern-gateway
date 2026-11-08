<?php defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * Zlg_admin: the settings pages' link to zlg-admin (root, outside /srv/zlg)
 * over /m6g2c/run/admin.sock, plus the pages' login and form tokens.
 * Part of this kit (board-files/zlg/web/app), not NEP's code.
 */
#[\AllowDynamicProperties]
class Zlg_admin {

	const SOCK = '/m6g2c/run/admin.sock';

	private $CI;
	private $valid = null;

	public function __construct()
	{
		$this->CI =& get_instance();
	}

	/* one request to zlg-admin; ['ok' => false, 'error' => ...] on failure */
	public function call(string $op, array $args = [], int $timeout = 30): array
	{
		$s = @stream_socket_client('unix://' . self::SOCK, $errno, $errstr, 5);
		if (!$s)
			return ['ok' => false, 'error' => "the gateway's settings service isn't running"];
		stream_set_timeout($s, $timeout);
		$token = $this->CI->session->userdata('zlg_admin_token');
		if ($token && !isset($args['token']))
			$args['token'] = $token;
		fwrite($s, json_encode(['op' => $op] + $args) . "\n");
		$line = fgets($s, 1 << 20);
		fclose($s);
		$r = is_string($line) ? json_decode($line, true) : null;
		return is_array($r) ? $r : ['ok' => false, 'error' => 'no answer from the settings service'];
	}

	public function logged_in(): bool
	{
		if ($this->valid === null) {
			$this->valid = false;
			if ($this->CI->session->userdata('zlg_admin_token')) {
				$r = $this->call('auth.check');
				$this->valid = !empty($r['valid']);
				if (!$this->valid)
					$this->CI->session->unset_userdata('zlg_admin_token');
			}
		}
		return $this->valid;
	}

	public function login(string $password): array
	{
		$r = $this->call('auth.login', ['password' => $password, 'token' => '']);
		if (!empty($r['ok'])) {
			$this->CI->session->sess_regenerate(true);
			$this->CI->session->set_userdata('zlg_admin_token', $r['token']);
			$this->valid = true;
		}
		return $r;
	}

	public function logout(): void
	{
		if ($this->CI->session->userdata('zlg_admin_token'))
			$this->call('auth.logout');
		$this->CI->session->unset_userdata('zlg_admin_token');
		$this->valid = false;
	}

	/* a per-session token every form and AJAX POST carries */
	public function csrf(): string
	{
		$t = $this->CI->session->userdata('zlg_csrf');
		if (!is_string($t) || strlen($t) !== 64) {
			$t = bin2hex(random_bytes(32));
			$this->CI->session->set_userdata('zlg_csrf', $t);
		}
		return $t;
	}

	public function csrf_ok(): bool
	{
		$sent = $this->CI->input->post('csrf') ?? $this->CI->input->get_request_header('X-Csrf-Token');
		$t = $this->CI->session->userdata('zlg_csrf');
		return $this->CI->input->method() === 'post' && is_string($sent) &&
		       is_string($t) && hash_equals($t, $sent);
	}

	/* JSON replies for the pages' AJAX calls */
	public function json(array $r, int $status = 200): void
	{
		$this->CI->output->set_status_header($status)
			->set_header('Cache-Control: no-store')
			->set_content_type('application/json', 'utf-8')
			->set_output(json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE));
	}

	/* the pages that have a login box (Access::login redirects back to one) */
	public static function page_ok(string $p): bool
	{
		return in_array($p, ['wifi', 'modules', 'advanced'], true);
	}
}
