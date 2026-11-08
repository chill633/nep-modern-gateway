<?php defined('BASEPATH') OR exit('No direct script access allowed');
/*
 * Advanced: NEP's MI Setup page (inverter grid settings), from the 20230903
 * firmware, unchanged in what it reads and writes. zlg-web-port builds its
 * HTML/JS into /misetup-ui and points it here instead of NEP's proxy.cgi
 * and PHP files; zlg-admin does the work (setup mode, plc_set). Viewing is
 * open; writing needs the settings password. Part of this kit.
 */
#[\AllowDynamicProperties]
class Advanced extends CI_Controller {

	const MISETUP = '/root/Gateway/misetup';

	public function __construct()
	{
		parent::__construct();
		$this->load->library('zlg_admin');
		$this->load->helper('language');
	}

	public function index()
	{
		$data = [
			'page' => 'advanced',
			'logged_in' => $this->zlg_admin->logged_in(),
			'csrf' => $this->zlg_admin->csrf(),
			'msg' => $this->session->flashdata('zlg_msg'),
			'built' => is_file(APPPATH . 'views/zlg/misetup_ui.php'),
		];
		$this->load->view('header_view');
		$this->load->view('zlg/style');
		$this->load->view('zlg/account', $data);
		$this->load->view('zlg/advanced_view', $data);
		$this->load->view('footer_view');
	}

	/* NEP's page itself, shown in a frame on index() */
	public function ui()
	{
		if (!is_file(APPPATH . 'views/zlg/misetup_ui.php'))
			show_404();
		$this->output->set_header('Cache-Control: no-store');
		$this->load->view('zlg/misetup_ui', [
			'csrf' => $this->zlg_admin->csrf(),
			'logged_in' => $this->zlg_admin->logged_in(),
		]);
	}

	private function text($r): void
	{
		/* NEP's code parses the text itself (eval / string compare) */
		$this->output->set_header('Cache-Control: no-store')
			->set_content_type('text/plain', 'utf-8')
			->set_output(is_string($r) ? $r : json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE));
	}

	private function call(string $op, array $args = [], int $timeout = 60): void
	{
		$r = $this->zlg_admin->call($op, $args, $timeout);
		$this->text(!empty($r['ok']) ? $r['reply']
			: ['type' => '0', 'data' => ucfirst($r['error'] ?? 'unknown error') . '.']);
	}

	/* NEP's proxy.cgi: GET = gateway info; POST mod 1 read, 2 write,
	   3 find gateway-plc, 4 setup mode, 5 leave it (NEP: reboot), 6 IDs */
	public function proxy()
	{
		if ($this->input->method() !== 'post')
			return $this->call('mi.init');
		if (!$this->zlg_admin->csrf_ok())
			return $this->text(['type' => '0', 'data' => 'The page had expired; reload it.']);
		$in = $this->input;
		switch ((string)$in->post('mod')) {
		case '1':
		case '2':
			return $this->call($in->post('mod') === '1' ? 'mi.read' : 'mi.write',
				['ind' => (string)$in->post('ind'), 'cmd' => (string)$in->post('cmd')]);
		case '3':
			return $this->call('mi.find');
		case '4':
			return $this->call('mi.setup');
		case '5':
			return $this->call('mi.leave');
		case '6':
			return $this->call('mi.list');
		}
		$this->text(['type' => '0', 'data' => 'Invalid parameter.']);
	}

	private function confs(string $dir): array
	{
		$l = [];
		foreach (glob(self::MISETUP . "/$dir/*.conf") ?: [] as $f)
			if (is_file($f))
				$l[] = basename($f, '.conf');
		sort($l);
		return $l;
	}

	/* NEP's get_file.php: the profile lists and the page's language */
	public function files()
	{
		$eng = @parse_ini_file('/root/Gateway/engineer.cfg', false, INI_SCANNER_RAW) ?: [];
		$lang = ['0' => 'en', '1' => 'ja', '2' => 'zh'][$eng['language'] ?? '0'] ?? 'en';
		$all = json_decode((string)@file_get_contents(self::MISETUP . '/language.json'), true) ?: [];
		$words = $all[$lang] ?? ($all['en'] ?? []);
		/* "Reboot Gateway" leaves setup mode here (gateway-plc starts) */
		$words['btnreboot'] = ['ja' => '設定モード終了', 'zh' => '退出设置模式'][$lang] ?? 'Leave Setup Mode';
		$this->zlg_admin->json(['selectconfig' => $this->confs('setting'),
			'customconfig' => $this->confs('setting/custom'),
			'language' => $words]);
	}

	/* NEP's get_setting.php: one profile's values */
	public function setting()
	{
		$dir = $this->input->get('type') == 2 ? 'setting/custom' : 'setting';
		$name = (string)$this->input->get('name');
		if (!in_array($name, $this->confs($dir), true))
			return $this->zlg_admin->json(['data' => '', 'status' => 0]);
		$data = [];
		foreach (explode("\n", (string)file_get_contents(self::MISETUP . "/$dir/$name.conf")) as $row) {
			$kv = explode('=', $row);
			if (count($kv) > 1)
				$data[$kv[0]] = $kv[1];
		}
		$this->zlg_admin->json(['data' => $data, 'status' => 1]);
	}

	/* the grid report: read every module, then a .tgz with a CSV and a PDF */
	public function report_start()
	{
		if (!$this->zlg_admin->csrf_ok())
			return $this->zlg_admin->json(['ok' => false, 'error' => 'the page had expired; reload it'], 403);
		$this->zlg_admin->json($this->zlg_admin->call('mi.report_start',
			['freq' => (string)$this->input->post('freq')]));
	}

	public function report_status()
	{
		$this->zlg_admin->json($this->zlg_admin->call('mi.report_status'));
	}

	public function report_download()
	{
		$r = $this->zlg_admin->call('mi.report_get', ['id' => (string)$this->input->get('id')]);
		if (empty($r['ok']) || !preg_match('/^[A-Za-z0-9._-]+\.tgz$/', $r['name'] ?? ''))
			show_404();
		$this->output->set_header('Cache-Control: no-store')
			->set_header('Content-Disposition: attachment; filename="' . $r['name'] . '"')
			->set_content_type('application/gzip')
			->set_output(base64_decode($r['data']));
	}

	/* NEP's save_as_custom_conf.php and set_para_profile.php */
	public function savecustom()
	{
		if (!$this->zlg_admin->csrf_ok())
			return $this->text('no');
		$data = $this->input->post('data');
		$r = $this->zlg_admin->call('mi.save_custom', ['name' => (string)$this->input->post('saveAsName'),
			'data' => is_array($data) ? $data : []]);
		$this->text(!empty($r['ok']) ? 'ok' : 'no: ' . ($r['error'] ?? 'unknown error'));
	}

	public function setprofile()
	{
		if (!$this->zlg_admin->csrf_ok())
			return $this->text('no');
		$r = $this->zlg_admin->call('mi.set_profile', ['name' => (string)$this->input->post('name')]);
		$this->text(!empty($r['ok']) ? 'ok' : 'no');
	}
}
