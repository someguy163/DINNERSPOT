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

	// =========================================================
	//  추천
	// =========================================================

	/** GET|POST /api/recommend */
	public function recommend()
	{
		$input = $this->payload();
		$out   = $this->spot_service->recommend($input);

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

		$host_key       = $this->input->get('host_key', FALSE);
		$state['is_host'] = ($host_key && hash_equals($room['host_key'], (string) $host_key));

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
