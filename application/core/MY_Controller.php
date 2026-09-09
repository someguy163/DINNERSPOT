<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 공통 베이스 컨트롤러
 */
class MY_Controller extends CI_Controller {

	/** 레이아웃에 넘길 기본값 */
	protected $layout_data = array(
		'title'      => 'DINNERSPOT · 회식장소 추천',
		'desc'       => '인원, 예산, 목적만 고르면 회식 장소를 골라주고 팀 투표까지.',
		'body_class' => '',
		'nav'        => 'home',
	);

	public function __construct()
	{
		parent::__construct();

		$this->config->load('dinnerspot', TRUE, TRUE);
	}

	/**
	 * 레이아웃 + 뷰 렌더
	 */
	protected function render($view, array $data = array(), array $layout = array())
	{
		$layout = array_merge($this->layout_data, $layout);

		// 헤더에 관리자 메뉴를 띄울지. 비밀번호가 설정되지 않으면 관리자 화면은
		// 404 이므로 링크도 감춘다 (죽은 링크를 노출하지 않는다).
		$layout['admin_enabled'] =
			((string) $this->config->item('admin_password', 'dinnerspot') !== '');

		$layout['content'] = $this->load->view($view, $data, TRUE);

		$this->load->view('layout/main', $layout);
	}

	/**
	 * JSON 응답
	 */
	protected function json($payload, $status = 200)
	{
		$this->output
			->set_status_header($status)
			->set_content_type('application/json', 'utf-8')
			->set_output(json_encode(
				$payload,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			));
	}

	protected function json_ok($data = array(), $extra = array())
	{
		return $this->json(array_merge(array('ok' => TRUE, 'data' => $data), $extra));
	}

	protected function json_fail($message, $status = 400, $extra = array())
	{
		return $this->json(array_merge(
			array('ok' => FALSE, 'message' => $message),
			$extra
		), $status);
	}

	/**
	 * JSON 바디 또는 폼 파라미터를 통합해서 읽는다.
	 */
	protected function payload()
	{
		$raw = $this->input->raw_input_stream;

		if ($raw !== '' && $raw !== NULL)
		{
			$json = json_decode($raw, TRUE);

			if (is_array($json))
			{
				return $json;
			}
		}

		$post = $this->input->post(NULL, FALSE);
		$get  = $this->input->get(NULL, FALSE);

		return array_merge(is_array($get) ? $get : array(), is_array($post) ? $post : array());
	}

	/** POST 만 허용 */
	protected function require_post()
	{
		if ($this->input->method(TRUE) !== 'POST')
		{
			$this->json_fail('POST 요청만 허용됩니다.', 405);

			return FALSE;
		}

		return TRUE;
	}
}
