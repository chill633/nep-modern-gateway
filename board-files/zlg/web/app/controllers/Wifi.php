<?php defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * Wi-Fi settings page (NEP's /wifi, on Debian's wpa_supplicant and
 * systemd-networkd). zlg-admin does the work; a change that doesn't connect
 * within 90 seconds is undone. Part of this kit, not NEP's code.
 */
#[\AllowDynamicProperties]
class Wifi extends CI_Controller {

	public function __construct()
	{
		parent::__construct();
		$this->load->library('zlg_admin');
		$this->load->helper('language');
	}

	public function index()
	{
		$st = $this->zlg_admin->call('wifi.status');
		$data = [
			'page' => 'wifi',
			'st' => $st,
			'logged_in' => $this->zlg_admin->logged_in(),
			'csrf' => $this->zlg_admin->csrf(),
			'msg' => $this->session->flashdata('zlg_msg'),
		];
		$this->load->view('header_view');
		$this->load->view('zlg/style');
		$this->load->view('zlg/account', $data);
		$this->load->view('zlg/wifi_view', $data);
		$this->load->view('footer_view');
	}

	private function guard(): bool
	{
		if (!$this->zlg_admin->csrf_ok()) {
			$this->zlg_admin->json(['ok' => false, 'error' => 'the page had expired; reload it'], 403);
			return false;
		}
		if (!$this->zlg_admin->logged_in()) {
			$this->zlg_admin->json(['ok' => false, 'error' => 'please log in first'], 403);
			return false;
		}
		return true;
	}

	public function scan()
	{
		if ($this->guard())
			$this->zlg_admin->json($this->zlg_admin->call('wifi.scan', [], 60));
	}

	public function save()
	{
		if (!$this->guard())
			return;
		$in = $this->input;
		$this->zlg_admin->json($this->zlg_admin->call('wifi.apply', [
			'ssid' => (string)$in->post('ssid'),
			'security' => (string)$in->post('security'),
			'passphrase' => (string)$in->post('passphrase'),
			'ipv4' => (string)$in->post('ipv4'),
			'address' => trim((string)$in->post('address')),
			'gateway' => trim((string)$in->post('gateway')),
			'dns' => trim((string)$in->post('dns')),
		]));
	}

	/* polled while a change is tested (no login: it shows no secrets) */
	public function status()
	{
		$st = $this->zlg_admin->call('wifi.status');
		$this->zlg_admin->json(['ok' => !empty($st['ok']), 'apply' => $st['apply'] ?? [],
					'link' => $st['link'] ?? null]);
	}
}
