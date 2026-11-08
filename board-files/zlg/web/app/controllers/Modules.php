<?php defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * Modules page (NEP's /module): the inverter and meter IDs the gateway
 * polls (plc_id.conf). Saving restarts the gateway software, as NEP's own
 * "reboot" does. zlg-admin does the work. Part of this kit, not NEP's code.
 */
#[\AllowDynamicProperties]
class Modules extends CI_Controller {

	public function __construct()
	{
		parent::__construct();
		$this->load->library('zlg_admin');
		$this->load->helper('language');
	}

	public function index()
	{
		$data = [
			'page' => 'modules',
			'mods' => $this->zlg_admin->call('modules.get'),
			'logged_in' => $this->zlg_admin->logged_in(),
			'csrf' => $this->zlg_admin->csrf(),
			'msg' => $this->session->flashdata('zlg_msg'),
		];
		$this->load->view('header_view');
		$this->load->view('zlg/style');
		$this->load->view('zlg/account', $data);
		$this->load->view('zlg/modules_view', $data);
		$this->load->view('footer_view');
	}

	public function save()
	{
		if (!$this->zlg_admin->csrf_ok())
			return $this->zlg_admin->json(['ok' => false, 'error' => 'the page had expired; reload it'], 403);
		if (!$this->zlg_admin->logged_in())
			return $this->zlg_admin->json(['ok' => false, 'error' => 'please log in first'], 403);
		$ids = $this->input->post('ids');
		$this->zlg_admin->json($this->zlg_admin->call('modules.set',
			['ids' => is_array($ids) ? array_map('strval', $ids) : []]));
	}
}
