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

	/**
	 * 로그인 시도 제한.
	 *
	 * 계정 체계가 없어 비밀번호 하나가 유일한 관문이고, 뚫리면 모든 방의
	 * 참여자 이름·선택·한마디·개인 초대 링크가 통째로 열린다. 제한이 없으면
	 * 무제한 대입이 가능하다(실측: 오답 25회가 1.37초, 약 18회/초).
	 *
	 * 카운터는 **IP 단위**로 파일에 남긴다. 세션 기반은 쿠키를 버리면
	 * 그만이라 방어가 되지 않는다. 새 테이블을 만들지 않은 이유는 스키마
	 * 변경 없이 끝나고, 이 기록이 백업·이전 가치가 없는 휘발성 데이터이기
	 * 때문이다. 저장 위치는 `application/cache/` 이며 `application/.htaccess`
	 * 가 이 디렉터리 전체를 웹에서 차단한다.
	 */
	const LOGIN_MAX_FAIL = 8;      // 창(window) 안에서 허용하는 오답 횟수
	const LOGIN_WINDOW   = 600;    // 오답 집계 구간 (초)
	const LOGIN_LOCK     = 900;    // 초과 시 잠금 시간 (초)

	public function __construct()
	{
		parent::__construct();

		$this->load->model('vote_model');
		$this->load->model('place_model');
		$this->output->set_header('Cache-Control: no-store');
		$this->output->set_header('X-Robots-Tag: noindex, nofollow');

		// redirect() 는 Output 클래스를 거치지 않고 header()+exit 로 끝난다.
		// 그래서 set_header() 만 두면 로그인 유도·로그아웃 같은 **리다이렉트 응답에는
		// X-Robots-Tag 가 붙지 않는다** (실측: 비로그인 /admin 의 307 응답에 없음).
		// 여기서 한 번 직접 내보내 두면 리다이렉트도 덮인다 — 렌더되는 응답에서는
		// 위 set_header 가 같은 이름을 replace 로 다시 내보내므로 중복되지 않는다.
		header('X-Robots-Tag: noindex, nofollow');
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
	//  로그인 시도 제한 (IP 단위)
	// =========================================================

	/** 시도 기록 파일. 웹에서 열리지 않는 application/ 아래에 둔다. */
	protected function throttle_path()
	{
		return APPPATH . 'cache/ds_admin_login.json';
	}

	/**
	 * 기록을 읽어 만료분을 걷어낸다.
	 *
	 * 실패하면 빈 배열을 돌려준다 — 파일을 못 읽는다고 로그인이 막히면
	 * 관리자가 자기 화면에서 잠기므로 **열리는 방향으로** 실패한다.
	 *
	 * @return array ip => array('n' => 오답수, 'first' => 첫 오답, 'until' => 잠금해제)
	 */
	protected function throttle_load()
	{
		$path = $this->throttle_path();

		if ( ! is_file($path))
		{
			return array();
		}

		$raw  = @file_get_contents($path);
		$data = ($raw === FALSE) ? NULL : json_decode($raw, TRUE);

		if ( ! is_array($data))
		{
			return array();
		}

		$now  = time();
		$out  = array();

		foreach ($data as $ip => $rec)
		{
			if ( ! is_array($rec))
			{
				continue;
			}

			$until = isset($rec['until']) ? (int) $rec['until'] : 0;
			$first = isset($rec['first']) ? (int) $rec['first'] : 0;

			// 잠금이 살아 있거나 집계 구간 안이면 남긴다
			if ($until > $now OR ($first + self::LOGIN_WINDOW) > $now)
			{
				$out[(string) $ip] = array(
					'n'     => isset($rec['n']) ? (int) $rec['n'] : 0,
					'first' => $first,
					'until' => $until,
				);
			}
		}

		return $out;
	}

	/** 기록 저장. 쓰기 실패는 무시한다(위 주석의 이유와 같다). */
	protected function throttle_save(array $data)
	{
		@file_put_contents($this->throttle_path(), json_encode($data), LOCK_EX);
	}

	/**
	 * 잠겨 있으면 남은 초, 아니면 0.
	 * 잠긴 동안에는 비밀번호 비교 자체를 하지 않는다.
	 */
	protected function login_locked_for($ip)
	{
		$data = $this->throttle_load();

		if ( ! isset($data[$ip]))
		{
			return 0;
		}

		$left = (int) $data[$ip]['until'] - time();

		return ($left > 0) ? $left : 0;
	}

	/** 오답 1회 기록. 한도를 넘으면 잠근다. */
	protected function login_fail($ip)
	{
		$data = $this->throttle_load();
		$now  = time();

		if ( ! isset($data[$ip]) OR ($data[$ip]['first'] + self::LOGIN_WINDOW) <= $now)
		{
			$data[$ip] = array('n' => 0, 'first' => $now, 'until' => 0);
		}

		$data[$ip]['n']++;

		if ($data[$ip]['n'] >= self::LOGIN_MAX_FAIL)
		{
			$data[$ip]['until'] = $now + self::LOGIN_LOCK;
			$data[$ip]['n']     = 0;
			$data[$ip]['first'] = $now;
		}

		$this->throttle_save($data);
	}

	/** 정답이면 그 IP 기록을 지운다 */
	protected function login_ok($ip)
	{
		$data = $this->throttle_load();

		if (isset($data[$ip]))
		{
			unset($data[$ip]);
			$this->throttle_save($data);
		}
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

		// 쿼리스트링은 어떤 키든 배열로 만들 수 있다(`?q[]=x`, `?status[]=open`).
		// (string) 캐스팅에 배열이 들어가면 "Array to string conversion" 경고가
		// 응답 본문 앞에 섞여 화면이 깨진다(개발 환경은 display_errors=1).
		// 실측: /admin?status[]=open -> Admin.php 이 줄에서 경고,
		//       /admin?q[]=x        -> Vote_model::admin_rooms() 에서 경고.
		// 다른 입구(Recommender::normalize_criteria, Vote_model::clean)와 같은
		// 방식으로 스칼라만 통과시키고 나머지는 없는 값으로 본다.
		$status = $this->input->get('status', TRUE);
		$q      = $this->input->get('q', TRUE);
		$status = is_scalar($status) ? (string) $status : '';
		$q      = is_scalar($q) ? (string) $q : '';

		$rooms = $this->vote_model->admin_rooms(array(
			'status' => $status,
			'q'      => $q,
			'limit'  => 100,
		));

		$this->render('admin/index', array(
			'summary' => $this->vote_model->admin_summary(),
			'rooms'   => $rooms,
			'status'  => $status,
			'q'       => $q,
		), array(
			'title'      => '전체 투표 현황 · DINNERSPOT',
			'nav'        => 'admin',
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
			'nav'        => 'admin',
			'body_class' => 'page-admin-room',
		));
	}

	/**
	 * 지역 사전(t_areas) 현황.
	 *
	 * **읽기 전용이다. 추가/수정 폼을 두지 않는다.** 기준 데이터의 정본은
	 * `sql/02_seed.sql` 이고, 화면에서 DB만 고치면 시드를 다시 넣는 순간
	 * `ON DUPLICATE KEY UPDATE` 가 그 값을 덮어써 조용히 사라진다.
	 * 그래서 여기서는 "무엇이 들어 있고 무엇이 비었는지" 를 보여주고
	 * 고치는 곳은 시드 파일로 안내한다. 좌표 보정만 예외로 실행할 수 있는데,
	 * 그건 사람이 입력한 값이 아니라 미확인(0)을 실측으로 채우는 작업이다.
	 */
	public function areas()
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

		$this->load->library('naver_local');

		$this->render('admin/areas', array(
			'stats'        => $this->place_model->area_stats(),
			'pending'      => $this->place_model->areas_pending_geo(200),
			'groups'       => $this->place_model->areas_grouped(),
			'naver_ready'  => $this->naver_local->is_enabled(),
			'geo_result'   => $this->session->flashdata('geo_result'),
		), array(
			'title'      => '지역 사전 · 관리자',
			'nav'        => 'admin',
			'body_class' => 'page-admin-areas',
		));
	}

	/**
	 * 좌표 미확인 지역을 네이버 지역검색으로 채운다.
	 *
	 * `/guide` 의 같은 버튼(`Home::geocode`)과 같은 모델 메서드를 쓴다.
	 * 관리자 화면에도 두는 이유는, 지역을 새로 넣은 직후 확인·보정·결과 확인이
	 * 한 화면에서 끝나야 갱신 작업이 이어지기 때문이다.
	 */
	public function areas_geocode()
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

		if ($this->input->method(TRUE) !== 'POST')
		{
			redirect('admin/areas');

			return;
		}

		$res = $this->place_model->geocode_pending_areas(30);

		if ($res['skipped'] !== '')
		{
			$msg = '좌표 보정을 건너뛰었습니다: ' . $res['skipped'];
		}
		else
		{
			$msg = $res['done'] . '곳의 좌표를 채웠습니다.';

			if ( ! empty($res['failed']))
			{
				$msg .= ' 못 찾은 곳: ' . implode(', ', array_slice($res['failed'], 0, 10));
			}
		}

		$this->session->set_flashdata('geo_result', $msg);
		redirect('admin/areas');
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
		$ip    = (string) $this->input->ip_address();
		$lock  = $this->login_locked_for($ip);

		if ($this->input->method(TRUE) === 'POST')
		{
			if ($lock > 0)
			{
				// 잠긴 동안에는 비밀번호를 **비교하지 않는다.** 남은 시간만 알려준다.
				$error = '오답이 너무 많아 잠시 잠겼습니다. '
					. ceil($lock / 60) . '분 뒤에 다시 시도해주세요.';
				log_message('error', 'Admin login locked out for ' . $ip);
			}
			else
			{
				// password[]=x 로 배열이 오면 (string) 캐스팅이
				// "Array to string conversion" 경고를 내고 그 HTML 이 로그인 화면
				// 앞에 붙는다(실측: POST /admin/login 에 password[]=x).
				// 비밀번호는 항상 문자열 하나다. 배열은 빈 값으로 본다.
				$pw = $this->input->post('password', FALSE);
				$pw = is_scalar($pw) ? (string) $pw : '';

				// 타이밍 공격 방지를 위해 hash_equals 로 비교한다
				if (hash_equals($this->admin_password(), $pw))
				{
					$this->login_ok($ip);

					// 권한이 올라가는 순간 세션 ID 를 새로 발급한다(세션 고정 방어).
					// 이걸 빼면 남이 미리 심어둔 세션 ID 가 그대로 관리자 세션이 된다.
					// 반드시 플래그를 넣기 전에 — 뒤에 하면 방금 넣은 값이 버려진다.
					$this->session->sess_regenerate(TRUE);

					$this->session->set_userdata(self::SESS, TRUE);
					redirect('admin');

					return;
				}

				$this->login_fail($ip);
				$lock = $this->login_locked_for($ip);

				$error = ($lock > 0)
					? ('오답이 너무 많아 ' . ceil($lock / 60) . '분간 잠갔습니다.')
					: '비밀번호가 맞지 않습니다.';

				log_message('error', 'Admin login failed from ' . $ip);
			}
		}

		$this->render('admin/login', array('error' => $error), array(
			'title'      => '관리자 · DINNERSPOT',
			'nav'        => 'admin',
			'body_class' => 'page-admin-login',
		));
	}

	public function logout()
	{
		// 여기도 관문을 통과시킨다. 빼먹으면 비밀번호가 없을 때 다른 경로는 404 인데
		// 이 주소만 리다이렉트를 돌려줘서 관리자 화면이 있다는 사실이 드러난다.
		if ( ! $this->require_enabled())
		{
			return;
		}

		$this->session->unset_userdata(self::SESS);

		// 권한이 내려가는 순간에도 세션 ID 를 새로 발급한다. 로그인 전에 쓰던
		// ID 를 남겨두면 그 ID 를 아는 사람이 다시 붙어볼 여지가 남는다.
		// 데이터는 새 ID 로 옮겨가므로 방장 표시(ds_host_*)는 유지된다.
		$this->session->sess_regenerate(TRUE);

		redirect('admin/login');
	}
}
