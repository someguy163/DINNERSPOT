<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 투표 화면
 */
class Vote extends MY_Controller {

	public function __construct()
	{
		parent::__construct();

		$this->load->model('vote_model');
		$this->load->model('place_model');
	}

	/** 코드 입력해서 방 들어가기 */
	public function index()
	{
		$code = $this->input->get('code', TRUE);

		// ?code[]=x 로 배열이 오면 뷰의 h() 에서 (string) 캐스팅 경고가 나고
		// 그 HTML 이 화면 앞에 붙는다. 코드는 항상 문자열 하나다.
		if ( ! is_scalar($code)) $code = '';

		if ($code)
		{
			$room = $this->vote_model->room_by_code($code);

			if ($room)
			{
				redirect('vote/r/' . $room['code']);

				return;
			}
		}

		$this->render('vote/enter', array(
			'error' => $code ? '해당 코드의 투표방이 없습니다.' : '',
			'code'  => $code ?: '',
		), array(
			'title'      => '투표방 참여 · DINNERSPOT',
			'nav'        => 'vote',
			'body_class' => 'page-vote-enter',
		));
	}

	/** 후보 선택 -> 방 생성 폼 (추천 결과에서 넘어옴) */
	public function create_form()
	{
		// ?ids[]=1 로 배열이 오면 explode() 가 PHP 8 에서 TypeError 로 터진다
		// (실측: /vote/new?ids[]=1 -> HTTP 500 + "explode(): Argument #2 ($string)
		//  must be of type string, array given"). 후보 목록은 늘 쉼표 문자열 하나다.
		$ids = $this->input->get('ids', TRUE);

		if ( ! is_scalar($ids))
		{
			$ids = '';
		}

		$ids = ($ids !== '') ? array_filter(array_map('intval', explode(',', (string) $ids))) : array();

		$places = $ids ? $this->place_model->get_many($ids) : array();

		// 요청 순서 유지
		$by_id  = array_column($places, NULL, 'id');
		$sorted = array();

		foreach ($ids as $id)
		{
			if (isset($by_id[$id]))
			{
				$sorted[] = $by_id[$id];
			}
		}

		$cat_map = $this->place_model->category_map();

		foreach ($sorted as &$p)
		{
			$c = isset($cat_map[$p['category_code']]) ? $cat_map[$p['category_code']] : NULL;
			$p['category_label'] = $c ? $c['label'] : '기타';
			$p['category_emoji'] = $c ? $c['emoji'] : '🍴';
		}
		unset($p);

		$this->render('vote/create', array(
			'places' => $sorted,
			'min'    => (int) $this->config->item('vote_min_options', 'dinnerspot'),
			'max'    => (int) $this->config->item('vote_max_options', 'dinnerspot'),
		), array(
			'title'      => '투표 만들기 · DINNERSPOT',
			'nav'        => 'vote',
			'body_class' => 'page-vote-create',
		));
	}

	/** 투표방 */
	public function room($code)
	{
		$room = $this->vote_model->room_by_code($code);

		if ( ! $room)
		{
			show_404();

			return;
		}

		$state   = $this->vote_model->state($room);
		$cat_map = $this->place_model->category_map();

		$this->render('vote/room', array(
			'state'    => $state,
			'code'     => $room['code'],
			'cat_map'  => $cat_map,
			'poll_ms'  => (int) $this->config->item('vote_poll_interval', 'dinnerspot'),
			'room_url' => base_url('vote/r/' . $room['code']),
		), array(
			'title'      => $room['title'] . ' · DINNERSPOT 투표',
			'nav'        => 'vote',
			'body_class' => 'page-vote-room',
		));
	}

	/**
	 * 1인 1링크 입장.
	 *
	 * 토큰이 그 사람을 지목하므로 이름을 입력받지 않는다.
	 * 토큰이 URL 에 있어서 시크릿 창으로 열어도 같은 사람으로 식별된다
	 * (브라우저 localStorage 에 의존하지 않는다).
	 */
	public function invite($token)
	{
		$found = $this->vote_model->by_invite_token($token);

		if ( ! $found)
		{
			$this->output->set_status_header(404);

			$this->render('vote/invite_invalid', array(), array(
				'title'      => '초대 링크를 찾을 수 없습니다 · DINNERSPOT',
				'nav'        => 'vote',
				'body_class' => 'page-vote-enter',
			));

			return;
		}

		$room  = $found['room'];
		$voter = $found['voter'];

		// 주소 자체가 이 사람의 투표 권한이다. 색인·캐시 대상이 아니고,
		// 바깥 링크(푸터의 네이버 문서 등)를 눌렀을 때 Referer 로 새어도 안 된다.
		$this->output->set_header('Cache-Control: no-store');
		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
		$this->output->set_header('Referrer-Policy: no-referrer');

		$this->render('vote/room', array(
			'state'    => $this->vote_model->state($room, $voter['voter_key']),
			'code'     => $room['code'],
			'cat_map'  => $this->place_model->category_map(),
			'poll_ms'  => (int) $this->config->item('vote_poll_interval', 'dinnerspot'),
			'room_url' => base_url('vote/r/' . $room['code']),
			// 초대 입장 전용
			'invite'   => array(
				'token'    => $voter['invite_token'],
				'nickname' => $voter['nickname'],
			),
		), array(
			'title'      => $room['title'] . ' · ' . $voter['nickname'] . '님',
			'nav'        => 'vote',
			'body_class' => 'page-vote-room',
		));
	}

	/**
	 * 방장 확인.
	 *
	 * 통과하면 TRUE. 통과하지 못하면 응답을 직접 마무리하므로(404 또는 리다이렉트)
	 * 호출부는 FALSE 를 받으면 그냥 return 해야 한다.
	 *
	 * 통과 조건은 셋 중 하나다.
	 *   1) 쿼리스트링 host_key 가 방의 host_key 와 일치 — 다른 브라우저·기기에서
	 *      다시 들어올 때의 유일한 경로다. 통과 즉시 세션으로 옮기고 주소를
	 *      되돌려서 host_key 가 주소창·방문기록·Referer 에 남지 않게 한다.
	 *   2) 이 브라우저 세션이 그 방의 방장으로 표시돼 있음 (방 생성 시 심는다)
	 *   3) 관리자 로그인 세션 — 관리자 화면에서 이 화면으로 넘어오는 링크가 있다
	 *
	 * 실패는 403 이 아니라 404 다. 403 은 "그 방이 명단 모드로 존재한다" 를
	 * 알려주므로, 명단 모드가 아닌 방과 구분되지 않게 맞춘다.
	 */
	protected function authorize_host(array $room)
	{
		// Admin::SESS 와 같은 값. Admin 클래스는 이 요청에 로드되지 않으므로 리터럴로 둔다.
		if ($this->session->userdata('ds_admin_ok'))
		{
			return TRUE;
		}

		$sess_key = 'ds_host_' . $room['code'];

		// ?host_key[]=x 로 배열이 오면 (string) 캐스팅이
		// "Array to string conversion" 경고를 내고 그 HTML 이 404 화면 앞에 붙는다
		// (실측: /vote/r/{code}/invites?host_key[]=x -> Vote.php 이 줄).
		// 방장 키는 항상 문자열 하나다. 배열은 키 없음으로 본다.
		$given = $this->input->get('host_key', FALSE);
		$given = is_scalar($given) ? (string) $given : '';

		if ($given !== '' && hash_equals((string) $room['host_key'], $given))
		{
			$this->session->set_userdata($sess_key, TRUE);
			redirect('vote/r/' . $room['code'] . '/invites');

			return FALSE;
		}

		if ($this->session->userdata($sess_key))
		{
			return TRUE;
		}

		show_404();

		return FALSE;
	}

	/**
	 * 초대 링크 목록 (방장용).
	 *
	 * 방장만 볼 수 있다 — 목록 전체가 곧 "모든 사람의 투표 권한" 이다.
	 * 방 코드로는 열리지 않아야 한다: 코드는 집계 화면 주소이자 단체방에
	 * 뿌리는 값이라, 코드만으로 열리면 단체방에 있는 누구나 남의 링크로
	 * 투표할 수 있다.
	 */
	public function invites($code)
	{
		$room = $this->vote_model->room_by_code($code);

		if ( ! $room OR $room['mode'] !== 'invite')
		{
			show_404();

			return;
		}

		if ( ! $this->authorize_host($room))
		{
			return;
		}

		// 이 화면은 캐시·색인 대상이 아니고, 주소가 Referer 로 새어도 안 된다.
		$this->output->set_header('Cache-Control: no-store');
		$this->output->set_header('X-Robots-Tag: noindex, nofollow');
		$this->output->set_header('Referrer-Policy: no-referrer');

		$this->render('vote/invites', array(
			'room'     => array(
				'code'  => $room['code'],
				'title' => $room['title'],
				'mode'  => $room['mode'],
			),
			'invites'  => $this->vote_model->invites($room['id']),
			'room_url' => base_url('vote/r/' . $room['code']),
		), array(
			'title'      => $room['title'] . ' · 초대 링크',
			'nav'        => 'vote',
			'body_class' => 'page-vote-invites',
		));
	}

	/** 결과만 보기 */
	public function result($code)
	{
		$room = $this->vote_model->room_by_code($code);

		if ( ! $room)
		{
			show_404();

			return;
		}

		$this->render('vote/result', array(
			'state'    => $this->vote_model->state($room),
			'code'     => $room['code'],
			'cat_map'  => $this->place_model->category_map(),
			'room_url' => base_url('vote/r/' . $room['code']),
		), array(
			'title'      => $room['title'] . ' 결과 · DINNERSPOT',
			'nav'        => 'vote',
			'body_class' => 'page-vote-result',
		));
	}
}
