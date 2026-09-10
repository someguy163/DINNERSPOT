<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 추천 유스케이스 오케스트레이션
 *
 *   조건 정규화 → (필요시) 네이버 수집 → DB 후보 조회 → 점수화 → 결과
 *
 * 컨트롤러(화면/API)는 이 클래스만 호출하면 된다.
 */
class Spot_service {

	/** @var CI_Controller */
	protected $CI;

	public function __construct()
	{
		$this->CI = &get_instance();
		$this->CI->load->model('place_model');
		$this->CI->load->library('recommender');
		$this->CI->load->library('naver_local');
		$this->CI->config->load('dinnerspot', TRUE, TRUE);
	}

	protected function cfg($key, $default = NULL)
	{
		$v = $this->CI->config->item($key, 'dinnerspot');

		return ($v === NULL) ? $default : $v;
	}

	/**
	 * 추천 실행
	 *
	 * @param  array $input 원본 요청값
	 * @return array
	 */
	public function recommend(array $input)
	{
		$t0 = microtime(TRUE);

		$c      = $this->CI->recommender->normalize_criteria($input);
		$origin = $this->resolve_origin($c);

		// resolve_origin 이 좌표를 채워줄 수 있다
		$c['lat'] = $origin['lat'];
		$c['lng'] = $origin['lng'];

		// 기준점으로 **실제로 쓴 것**을 criteria 에 되돌려 적는다.
		// ds_criteria_params() 가 area_id 를 보고 좌표를 실을지 정하므로
		// criteria 가 요청값 그대로 남아 있으면 페이지 링크에서 기준점이 바뀐다.
		//   - 없는/죽은 area_id + 좌표: 1페이지는 좌표 기준인데 링크는
		//     area_id 만 싣고 좌표를 버려 2페이지가 '전체' 가 됐다
		//     (실측: area_id=99999&lat=37.5663&lng=126.9779 -> 2페이지 200곳 '전체').
		//     area_id=-1 도 같다 — ! empty(-1) 이 TRUE 라서 통과한다.
		//   - keyword 로 지역을 찾은 경우: area_id 가 0 이라 링크에 지역 사전에서
		//     채운 좌표가 실려 나가 2페이지의 origin.type 이 area -> coords 로 바뀌었다.
		// 좌표는 아래에서 $c['lat']/$c['lng'] 로 이미 확정돼 있으므로 정보 손실은 없다.
		$c['area_id'] = ($origin['type'] === 'area') ? (int) $origin['id'] : 0;

		// 자유 입력 키워드의 업종 의도(점수용 소프트 신호).
		// 음식 종류를 직접 골랐으면 그것이 이미 하드 필터라서 필요 없다.
		// 사전 조회는 DB 라서 순수 계산 계층(Recommender)에서 할 수 없고,
		// 쿼리 조립 전인 여기서 미리 계산해 넣는다.
		$c['keyword_codes'] = ($c['keyword'] !== '' && empty($c['categories']))
			? $this->CI->place_model->codes_matching_keyword($c['keyword'])
			: array();

		$enabled = $this->CI->naver_local->is_enabled();

		// 1) 네이버 수집 (키가 있고, 캐시가 얕을 때만)
		$naver = array('used' => FALSE, 'new' => 0, 'calls' => 0, 'error' => '');

		if ($enabled && $this->should_fetch($c, $origin))
		{
			$queries = $this->build_queries($c, $origin);
			$rows    = $this->CI->naver_local->search_many($queries);

			$naver['used']  = TRUE;
			$naver['calls'] = $this->CI->naver_local->call_count();
			$naver['error'] = $this->CI->naver_local->last_error();
			$naver['new']   = $this->CI->place_model->upsert_naver($rows);
		}

		// 2) 후보 출처 결정
		$c['source_mode'] = $this->resolve_source_mode($enabled, $c);

		// 3) DB 후보
		$candidates = $this->CI->place_model->find_candidates($c);

		// 네이버 모드인데 수집된 게 하나도 없으면 빈 화면을 주는 대신 DB 로 폴백한다.
		if (empty($candidates) && $c['source_mode'] === 'naver')
		{
			$c['source_mode']  = 'db';
			$c['fell_back']    = TRUE;
			$candidates        = $this->CI->place_model->find_candidates($c);
		}

		// 4) 점수화 — 전체 후보에 점수를 매겨 순위를 만든다.
		//    페이지 크기로 자르기 전에 전체를 세워야 21위 이하가 존재한다.
		$limit  = (int) $this->cfg('result_limit', 200);
		$ranked = $this->CI->recommender->rank($candidates, $c, $limit);

		$cat_map = $this->CI->place_model->category_map();

		// 5) 자동완성용 색인 — **걸러내기 전** 전체 순위로 만든다.
		//    거른 뒤로 만들면 한 번 걸러낸 다음에는 다른 말을 제안할 수 없다.
		//    한 페이지(10건)가 아니라 전체(수십~200건)를 대상으로 제안해야
		//    "목록에서 찾기" 가 의미가 있다.
		$index = array();

		foreach ($ranked as $n => $r)
		{
			$code = $r['category_code'];

			// 진짜 순위를 행에 박아둔다. 목록을 걸러낸 뒤에도 "37위" 로
			// 보여야 한다 — 걸러낸 결과에 01,02… 를 다시 붙이면
			// 37위인 곳이 1위로 보인다.
			$ranked[$n]['rank_no'] = $n + 1;

			// 주소는 앞 4토막(시도·시군구·도로명·번호)만 쓴다. 드롭다운에서
			// 지점을 구별하는 게 목적이라 '지천빌딩 지하1층' 같은 꼬리는
			// 한 줄을 넘겨 잘리기만 하고, 색인 크기도 그만큼 커진다.
			$addr = (string) ($r['road_address'] ?: $r['address']);
			$addr = implode(' ', array_slice(preg_split('/\s+/u', trim($addr), -1, PREG_SPLIT_NO_EMPTY), 0, 4));

			$index[] = array(
				'id'    => (int) $r['id'],
				'rank'  => $n + 1,
				'name'  => $r['name'],
				'cat'   => isset($cat_map[$code]) ? $cat_map[$code]['label'] : '기타',
				'addr'  => $addr,
				'score' => (float) $r['score'],
			);
		}

		// 6) 목록 내 검색 — 점수는 건드리지 않고 표시만 줄인다
		if ($c['find'] !== '')
		{
			$ranked = $this->filter_by_find($ranked, $c['find'], $cat_map);
		}

		// 7) 페이지 자르기
		$total = count($ranked);
		$per   = max(1, (int) $c['per_page']);
		$pages = max(1, (int) ceil($total / $per));

		// 마지막 페이지를 넘는 요청은 빈 목록 대신 마지막 페이지를 준다.
		// 예전 링크를 다시 열거나, 조건을 좁힌 뒤 5페이지가 3페이지로
		// 줄어든 경우에 아무것도 없는 화면을 보게 되는 것을 막는다.
		$page      = min(max(1, (int) $c['page']), $pages);
		$c['page'] = $page;

		$offset  = ($page - 1) * $per;
		$results = array_slice($ranked, $offset, $per);

		// 8) 표시용 가공 — 이 페이지에 나가는 것만 가공한다
		foreach ($results as &$r)
		{
			$r = $this->decorate($r, $cat_map);
		}
		unset($r);

		$elapsed = (int) round((microtime(TRUE) - $t0) * 1000);

		// 조회 횟수는 실제로 화면에 나간 것만 올린다.
		// 로그에는 전체 순위 건수를 남긴다 — 페이지 크기를 바꿨을 때
		// 추천 품질이 달라진 것처럼 보이면 안 된다.
		$this->CI->place_model->bump_hits(array_column($results, 'id'));
		$this->CI->place_model->log_search(
			$origin['label'], $c, $total, $naver['used'], $elapsed,
			$this->CI->input->ip_address()
		);

		return array(
			'criteria'       => $c,
			'origin'         => $origin,
			'results'        => $results,
			'page'           => $page,
			'per_page'       => $per,
			'total'          => $total,
			'total_pages'    => $pages,
			'offset'         => $offset,
			// 걸러내기 전 전체 순위 건수. find 가 없으면 total 과 같다.
			'total_all'      => count($index),
			'find'           => $c['find'],
			'index'          => $index,
			'candidate_cnt'  => count($candidates),
			'elapsed_ms'     => $elapsed,
			'naver'          => $naver,
			'naver_enabled'  => $enabled,
			'source_mode'    => $c['source_mode'],
			'fell_back'      => ! empty($c['fell_back']),
			'map_key'        => (string) $this->cfg('naver_map_key_id', ''),
		);
	}

	/**
	 * 좌표를 사람이 읽는 위치 설명으로.
	 *
	 * 등록된 지역(t_areas)에서 가장 가까운 곳을 기준으로 말한다.
	 * 멀면 멀다고 말한다 — 200km 떨어진 역을 "근처" 라고 하면 거짓이고,
	 * 그 경우 이 지역 데이터가 얇다는 것도 같이 알려야 한다.
	 *
	 * 좌표 자체는 뒤에 붙이지 않는다. 사람이 확인할 값이 아니고,
	 * 이 문장이 화면의 한 줄이라 길어지면 뒤가 잘린다.
	 */
	public function describe_coords($lat, $lng, $near)
	{
		if ( ! $near)
		{
			// 좌표는 유효한데 비교할 지역이 없다(사전이 비었거나 전부 미확인).
			// 없는 지명을 지어내지 않고 좌표를 그대로 보인다.
			return sprintf('내 위치 · %.5f, %.5f', (float) $lat, (float) $lng);
		}

		$d    = (int) $near['distance_m'];
		$dist = ($d >= 1000) ? (round($d / 1000, 1) . 'km') : ($d . 'm');
		$admin = trim($near['sido'] . ' ' . $near['sigungu']);

		if ($d > 5000)
		{
			return sprintf(
				'내 위치 · 등록된 지역 중 가장 가까운 곳은 %s(%s) — 이 근처는 데이터가 적을 수 있습니다',
				$near['name'], $dist
			);
		}

		// "강남역에서 0m" 는 말이 안 된다. 역 좌표는 대표점 하나뿐이고
		// 브라우저 위치도 오차가 있어서 수십 m 안쪽의 숫자는 의미가 없다.
		$where = ($d < 50)
			? ($near['name'] . ' 바로 앞')
			: sprintf('%s에서 %s', $near['name'], $dist);

		$out = ($admin !== '' ? $admin . ' · ' : '') . $where;

		if ( ! empty($near['line_info']))
		{
			$out .= ' (' . $near['line_info'] . ')';
		}

		return $out;
	}

	/**
	 * 순위표 안에서 말로 걸러낸다.
	 *
	 * 점수와 순서는 손대지 않는다 — 이건 검색이 아니라 이미 나온 목록의
	 * 필터다. 상호명·주소·업종을 훑고, 대소문자와 공백은 무시한다.
	 * 공백으로 나뉜 여러 낱말은 **전부** 들어있어야 한다(AND).
	 *
	 * DB 로 내리지 않고 여기서 하는 이유: 점수를 매긴 뒤라야 "순위 몇 위"
	 * 를 유지하며 걸러낼 수 있고, 자동완성 색인도 같은 집합에서 나온다.
	 */
	protected function filter_by_find(array $rows, $find, array $cat_map)
	{
		$norm = function ($s) {
			// 공백 제거 + 소문자. '강남 역' 과 '강남역' 을 같게 본다.
			return preg_replace('/\s+/u', '', mb_strtolower((string) $s, 'UTF-8'));
		};

		$terms = preg_split('/\s+/u', trim($find), -1, PREG_SPLIT_NO_EMPTY);
		$terms = array_map($norm, $terms);
		$terms = array_values(array_filter($terms, function ($t) { return $t !== ''; }));

		if ( ! $terms)
		{
			return $rows;
		}

		$out = array();

		foreach ($rows as $r)
		{
			$code = $r['category_code'];
			$hay  = $norm(implode(' ', array(
				$r['name'],
				$r['road_address'],
				$r['address'],
				$r['category_raw'],
				isset($cat_map[$code]) ? $cat_map[$code]['label'] : '',
				$r['tags'],
			)));

			$hit = TRUE;

			foreach ($terms as $t)
			{
				if (mb_strpos($hay, $t, 0, 'UTF-8') === FALSE)
				{
					$hit = FALSE;
					break;
				}
			}

			if ($hit)
			{
				$out[] = $r;
			}
		}

		return $out;
	}

	/**
	 * 후보 출처 결정.
	 *
	 * config 는 'naver' 가 기본이지만, 키가 없으면 네이버 데이터가 애초에
	 * 존재하지 않으므로 자동으로 'db' 로 내린다. 설정 하나 때문에
	 * 빈 화면을 보게 되는 일이 없도록 하는 게 목적이다.
	 */
	protected function resolve_source_mode($naver_enabled, array $c)
	{
		// 요청으로 강제 지정 (디버깅/비교용)
		if ( ! empty($c['source_mode'])
			&& in_array($c['source_mode'], array('naver', 'db', 'both'), TRUE))
		{
			return $c['source_mode'];
		}

		$mode = (string) $this->cfg('source_mode', 'naver');

		if ( ! in_array($mode, array('naver', 'db', 'both'), TRUE))
		{
			$mode = 'naver';
		}

		if ($mode === 'naver' && ! $naver_enabled)
		{
			return 'db';
		}

		return $mode;
	}

	/**
	 * 검색 기준점 결정
	 *   1순위: area_id
	 *   2순위: 직접 넘어온 lat/lng (내 위치)
	 *   3순위: 키워드로 areas 매칭
	 */
	protected function resolve_origin(array $c)
	{
		if ($c['area_id'] > 0)
		{
			$a = $this->CI->place_model->area($c['area_id']);

			if ($a)
			{
				return array(
					'type'  => 'area',
					'id'    => (int) $a['id'],
					'label' => $a['name'],
					'sub'   => trim($a['sido'] . ' ' . $a['sigungu']),
					'lat'   => (float) $a['lat'],
					'lng'   => (float) $a['lng'],
				);
			}
		}

		if ( ! empty($c['lat']) && ! empty($c['lng']))
		{
			// 좌표만 찍어주면 사용자는 자기가 어디로 검색했는지 알 수 없다.
			// '37.49793, 127.02758' 은 사람이 읽는 값이 아니다.
			// 등록된 지역 중 가장 가까운 곳으로 위치를 설명한다.
			$near = $this->CI->place_model->nearest_area($c['lat'], $c['lng']);

			return array(
				'type'  => 'coords',
				'id'    => 0,
				// 좌표는 "내 위치" 버튼에서도, 지도에서 핀을 옮겨서도 온다.
				// 서버는 둘을 구분할 수 없으므로 어느 쪽이든 맞는 말을 쓴다 —
				// '현재 위치' 라고 하면 지도에서 다른 동네를 찍은 경우에 거짓이 된다.
				// 정확히 어디인지는 아래 sub 가 말해준다.
				'label' => $c['keyword'] !== '' ? $c['keyword'] : '지정한 위치',
				'sub'   => $this->describe_coords($c['lat'], $c['lng'], $near),
				'near'  => $near ? array(
					'id'         => (int) $near['id'],
					'name'       => $near['name'],
					'sido'       => $near['sido'],
					'sigungu'    => $near['sigungu'],
					'line_info'  => $near['line_info'],
					'distance_m' => (int) $near['distance_m'],
				) : NULL,
				'lat'   => (float) $c['lat'],
				'lng'   => (float) $c['lng'],
			);
		}

		if ($c['keyword'] !== '')
		{
			$a = $this->CI->place_model->find_area_by_name($c['keyword']);

			if ($a)
			{
				return array(
					'type'  => 'area',
					'id'    => (int) $a['id'],
					'label' => $a['name'],
					'sub'   => trim($a['sido'] . ' ' . $a['sigungu']),
					'lat'   => (float) $a['lat'],
					'lng'   => (float) $a['lng'],
				);
			}
		}

		// 좌표를 못 찾으면 키워드 검색으로 폴백
		return array(
			'type'  => 'keyword',
			'id'    => 0,
			'label' => $c['keyword'] !== '' ? $c['keyword'] : '전체',
			'sub'   => '좌표 없음 · 이름/주소 기준 검색',
			'lat'   => 0.0,
			'lng'   => 0.0,
		);
	}

	/**
	 * 네이버를 새로 때릴지 판단.
	 * 최근 N시간 안에 같은 지역을 수집했다면 DB 캐시로 충분하다.
	 */
	protected function should_fetch(array $c, array $origin)
	{
		if ($origin['label'] === '전체')
		{
			return FALSE;
		}

		// 재호출 억제는 Naver_local 이 **질의 단위**로 한다
		// (t_naver_queries). 여기서 지역 단위로 한 번 더 막으면,
		// 같은 지역에서 새 카테고리를 골랐을 때 그 카테고리 질의가
		// 영원히 나가지 않아 해당 업종이 후보에 절대 들어오지 않는다.
		// (실측: 강남역에서 "카페/디저트" 선택 -> 호출 0회, 카페 0건)
		//
		// 이미 던진 질의는 Naver_local 이 건너뛰므로 호출 낭비는 없다.
		return TRUE;
	}

	/**
	 * 네이버 지역검색용 질의어 조합.
	 *
	 * 한 번에 5건만 오므로 "지역 + 성격" 조합으로 여러 번 나눠 던진다.
	 */
	protected function build_queries(array $c, array $origin)
	{
		$base = $origin['label'];

		if ($base === '' OR $base === '전체')
		{
			$base = $c['keyword'];
		}

		$base = trim($base);

		if ($base === '')
		{
			return array();
		}

		$queries = array();

		// 선택한 음식 종류가 있으면 그것부터
		if ( ! empty($c['categories']))
		{
			$labels = $this->CI->place_model->category_map();

			foreach ($c['categories'] as $code)
			{
				if (isset($labels[$code]))
				{
					$queries[] = $base . ' ' . str_replace('/', ' ', $labels[$code]['label']);
				}
			}
		}

		// 목적별 보조 질의
		$by_purpose = array(
			'any'    => array(),   // 목적 무관 — 아래 업종 풀로만 넓게 훑는다
			'team'   => array('회식', '고기집', '단체'),
			'client' => array('한정식', '룸식당', '접대'),
			'cheap'  => array('가성비', '백반'),
			'after'  => array('술집', '호프', '포차'),
			'quiet'  => array('룸식당', '조용한 식당', '개별룸'),
			'date'   => array('분위기 좋은', '카페', '데이트'),
		);

		$extra = isset($by_purpose[$c['purpose']]) ? $by_purpose[$c['purpose']] : array();

		foreach ($extra as $e)
		{
			$queries[] = $base . ' ' . $e;
		}

		$queries[] = $base . ' 맛집';

		/* 업종 풀을 덧붙인다.
		 *
		 * 지역검색 API 는 **호출당 정확히 5건**만 주고 start 파라미터가 무시된다
		 * (실측: display=30 · start=6 · start=11 모두 같은 5건, total=5).
		 * 그래서 건수를 늘리는 방법은 "질의를 다르게 던지는 것" 뿐이다.
		 *
		 * 병점역 실측 — 목적 질의 4개로는 13곳, 업종 풀까지 19개를 던지면 53곳.
		 * 아래 순서는 그 실측에서 새로 나온 곳이 많은 순이다
		 * (중국집·치킨·횟집·분식 각 +5, 국밥·술집·카페·일식·양식 각 +3).
		 * '한식'·'찌개'·'식당' 은 새것이 0곳이라 뺐다 — 앞선 질의와 겹친다.
		 *
		 * 실제로 몇 개를 던질지는 naver_max_queries 가 자른다(호출 1회 = 질의 1개).
		 */
		$pool = $this->cfg('naver_query_pool', array(
			'중국집', '치킨', '횟집', '분식', '국밥', '술집', '일식', '양식',
			'카페', '삼겹살', '고기집', '뷔페', '곱창',
		));

		foreach ((array) $pool as $kw)
		{
			$kw = trim((string) $kw);

			if ($kw !== '')
			{
				$queries[] = $base . ' ' . $kw;
			}
		}

		return array_values(array_unique($queries));
	}

	/**
	 * 결과 행에 화면용 필드를 덧붙인다.
	 */
	protected function decorate(array $r, array $cat_map)
	{
		$code = $r['category_code'];
		$cat  = isset($cat_map[$code]) ? $cat_map[$code] : NULL;

		$grade = ds_score_grade($r['score']);

		$r['category_label'] = $cat ? $cat['label'] : '기타';
		$r['category_emoji'] = $cat ? $cat['emoji'] : '🍴';
		$r['price_label']    = ds_won($r['avg_price']);
		$r['distance_label'] = ds_distance_label($r['distance_m']);
		$r['grade_label']    = $grade['label'];
		$r['grade_class']    = $grade['class'];
		$r['is_sample']      = (strpos((string) $r['memo'], '샘플') !== FALSE);
		$r['party_label']    = ((int) $r['max_party'] > 0)
			? '최대 ' . (int) $r['max_party'] . '명'
			: '수용인원 미상';

		$r['badges'] = array();

		if ( ! empty($r['has_room']))    $r['badges'][] = '룸/단체석';
		if ( ! empty($r['has_parking'])) $r['badges'][] = '주차';
		if ( ! empty($r['open_late']))   $r['badges'][] = '심야';

		// 네이버 지도 검색 링크 (상세 페이지와 같은 헬퍼를 쓴다)
		$r['map_url'] = ds_naver_map_url(
			$r['name'],
			isset($r['address']) ? $r['address'] : ''
		);

		// 숫자 타입 정리 (JSON 으로 나갈 때 문자열이 되지 않도록)
		foreach (array('id', 'avg_price', 'max_party', 'review_count', 'price_level') as $k)
		{
			$r[$k] = (int) $r[$k];
		}

		foreach (array('has_room', 'has_parking', 'open_late') as $k)
		{
			$r[$k] = (bool) $r[$k];
		}

		$r['lat']    = (float) $r['lat'];
		$r['lng']    = (float) $r['lng'];
		$r['rating'] = (float) $r['rating'];

		unset($r['source_key'], $r['hit_count'], $r['pick_count'], $r['is_active']);

		return $r;
	}

	/**
	 * 화면 초기 데이터 (지역/카테고리/목적/프리셋)
	 */
	public function form_meta()
	{
		$enabled = $this->CI->naver_local->is_enabled();

		return array(
			'areas'          => $this->CI->place_model->areas(),
			'area_groups'    => $this->CI->place_model->areas_grouped(),
			'categories'     => $this->CI->place_model->categories(),
			'source_mode'    => $this->resolve_source_mode($enabled, array()),
			'source_counts'  => $this->CI->place_model->source_counts(),
			'pending_geo'    => $this->CI->place_model->pending_geo_count(),
			'purposes'       => $this->CI->recommender->purposes(),
			'budget_presets' => $this->cfg('budget_presets', array()),
			'radius_options' => $this->cfg('radius_options', array(500, 800, 1500)),
			'defaults'       => array(
				'radius'    => (int) $this->cfg('default_radius', 800),
				'headcount' => (int) $this->cfg('default_headcount', 6),
				'budget'    => (int) $this->cfg('default_budget', 25000),
				/* 홈 화면의 목적 select 이 "첫 번째 항목이 기본" 에 의존하지
				 * 않게 기본값을 명시한다. 목적 목록 맨 앞에 '무엇이든' 을
				 * 넣었으므로, 이게 없으면 기본이 조용히 바뀐다. */
				'purpose'   => (string) $this->cfg('default_purpose', 'team'),
			),
			'naver_enabled'  => $this->CI->naver_local->is_enabled(),
			'map_key'        => (string) $this->cfg('naver_map_key_id', ''),
			'vote'           => array(
				'min' => (int) $this->cfg('vote_min_options', 2),
				'max' => (int) $this->cfg('vote_max_options', 6),
			),
		);
	}
}
