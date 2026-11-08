<?php defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * Login for the settings pages (Wi-Fi, Modules, Advanced): viewing is open,
 * changes need the settings password. Part of this kit, not NEP's code.
 */
#[\AllowDynamicProperties]
class Access extends CI_Controller {

	public function __construct()
	{
		parent::__construct();
		$this->load->library('zlg_admin');
		$this->load->helper('language');
	}

	private function back(): string
	{
		$p = (string)$this->input->post('page');
		return Zlg_admin::page_ok($p) ? $p : 'wifi';
	}

	private function flash(string $msg, bool $ok): void
	{
		$this->session->set_flashdata('zlg_msg', ['text' => $msg, 'ok' => $ok]);
	}

	public function login()
	{
		$page = $this->back();
		if (!$this->zlg_admin->csrf_ok()) {
			$this->flash('The form had expired; please try again.', false);
		} else {
			$r = $this->zlg_admin->login((string)$this->input->post('password'));
			if (empty($r['ok']))
				$this->flash('Login failed: ' . ($r['error'] ?? 'unknown error') . '.', false);
		}
		redirect(base_url() . $page);
	}

	public function logout()
	{
		$page = $this->back();
		if ($this->zlg_admin->csrf_ok())
			$this->zlg_admin->logout();
		redirect(base_url() . $page);
	}

	public function password()
	{
		$page = $this->back();
		if (!$this->zlg_admin->csrf_ok() || !$this->zlg_admin->logged_in()) {
			$this->flash('Please log in first.', false);
		} elseif ((string)$this->input->post('new') !== (string)$this->input->post('new2')) {
			$this->flash('The two new passwords differ.', false);
		} else {
			$r = $this->zlg_admin->call('auth.passwd', [
				'old' => (string)$this->input->post('old'),
				'new' => (string)$this->input->post('new')]);
			$this->flash(!empty($r['ok']) ? 'The settings password was changed.'
				: 'Not changed: ' . ($r['error'] ?? 'unknown error') . '.', !empty($r['ok']));
		}
		redirect(base_url() . $page);
	}
}
