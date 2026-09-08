<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 관리자 - 전체 투표 현황
 *
 * 이 프로젝트에는 로그인 체계가 없다. 그래서 config 의 admin_password 를
 * 유일한 관문으로 쓴다. **비밀번호를 설정하지 않으면 화면 자체가 404 다** —
 * 값을 안 넣었는데 관리자 화면이 열려 있으면 링크를 아는 누구나 모든
 * 투표 현황을 볼 수 있게 되므로, 기본을 "꺼짐" 으로 둔다.
 */
class Admin extends MY_Controller {

	/** 세션 키 */
	const SESS = 'ds_admin_ok';

	public function __construct()
	{
		parent::__construct();

		$this->load->model('vote_model');
		$this->output->set_header('Cache-Control: no-store');
		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
	}

	/** 설정된 관리자 비밀번호. 없으면 '' */
	protected function admin_password()
	{
		return (string) $this->config->item('admin_password', 'dinnerspot');
	}

	/** 비밀번호가 설정되지 않았으면 관리자 기능 전체를 없는 것처럼 취급한다 */
	protected function require_enabled()
	{
		if ($this->admin_password() === '')
		{
			show_404();

			return FALSE;
		}

		return TRUE;
	}

	protected function is_logged_in()
	{
		return (bool) $this->session->userdata(self::SESS);
	}

	// =========================================================

	/** 전체 현황 */
	public function index()
	{
		if ( ! $this->require_enabled())
		{
			return;
		}

		if ( ! $this->is_logged_in())
		{
			redirect('admin/login');

			return;
		}

		$rooms = $this->vote_model->admin_rooms(array(
			'status' => $this->input->get('status', TRUE),
			'q'      => $this->input->get('q', TRUE),
			'limit'  => 100,
		));

		$this->render('admin/index', array(
			'summary' => $this->vote_model->admin_summary(),
			'rooms'   => $rooms,
			'status'  => (string) $this->input->get('status', TRUE),
			'q'       => (string) $this->input->get('q', TRUE),
		), array(
			'title'      => '전체 투표 현황 · DINNERSPOT',
			'nav'        => '',
			'body_class' => 'page-admin',
		));
	}

	/** 방 하나 상세 - 누가 무엇을 골랐는지, 누가 아직 안 했는지 */
	public function room($code)
	{
		if ( ! $this->require_enabled())
		{
			return;
		}

		if ( ! $this->is_logged_in())
		{
			redirect('admin/login');

			return;
		}

		$room = $this->vote_model->room_by_code($code);

		if ( ! $room)
		{
			show_404();

			return;
		}

		$this->render('admin/room', array(
			'state'    => $this->vote_model->state($room),
			'code'     => $room['code'],
			'invites'  => ($room['mode'] === 'invite')
				? $this->vote_model->invites($room['id'])
				: array(),
			'room_url' => base_url('vote/r/' . $room['code']),
			'created'  => $room['created_at'],
		), array(
			'title'      => $room['title'] . ' · 관리자',
			'nav'        => '',
			'body_class' => 'page-admin-room',
		));
	}

	/** 로그인 */
	public function login()
	{
		if ( ! $this->require_enabled())
		{
			return;
		}

		if ($this->is_logged_in())
		{
			redirect('admin');

			return;
		}

		$error = '';

		if ($this->input->method(TRUE) === 'POST')
		{
			$pw = (string) $this->input->post('password', FALSE);

			// 타이밍 공격 방지를 위해 hash_equals 로 비교한다
			if (hash_equals($this->admin_password(), $pw))
			{
				$this->session->set_userdata(self::SESS, TRUE);
				redirect('admin');

				return;
			}

			$error = '비밀번호가 맞지 않습니다.';
			log_message('error', 'Admin login failed from ' . $this->input->ip_address());
		}

		$this->render('admin/login', array('error' => $error), array(
			'title'      => '관리자 · DINNERSPOT',
			'nav'        => '',
			'body_class' => 'page-admin-login',
		));
	}

	public function logout()
	{
		$this->session->unset_userdata(self::SESS);
		redirect('admin/login');
	}
}
