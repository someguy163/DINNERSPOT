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
		$ids = $this->input->get('ids', TRUE);
		$ids = $ids ? array_filter(array_map('intval', explode(',', $ids))) : array();

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
	 * 초대 링크 목록 (방장용).
	 *
	 * host_key 를 아는 사람만 볼 수 있다 — 링크 목록이 곧 모든 사람의
	 * 투표 권한이므로 아무나 보면 안 된다.
	 */
	public function invites($code)
	{
		$room = $this->vote_model->room_by_code($code);

		if ( ! $room OR $room['mode'] !== 'invite')
		{
			show_404();

			return;
		}

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
