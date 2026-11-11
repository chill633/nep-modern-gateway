<?php defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * The Weather page's source switch (Open-Meteo or a local weewx station).
 * The page itself is NEP's Info page (info/index), which shows the panel
 * (views/zlg/weather_source.php); zlg-admin does the work. Part of this
 * kit, not NEP's code.
 */
#[\AllowDynamicProperties]
class Weather extends CI_Controller {

	public function __construct()
	{
		parent::__construct();
		$this->load->library('zlg_admin');
	}

	public function index()
	{
		redirect(base_url() . 'info/index');
	}

	public function save()
	{
		if (!$this->zlg_admin->csrf_ok())
			return $this->zlg_admin->json(['ok' => false, 'error' => 'the page had expired; reload it'], 403);
		if (!$this->zlg_admin->logged_in())
			return $this->zlg_admin->json(['ok' => false, 'error' => 'please log in first'], 403);
		$this->zlg_admin->json($this->zlg_admin->call('weather.set', [
			'source' => (string)$this->input->post('source'),
			'url' => trim((string)$this->input->post('url')),
		], 45));
	}
}
