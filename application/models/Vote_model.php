<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 투표 저장소
 *
 * 로그인 없이 동작한다.
 *  - 방장:   host_key (생성자 브라우저 localStorage)
 *  - 참여자: voter_key (참여자 브라우저 localStorage)
 * 두 토큰 모두 서버가 발급하고, 클라이언트가 보관/제출한다.
 */
class Vote_model extends CI_Model {

	public function __construct()
	{
		parent::__construct();

		// 섹션(dinnerspot)으로 로드되어 있어야 item($k, 'dinnerspot') 이 동작한다
		$this->config->load('dinnerspot', TRUE, TRUE);
	}

	/**
	 * 투표방 생성
	 *
	 * @param  array $data    title, host_nick, headcount, max_choice, deadline_at, criteria
	 * @param  array $options 후보 배열 (place row 또는 스냅샷용 배열)
	 * @return array|NULL     ['code'=>..., 'host_key'=>..., 'room_id'=>...]
	 */
	public function create_room(array $data, array $options)
	{
		$min = (int) $this->config->item('vote_min_options', 'dinnerspot') ?: 2;
		$max = (int) $this->config->item('vote_max_options', 'dinnerspot') ?: 6;

		$options = array_slice(array_values($options), 0, $max);

		if (count($options) < $min)
		{
			return NULL;
		}

		$len      = (int) $this->config->item('vote_code_length', 'dinnerspot') ?: 8;
		$code     = $this->unique_code($len);
		$host_key = ds_token(32);

		$this->db->trans_start();

		// 명단이 있으면 1인 1링크(invite) 모드
		$roster = $this->clean_roster($data['roster'] ?? '');
		$mode   = ! empty($roster) ? 'invite' : 'open';

		$this->db->insert('t_vote_rooms', array(
			'code'         => $code,
			'title'        => $this->clean($data['title'] ?? '', 120, '회식 장소 투표'),
			'host_nick'    => $this->clean($data['host_nick'] ?? '', 40, '방장'),
			'host_key'     => $host_key,
			'headcount'    => ! empty($roster)
				? count($roster)
				: max(0, (int) ($data['headcount'] ?? 0)),
			'max_choice'   => max(1, min(count($options), (int) ($data['max_choice'] ?? 1))),
			'allow_change' => empty($data['allow_change']) ? 0 : 1,
			'mode'         => $mode,
			'deadline_at'  => $this->clean_deadline($data['deadline_at'] ?? NULL),
			'criteria'     => isset($data['criteria'])
				? json_encode($data['criteria'], JSON_UNESCAPED_UNICODE)
				: NULL,
		));

		$room_id = (int) $this->db->insert_id();
		$sort    = 0;

		foreach ($options as $opt)
		{
			$this->db->insert('t_vote_options', array(
				'room_id'    => $room_id,
				'place_id'   => ! empty($opt['id']) ? (int) $opt['id'] : NULL,
				'snapshot'   => json_encode($this->snapshot($opt), JSON_UNESCAPED_UNICODE),
				'sort_order' => $sort++,
			));
		}

		// invite 모드: 명단만큼 참여자 행을 미리 만들고 각자 초대 토큰을 발급한다.
		// 투표 전부터 행이 존재하므로 "누가 아직 안 했는지"(voted_at IS NULL)를 알 수 있다.
		$invites = array();

		foreach ($roster as $nick)
		{
			$token = $this->unique_invite_token();

			$this->db->insert('t_vote_voters', array(
				'room_id'      => $room_id,
				'voter_key'    => ds_token(32),
				'nickname'     => $nick,
				'invite_token' => $token,
				'voted_at'     => NULL,
			));

			$invites[] = array('nickname' => $nick, 'token' => $token);
		}

		$this->db->trans_complete();

		if ($this->db->trans_status() === FALSE)
		{
			return NULL;
		}

		return array(
			'room_id'  => $room_id,
			'code'     => $code,
			'host_key' => $host_key,
			'mode'     => $mode,
			'invites'  => $invites,
		);
	}

	/**
	 * 참여자 명단 정리.
	 *
	 * 줄바꿈 / 콤마 / 탭으로 구분한 이름 목록을 받아 중복과 공백을 정리한다.
	 * 같은 이름이 두 번 들어오면 하나만 남긴다 — 명단에 동명이인이 있으면
	 * 방장이 "박사원A", "박사원B" 처럼 구분해서 넣어야 한다.
	 *
	 * @return array 정리된 이름 배열 (최대 100명)
	 */
	protected function clean_roster($raw)
	{
		if (is_array($raw))
		{
			$parts = $raw;
		}
		else
		{
			$parts = preg_split('/[\r\n,\t]+/u', (string) $raw);
		}

		$out  = array();
		$seen = array();

		foreach ($parts as $p)
		{
			$nick = $this->clean($p, 40);

			if ($nick === '')
			{
				continue;
			}

			$key = mb_strtolower($nick, 'UTF-8');

			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = TRUE;
			$out[] = $nick;

			if (count($out) >= 100)
			{
				break;
			}
		}

		return $out;
	}

	/** 충돌하지 않는 초대 토큰 */
	protected function unique_invite_token()
	{
		for ($i = 0; $i < 20; $i++)
		{
			$token = ds_token(16);

			$hit = $this->db->select('id')->where('invite_token', $token)
				->get('t_vote_voters')->row_array();

			if ( ! $hit)
			{
				return $token;
			}
		}

		return ds_token(16);
	}

	/**
	 * 초대 토큰으로 참여자와 방을 찾는다.
	 *
	 * @return array|NULL ['room' => ..., 'voter' => ...]
	 */
	public function by_invite_token($token)
	{
		$token = preg_replace('/[^a-f0-9]/i', '', (string) $token);

		if ($token === '')
		{
			return NULL;
		}

		$voter = $this->db->where('invite_token', $token)
			->get('t_vote_voters')
			->row_array();

		if ( ! $voter)
		{
			return NULL;
		}

		$room = $this->db->where('id', $voter['room_id'])
			->get('t_vote_rooms')
			->row_array();

		if ( ! $room)
		{
			return NULL;
		}

		// room_by_code 와 같은 자동 마감 처리
		if ($room['status'] === 'open'
			&& ! empty($room['deadline_at'])
			&& strtotime($room['deadline_at']) < time())
		{
			$this->db->where('id', $room['id'])->update('t_vote_rooms', array('status' => 'closed'));
			$room['status'] = 'closed';
		}

		return array('room' => $room, 'voter' => $voter);
	}

	// =========================================================
	//  관리자 조회 - 전체 투표 현황
	// =========================================================

	/**
	 * 모든 투표방을 한 줄씩 요약한다.
	 *
	 * 방마다 참여율과 1위 후보를 같이 계산해서 목록만 보고도 상황을 알 수 있게 한다.
	 * host_key 는 절대 내보내지 않는다 (그 값을 알면 남의 방을 마감할 수 있다).
	 *
	 * @param  array $opt status / q / limit / offset
	 * @return array
	 */
	public function admin_rooms(array $opt = array())
	{
		$limit  = max(1, min(200, (int) ($opt['limit'] ?? 50)));
		$offset = max(0, (int) ($opt['offset'] ?? 0));

		$this->db->select('id, code, title, host_nick, headcount, max_choice,
		                   mode, status, deadline_at, created_at', FALSE)
			->from('t_vote_rooms');

		if ( ! empty($opt['status']) && in_array($opt['status'], array('open', 'closed'), TRUE))
		{
			$this->db->where('status', $opt['status']);
		}

		if ( ! empty($opt['q']))
		{
			$q = trim((string) $opt['q']);

			$this->db->group_start()
				->like('title', $q)
				->or_like('host_nick', $q)
				->or_like('code', strtoupper($q))
				->group_end();
		}

		$rooms = $this->db->order_by('created_at', 'DESC')
			->limit($limit, $offset)
			->get()
			->result_array();

		if (empty($rooms))
		{
			return array();
		}

		$ids = array_map('intval', array_column($rooms, 'id'));

		// 방별 집계를 한 번에 가져온다 (방 수만큼 쿼리를 도는 것을 피한다)
		$voters = array();
		$rows = $this->db->select('room_id,
			COUNT(*) AS invited,
			SUM(voted_at IS NOT NULL) AS voted', FALSE)
			->where_in('room_id', $ids)
			->group_by('room_id')
			->get('t_vote_voters')
			->result_array();

		foreach ($rows as $r)
		{
			$voters[(int) $r['room_id']] = $r;
		}

		$ballots = array();
		$rows = $this->db->select('room_id, COUNT(*) AS cnt', FALSE)
			->where_in('room_id', $ids)
			->group_by('room_id')
			->get('t_vote_ballots')
			->result_array();

		foreach ($rows as $r)
		{
			$ballots[(int) $r['room_id']] = (int) $r['cnt'];
		}

		// 방별 최다 득표 후보
		$leaders = array();
		$rows = $this->db->select('o.room_id, o.snapshot, COUNT(b.id) AS votes', FALSE)
			->from('t_vote_options o')
			->join('t_vote_ballots b', 'b.option_id = o.id AND b.room_id = o.room_id', 'left')
			->where_in('o.room_id', $ids)
			->group_by('o.id')
			->order_by('votes', 'DESC')
			->get()
			->result_array();

		foreach ($rows as $r)
		{
			$rid = (int) $r['room_id'];

			// order_by votes DESC 이므로 방마다 처음 만나는 행이 1위다
			if (isset($leaders[$rid]) OR (int) $r['votes'] === 0)
			{
				continue;
			}

			$snap = json_decode($r['snapshot'], TRUE);
			$leaders[$rid] = array(
				'name'  => is_array($snap) && isset($snap['name']) ? $snap['name'] : '',
				'votes' => (int) $r['votes'],
			);
		}

		foreach ($rooms as &$room)
		{
			$rid = (int) $room['id'];
			$v   = $voters[$rid] ?? array('invited' => 0, 'voted' => 0);

			$room['invited_count'] = (int) $v['invited'];
			$room['voted_count']   = (int) $v['voted'];
			$room['total_votes']   = $ballots[$rid] ?? 0;
			$room['leader']        = $leaders[$rid] ?? NULL;
			$room['option_count']  = 0;

			// 참여율: invite 모드는 명단 기준, open 모드는 방장이 적은 참석 인원 기준
			$base = ($room['mode'] === 'invite')
				? (int) $v['invited']
				: (int) $room['headcount'];

			$room['turnout'] = ($base > 0)
				? (int) round($room['voted_count'] / $base * 100)
				: NULL;

			unset($room['id']);   // 내부 id 는 내보내지 않는다
		}
		unset($room);

		// 후보 수
		$rows = $this->db->select('room_id, COUNT(*) AS cnt', FALSE)
			->where_in('room_id', $ids)
			->group_by('room_id')
			->get('t_vote_options')
			->result_array();

		$optCnt = array();

		foreach ($rows as $r)
		{
			$optCnt[(int) $r['room_id']] = (int) $r['cnt'];
		}

		$i = 0;

		foreach ($rooms as &$room)
		{
			$room['option_count'] = $optCnt[$ids[$i]] ?? 0;
			$i++;
		}
		unset($room);

		return $rooms;
	}

	/** 관리자 화면 상단 요약 */
	public function admin_summary()
	{
		$row = $this->db->select('COUNT(*) AS rooms,
			SUM(status = "open") AS open_rooms,
			SUM(status = "closed") AS closed_rooms,
			SUM(mode = "invite") AS invite_rooms', FALSE)
			->get('t_vote_rooms')
			->row_array();

		$v = $this->db->select('COUNT(*) AS voters, SUM(voted_at IS NOT NULL) AS voted', FALSE)
			->get('t_vote_voters')
			->row_array();

		return array(
			'rooms'        => (int) $row['rooms'],
			'open_rooms'   => (int) $row['open_rooms'],
			'closed_rooms' => (int) $row['closed_rooms'],
			'invite_rooms' => (int) $row['invite_rooms'],
			'voters'       => (int) $v['voters'],
			'voted'        => (int) $v['voted'],
			'ballots'      => (int) $this->db->count_all_results('t_vote_ballots'),
		);
	}

	/** 방의 초대 목록 (방장 화면에서 링크를 뿌리기 위해) */
	public function invites($room_id)
	{
		return $this->db->select('nickname, invite_token, voted_at')
			->where('room_id', (int) $room_id)
			->where('invite_token IS NOT NULL', NULL, FALSE)
			->order_by('id', 'ASC')
			->get('t_vote_voters')
			->result_array();
	}

	/** 후보 스냅샷에 담을 필드만 추린다 */
	protected function snapshot(array $p)
	{
		$keys = array(
			'id', 'name', 'category_code', 'category_raw', 'address', 'road_address',
			'phone', 'homepage', 'lat', 'lng', 'avg_price', 'max_party',
			'has_room', 'has_parking', 'open_late', 'rating', 'review_count',
			'score', 'distance_m', 'reasons', 'source',
		);

		$out = array();

		foreach ($keys as $k)
		{
			if (array_key_exists($k, $p))
			{
				$out[$k] = $p[$k];
			}
		}

		return $out;
	}

	protected function clean($str, $len, $fallback = '')
	{
		$str = trim(strip_tags((string) $str));
		$str = mb_substr($str, 0, $len, 'UTF-8');

		return ($str === '') ? $fallback : $str;
	}

	protected function clean_deadline($val)
	{
		if (empty($val))
		{
			return NULL;
		}

		$ts = strtotime((string) $val);

		if ($ts === FALSE OR $ts < time())
		{
			return NULL;
		}

		// 최대 30일
		$ts = min($ts, time() + 30 * 86400);

		return date('Y-m-d H:i:s', $ts);
	}

	protected function unique_code($len)
	{
		for ($i = 0; $i < 20; $i++)
		{
			$code = ds_room_code($len);

			$hit = $this->db->select('id')->where('code', $code)
				->get('t_vote_rooms')->row_array();

			if ( ! $hit)
			{
				return $code;
			}
		}

		// 극단적 충돌 시 길이를 늘려 회피
		return ds_room_code($len + 4);
	}

	// =========================================================
	//  조회
	// =========================================================

	public function room_by_code($code)
	{
		$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));

		if ($code === '')
		{
			return NULL;
		}

		$row = $this->db->where('code', $code)->get('t_vote_rooms')->row_array();

		if ( ! $row)
		{
			return NULL;
		}

		// 마감시각이 지났으면 자동 종료 처리
		if ($row['status'] === 'open'
			&& ! empty($row['deadline_at'])
			&& strtotime($row['deadline_at']) < time())
		{
			$this->db->where('id', $row['id'])->update('t_vote_rooms', array('status' => 'closed'));
			$row['status'] = 'closed';
		}

		return $row;
	}

	public function options($room_id)
	{
		$rows = $this->db->where('room_id', (int) $room_id)
			->order_by('sort_order', 'ASC')
			->get('t_vote_options')
			->result_array();

		foreach ($rows as &$r)
		{
			$snap = json_decode($r['snapshot'], TRUE);
			$r['place'] = is_array($snap) ? $snap : array();
			unset($r['snapshot']);
		}

		return $rows;
	}

	/**
	 * 방 전체 상태 (후보 + 집계 + 참여자)
	 *
	 * @param  array  $room
	 * @param  string $voter_key 내 표시용. 없으면 NULL
	 * @return array
	 */
	public function state(array $room, $voter_key = NULL)
	{
		$options = $this->options($room['id']);

		// 후보별 득표
		$tally = array();

		$rows = $this->db->select('option_id, COUNT(*) AS cnt')
			->where('room_id', $room['id'])
			->group_by('option_id')
			->get('t_vote_ballots')
			->result_array();

		foreach ($rows as $r)
		{
			$tally[(int) $r['option_id']] = (int) $r['cnt'];
		}

		// 참여자 + 각자 고른 후보
		$voters = $this->db->select('id, nickname, comment, voted_at, voter_key')
			->where('room_id', $room['id'])
			->order_by('id', 'ASC')
			->get('t_vote_voters')
			->result_array();

		$picks = array();

		if ( ! empty($voters))
		{
			$brows = $this->db->select('voter_id, option_id')
				->where('room_id', $room['id'])
				->get('t_vote_ballots')
				->result_array();

			foreach ($brows as $b)
			{
				$picks[(int) $b['voter_id']][] = (int) $b['option_id'];
			}
		}

		$my = NULL;
		$voter_list = array();

		foreach ($voters as $v)
		{
			$vid   = (int) $v['id'];
			$entry = array(
				'nickname' => $v['nickname'],
				'comment'  => $v['comment'],
				'voted_at' => $v['voted_at'],
				// invite 모드는 투표 전부터 행이 존재한다. voted_at 이 NULL 이면 미투표.
				'voted'    => ! empty($v['voted_at']),
				'ago'      => $v['voted_at'] ? ds_time_ago($v['voted_at']) : '미투표',
				'picks'    => isset($picks[$vid]) ? $picks[$vid] : array(),
			);

			if ($voter_key !== NULL && hash_equals($v['voter_key'], (string) $voter_key))
			{
				$my = $entry;
			}

			$voter_list[] = $entry;
		}

		$total_votes = array_sum($tally);
		$max_cnt     = $tally ? max($tally) : 0;

		foreach ($options as &$o)
		{
			$oid          = (int) $o['id'];
			$o['votes']   = isset($tally[$oid]) ? $tally[$oid] : 0;
			$o['percent'] = $total_votes > 0 ? round($o['votes'] / $total_votes * 100) : 0;
			$o['leading'] = ($max_cnt > 0 && $o['votes'] === $max_cnt);
		}
		unset($o);

		return array(
			'room' => array(
				'code'         => $room['code'],
				'title'        => $room['title'],
				'host_nick'    => $room['host_nick'],
				'headcount'    => (int) $room['headcount'],
				'max_choice'   => (int) $room['max_choice'],
				'allow_change' => (int) $room['allow_change'],
				'mode'         => isset($room['mode']) ? $room['mode'] : 'open',
				'deadline_at'  => $room['deadline_at'],
				'status'       => $room['status'],
				'created_at'   => $room['created_at'],
			),
			'options'      => $options,
			'voters'       => $voter_list,
			// invite 모드는 초대만 된 사람도 목록에 있으므로 둘을 구분해야 한다.
			//   invited_count : 명단에 오른 총 인원
			//   voter_count   : 실제로 투표한 인원
			'invited_count' => count($voter_list),
			'voter_count'   => count(array_filter($voter_list, function ($v) { return $v['voted']; })),
			'pending'       => array_values(array_map(
				function ($v) { return $v['nickname']; },
				array_filter($voter_list, function ($v) { return ! $v['voted']; })
			)),
			'total_votes'  => $total_votes,
			'me'           => $my,
			'is_tie'       => ($max_cnt > 0 && count(array_keys($tally, $max_cnt)) > 1),
		);
	}

	// =========================================================
	//  투표
	// =========================================================

	/**
	 * 표 등록/변경
	 *
	 * @return array ['ok'=>bool, 'msg'=>string, 'voter_key'=>string]
	 */
	public function cast(array $room, $voter_key, $nickname, array $option_ids, $comment = '')
	{
		if ($room['status'] !== 'open')
		{
			return array('ok' => FALSE, 'msg' => '이미 마감된 투표입니다.');
		}

		// invite 모드는 이름을 방장이 정해둔 값으로 쓴다 (참여자가 입력하지 않는다).
		$is_invite = (isset($room['mode']) && $room['mode'] === 'invite');
		$nickname  = $this->clean($nickname, 40);

		if ( ! $is_invite && $nickname === '')
		{
			return array('ok' => FALSE, 'msg' => '이름을 입력해 주세요.');
		}

		// 이 방의 유효한 후보만 통과
		$valid = array_column($this->options($room['id']), 'id');
		$valid = array_map('intval', $valid);

		$option_ids = array_values(array_unique(array_filter(
			array_map('intval', $option_ids),
			function ($id) use ($valid) { return in_array($id, $valid, TRUE); }
		)));

		if (empty($option_ids))
		{
			return array('ok' => FALSE, 'msg' => '후보를 선택해 주세요.');
		}

		$max = max(1, (int) $room['max_choice']);

		if (count($option_ids) > $max)
		{
			return array('ok' => FALSE, 'msg' => '최대 ' . $max . '개까지 선택할 수 있습니다.');
		}

		$voter_key = preg_replace('/[^a-f0-9]/i', '', (string) $voter_key);
		$existing  = NULL;

		if ($voter_key !== '')
		{
			$existing = $this->db->where('room_id', $room['id'])
				->where('voter_key', $voter_key)
				->get('t_vote_voters')
				->row_array();
		}

		// invite 모드에서는 명단에 없는 사람이 투표할 수 없다.
		// 초대 링크(/vote/i/<token>)로 들어오면 컨트롤러가 그 사람의 voter_key 를
		// 넘겨주므로 $existing 이 잡힌다. 잡히지 않으면 명단 외 접근이다.
		if (isset($room['mode']) && $room['mode'] === 'invite' && ! $existing)
		{
			return array(
				'ok'  => FALSE,
				'msg' => '이 투표는 초대받은 사람만 참여할 수 있습니다. 받은 링크로 다시 들어와 주세요.',
			);
		}

		if ($existing && empty($room['allow_change']))
		{
			return array('ok' => FALSE, 'msg' => '이 투표는 재투표가 허용되지 않습니다.');
		}

		// invite 모드: 이름은 명단 값으로 고정한다. 클라이언트가 보낸 값은 무시.
		if ($is_invite && $existing)
		{
			$nickname = $existing['nickname'];
		}

		// 같은 방에 같은 이름이 이미 있으면 막는다.
		//
		// 이름은 사람이 직접 타이핑하는 자기 신고 값이라 중복을 막을 장치가 없다.
		// 그대로 두면 참여자 목록에 "박사원, 박사원" 이 나란히 떠서 누가 아직
		// 투표하지 않았는지 총무가 알 수 없다. 동명이인이 실제로 있을 수 있으니
		// 자동으로 번호를 붙이지 않고, 구분되는 이름을 쓰도록 안내한다.
		$dup = $is_invite ? NULL : $this->db->select('id')
			->where('room_id', $room['id'])
			->where('nickname', $nickname)
			->where('voter_key !=', $voter_key !== '' ? $voter_key : '-')
			->get('t_vote_voters')
			->row_array();

		if ($dup)
		{
			return array(
				'ok'  => FALSE,
				'msg' => '이미 "' . $nickname . '" 님이 투표했습니다. '
					. '동명이인이면 구분되는 이름으로 넣어주세요 (예: ' . $nickname . 'B).',
			);
		}

		if ($voter_key === '')
		{
			$voter_key = ds_token(32);
		}

		$this->db->trans_start();

		if ($existing)
		{
			$voter_id = (int) $existing['id'];

			$upd = array(
				'comment'  => $this->clean($comment, 200),
				'voted_at' => date('Y-m-d H:i:s'),
			);

			// invite 모드에서는 이름이 방장이 정한 값이므로 본인이 바꾸지 못한다.
			if ( ! (isset($room['mode']) && $room['mode'] === 'invite'))
			{
				$upd['nickname'] = $nickname;
			}

			$this->db->where('id', $voter_id)->update('t_vote_voters', $upd);
			$this->db->where('voter_id', $voter_id)->delete('t_vote_ballots');
		}
		else
		{
			$this->db->insert('t_vote_voters', array(
				'room_id'   => $room['id'],
				'voter_key' => $voter_key,
				'nickname'  => $nickname,
				'comment'   => $this->clean($comment, 200),
				'voted_at'  => date('Y-m-d H:i:s'),
			));

			$voter_id = (int) $this->db->insert_id();
		}

		foreach ($option_ids as $oid)
		{
			$this->db->insert('t_vote_ballots', array(
				'room_id'   => $room['id'],
				'option_id' => $oid,
				'voter_id'  => $voter_id,
			));
		}

		$this->db->trans_complete();

		if ($this->db->trans_status() === FALSE)
		{
			return array('ok' => FALSE, 'msg' => '저장 중 오류가 발생했습니다.');
		}

		return array('ok' => TRUE, 'msg' => '투표가 반영되었습니다.', 'voter_key' => $voter_key);
	}

	/** 방장이 투표 마감 */
	public function close(array $room, $host_key)
	{
		if ( ! hash_equals($room['host_key'], (string) $host_key))
		{
			return array('ok' => FALSE, 'msg' => '방장만 마감할 수 있습니다.');
		}

		$this->db->where('id', $room['id'])
			->update('t_vote_rooms', array('status' => 'closed'));

		return array('ok' => TRUE, 'msg' => '투표를 마감했습니다.');
	}
}
