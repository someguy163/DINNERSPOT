<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * JSON API
 *
 * 응답 규약
 *   성공: { ok: true,  data: ... }
 *   실패: { ok: false, message: "..." }
 */
class Api extends MY_Controller {

	public function __construct()
	{
		parent::__construct();

		$this->load->library('spot_service');
		$this->load->model('place_model');
		$this->load->model('vote_model');

		$this->output->set_header('Cache-Control: no-store');
	}

	// =========================================================
	//  기준 데이터
	// =========================================================

	/** GET /api/areas */
	public function areas()
	{
		$this->json_ok($this->place_model->areas());
	}

	/** GET /api/categories */
	public function categories()
	{
		$this->json_ok($this->place_model->categories());
	}

	/**
	 * GET /api/whereami?lat=..&lng=..
	 *
	 * 좌표가 어디인지 사람이 읽는 말로 돌려준다.
	 * "내 위치 기준" 을 누른 직후, 검색을 하기 전에 어디로 찾게 되는지
	 * 보여주는 용도다. 등록된 지역(t_areas) 중 가장 가까운 곳으로 답한다.
	 */
	public function whereami()
	{
		$lat = $this->input->get('lat');
		$lng = $this->input->get('lng');

		// 쿼리스트링은 어떤 키든 배열로 만들 수 있다(`?lat[]=1`).
		// (float) 로 캐스팅하면 배열이 조용히 1 이 되어 엉뚱한 곳을 답한다.
		if ( ! is_scalar($lat) OR ! is_scalar($lng))
		{
			return $this->json_fail('좌표가 올바르지 않습니다.');
		}

		// 숫자가 아닌 값도 (float) 로 조용히 0 이 된다. 0/0 만 걸러내고 있어서
		// 한쪽만 망가진 입력이 "적도의 좌표" 로 확정되고, 그걸 사람 말로
		// 단언해 버렸다 — 실측: `?lat=abc&lng=127.0` 이 ok=true 로
		// "가장 가까운 곳은 광주송정역(3907.2km)", `?lat=37,5`(쉼표)가
		// "동탄역(23.9km)" 을 답했다. 좌표는 클램프도 추측도 하지 않고 거절한다.
		if ( ! is_numeric($lat) OR ! is_numeric($lng))
		{
			return $this->json_fail('좌표가 올바르지 않습니다.');
		}

		$lat = (float) $lat;
		$lng = (float) $lng;

		if ($lat < -90 OR $lat > 90 OR $lng < -180 OR $lng > 180
			OR ($lat === 0.0 && $lng === 0.0))
		{
			return $this->json_fail('좌표가 올바르지 않습니다.');
		}

		$near = $this->place_model->nearest_area($lat, $lng);

		$this->json_ok(array(
			'lat'   => $lat,
			'lng'   => $lng,
			'label' => $this->spot_service->describe_coords($lat, $lng, $near),
			'near'  => $near ? array(
				'id'         => (int) $near['id'],
				'name'       => $near['name'],
				'sido'       => $near['sido'],
				'sigungu'    => $near['sigungu'],
				'line_info'  => $near['line_info'],
				'distance_m' => (int) $near['distance_m'],
			) : NULL,
		));
	}

	// =========================================================
	//  추천
	// =========================================================

	/** GET|POST /api/recommend */
	public function recommend()
	{
		$input = $this->payload();
		$out   = $this->spot_service->recommend($input);

		// count 는 이 페이지의 건수, total 은 전체 순위 건수다.
		// page 를 붙이지 않으면 1페이지가 온다.
		$this->json_ok(array(
			'criteria' => $out['criteria'],
			'origin'   => $out['origin'],
			'results'  => $out['results'],
			'count'    => count($out['results']),
		), array(
			'meta' => array(
				'candidate_cnt' => $out['candidate_cnt'],
				'elapsed_ms'    => $out['elapsed_ms'],
				'naver'         => $out['naver'],
			),
			// total 은 find 로 걸러낸 뒤의 건수, total_all 은 걸러내기 전 건수다.
			// 자동완성 색인(index)은 화면 전용이라 여기로 내리지 않는다 —
			// 응답이 두 배로 커지는데 API 소비자는 per_page 를 키우면 된다.
			'page' => array(
				'page'        => $out['page'],
				'per_page'    => $out['per_page'],
				'total'       => $out['total'],
				'total_all'   => $out['total_all'],
				'total_pages' => $out['total_pages'],
				'offset'      => $out['offset'],
				'find'        => $out['find'],
			),
		));
	}

	/** GET /api/place/{id} */
	public function place($id)
	{
		$place = $this->place_model->get($id);

		if ( ! $place)
		{
			return $this->json_fail('장소를 찾을 수 없습니다.', 404);
		}

		$this->json_ok($place);
	}

	// =========================================================
	//  투표
	// =========================================================

	/**
	 * POST /api/vote/create
	 * body: { title, host_nick, headcount, max_choice, allow_change,
	 *         deadline_at, place_ids: [1,2,3], criteria: {...} }
	 */
	public function vote_create()
	{
		if ( ! $this->require_post())
		{
			return;
		}

		$in  = $this->payload();
		$ids = isset($in['place_ids']) ? $in['place_ids'] : array();

		if (is_string($ids))
		{
			$ids = array_filter(explode(',', $ids));
		}

		$ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));

		$min = (int) $this->config->item('vote_min_options', 'dinnerspot');
		$max = (int) $this->config->item('vote_max_options', 'dinnerspot');

		if (count($ids) < $min)
		{
			return $this->json_fail('후보를 최소 ' . $min . '곳 선택해 주세요.');
		}
		if (count($ids) > $max)
		{
			return $this->json_fail('후보는 최대 ' . $max . '곳까지 가능합니다.');
		}

		$places = $this->place_model->get_many($ids);

		if (count($places) < $min)
		{
			return $this->json_fail('선택한 장소를 찾을 수 없습니다.');
		}

		// 요청한 순서를 유지
		$by_id  = array_column($places, NULL, 'id');
		$sorted = array();

		foreach ($ids as $id)
		{
			if (isset($by_id[$id]))
			{
				$sorted[] = $by_id[$id];
			}
		}

		$room = $this->vote_model->create_room(array(
			'title'        => $in['title'] ?? '',
			'host_nick'    => $in['host_nick'] ?? '',
			'headcount'    => $in['headcount'] ?? 0,
			'max_choice'   => $in['max_choice'] ?? 1,
			'allow_change' => array_key_exists('allow_change', $in) ? $in['allow_change'] : 1,
			'deadline_at'  => $in['deadline_at'] ?? NULL,
			'criteria'     => $in['criteria'] ?? NULL,
			// 명단이 오면 1인 1링크(invite) 모드로 만든다
			'roster'       => $in['roster'] ?? '',
		), $sorted);

		if ($room === NULL)
		{
			return $this->json_fail('투표방 생성에 실패했습니다.', 500);
		}

		$this->place_model->bump_picks($ids);

		$out = array(
			'code'      => $room['code'],
			'host_key'  => $room['host_key'],
			'room_url'  => base_url('vote/r/' . $room['code']),
			'mode'      => $room['mode'],
		);

		if ($room['mode'] === 'invite')
		{
			// 초대 링크 목록은 방장만 볼 수 있다(Vote::authorize_host).
			// 만든 직후 바로 그 화면으로 넘어가므로 이 브라우저를 방장으로 표시해 둔다.
			// 세션이 끊긴 뒤에는 ?host_key=... 로 다시 들어와야 한다 — 투표방 화면의
			// "초대 링크" 버튼이 localStorage 의 host_key 로 그 주소를 만들어 준다.
			$this->session->set_userdata('ds_host_' . $room['code'], TRUE);

			$out['invites_url'] = base_url('vote/r/' . $room['code'] . '/invites');
			$out['invites']     = array();

			foreach ($room['invites'] as $iv)
			{
				$out['invites'][] = array(
					'nickname' => $iv['nickname'],
					'url'      => base_url('vote/i/' . $iv['token']),
				);
			}
		}

		$this->json_ok($out);
	}

	/** GET /api/vote/{code} — 현재 상태 (폴링용) */
	public function vote_state($code)
	{
		$room = $this->vote_model->room_by_code($code);

		if ( ! $room)
		{
			return $this->json_fail('투표방을 찾을 수 없습니다.', 404);
		}

		$voter_key = $this->input->get('voter_key', FALSE);
		$state     = $this->vote_model->state($room, $voter_key);

		// ?host_key[]=x 로 배열이 오면 (string) 캐스팅이 경고를 내며 JSON 을 오염시킨다
		$host_key       = $this->input->get('host_key', FALSE);
		$state['is_host'] = (is_scalar($host_key) && (string) $host_key !== ''
			&& hash_equals($room['host_key'], (string) $host_key));

		$this->json_ok($state);
	}

	/**
	 * POST /api/vote/{code}/cast
	 * body: { voter_key, nickname, option_ids: [..], comment }
	 */
	public function vote_cast($code)
	{
		if ( ! $this->require_post())
		{
			return;
		}

		$room = $this->vote_model->room_by_code($code);

		if ( ! $room)
		{
			return $this->json_fail('투표방을 찾을 수 없습니다.', 404);
		}

		$in  = $this->payload();
		$ids = isset($in['option_ids']) ? $in['option_ids'] : array();

		if (is_string($ids))
		{
			$ids = array_filter(explode(',', $ids));
		}

		$res = $this->vote_model->cast(
			$room,
			$in['voter_key'] ?? '',
			$in['nickname'] ?? '',
			(array) $ids,
			$in['comment'] ?? ''
		);

		if ( ! $res['ok'])
		{
			return $this->json_fail($res['msg']);
		}

		$state = $this->vote_model->state($room, $res['voter_key']);

		$this->json_ok(array(
			'voter_key' => $res['voter_key'],
			'state'     => $state,
		), array('message' => $res['msg']));
	}

	// ---------------------------------------------------------
	//  1인 1링크 (초대 토큰)
	// ---------------------------------------------------------

	/** GET /api/vote/i/{token} — 초대받은 사람 기준 상태 (폴링용) */
	public function invite_state($token)
	{
		$found = $this->vote_model->by_invite_token($token);

		if ( ! $found)
		{
			return $this->json_fail('초대 링크를 찾을 수 없습니다.', 404);
		}

		$state = $this->vote_model->state($found['room'], $found['voter']['voter_key']);
		$state['invite'] = array('nickname' => $found['voter']['nickname']);

		$this->json_ok($state);
	}

	/**
	 * POST /api/vote/i/{token}/cast — 초대받은 사람의 투표
	 *
	 * 이름은 받지 않는다. 토큰이 그 사람을 지목하므로 서버가 명단의
	 * 이름을 그대로 쓴다 (참여자가 이름을 바꿀 수 없다).
	 */
	public function invite_cast($token)
	{
		if ( ! $this->require_post())
		{
			return;
		}

		$found = $this->vote_model->by_invite_token($token);

		if ( ! $found)
		{
			return $this->json_fail('초대 링크를 찾을 수 없습니다.', 404);
		}

		$in  = $this->payload();
		$ids = isset($in['option_ids']) ? $in['option_ids'] : array();

		if (is_string($ids))
		{
			$ids = array_filter(explode(',', $ids));
		}

		$res = $this->vote_model->cast(
			$found['room'],
			$found['voter']['voter_key'],
			$found['voter']['nickname'],
			(array) $ids,
			$in['comment'] ?? ''
		);

		if ( ! $res['ok'])
		{
			return $this->json_fail($res['msg']);
		}

		$state = $this->vote_model->state($found['room'], $found['voter']['voter_key']);
		$state['invite'] = array('nickname' => $found['voter']['nickname']);

		$this->json_ok(array('state' => $state), array('message' => $res['msg']));
	}

	/** POST /api/vote/{code}/close — 방장 마감 */
	public function vote_close($code)
	{
		if ( ! $this->require_post())
		{
			return;
		}

		$room = $this->vote_model->room_by_code($code);

		if ( ! $room)
		{
			return $this->json_fail('투표방을 찾을 수 없습니다.', 404);
		}

		$in  = $this->payload();
		$res = $this->vote_model->close($room, $in['host_key'] ?? '');

		if ( ! $res['ok'])
		{
			return $this->json_fail($res['msg'], 403);
		}

		$room = $this->vote_model->room_by_code($code);

		$this->json_ok($this->vote_model->state($room), array('message' => $res['msg']));
	}
}
