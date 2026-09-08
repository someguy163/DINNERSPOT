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

		// 3) 점수화
		$limit   = (int) $this->cfg('result_limit', 20);
		$results = $this->CI->recommender->rank($candidates, $c, $limit);

		// 4) 표시용 가공
		$cat_map = $this->CI->place_model->category_map();

		foreach ($results as &$r)
		{
			$r = $this->decorate($r, $cat_map);
		}
		unset($r);

		$elapsed = (int) round((microtime(TRUE) - $t0) * 1000);

		$this->CI->place_model->bump_hits(array_column($results, 'id'));
		$this->CI->place_model->log_search(
			$origin['label'], $c, count($results), $naver['used'], $elapsed,
			$this->CI->input->ip_address()
		);

		return array(
			'criteria'       => $c,
			'origin'         => $origin,
			'results'        => $results,
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
			return array(
				'type'  => 'coords',
				'id'    => 0,
				'label' => $c['keyword'] !== '' ? $c['keyword'] : '현재 위치',
				'sub'   => sprintf('%.5f, %.5f', $c['lat'], $c['lng']),
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

		$hours = (int) $this->cfg('naver_cache_hours', 24);

		if ($hours <= 0)
		{
			return TRUE;
		}

		// 기준점 근처를 최근에 수집한 적이 있는가.
		//
		// "몇 건 이상 쌓였나" 로 판단하면 안 된다. 한 번 수집하면 보통 14~19곳이
		// 들어오는데 임계값을 15로 두면 14곳인 지역은 매 요청마다 다시 호출한다.
		// 게다가 같은 질의를 다시 던져봐야 같은 결과라 호출만 낭비된다.
		// 그래서 "최근에 한 번이라도 수집했으면 건너뛴다" 로 본다.
		if (empty($origin['lat']))
		{
			return TRUE;
		}

		$d_lat = ($c['radius'] * 1.8) / 111320;
		$d_lng = $d_lat / max(0.1, cos(deg2rad($origin['lat'])));

		$row = $this->CI->db
			->select('COUNT(*) AS cnt', FALSE)
			->from('t_places')
			->where('source', 'naver')
			->where('synced_at >=', date('Y-m-d H:i:s', time() - $hours * 3600))
			->where('lat >=', $origin['lat'] - $d_lat)
			->where('lat <=', $origin['lat'] + $d_lat)
			->where('lng >=', $origin['lng'] - $d_lng)
			->where('lng <=', $origin['lng'] + $d_lng)
			->get()
			->row_array();

		$min = (int) $this->cfg('naver_recollect_min', 1);

		return ((int) $row['cnt'] < max(1, $min));
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
			'team'   => array('회식', '고기집', '단체'),
			'client' => array('한정식', '룸식당', '접대'),
			'cheap'  => array('맛집', '가성비', '백반'),
			'after'  => array('술집', '호프', '포차'),
			'quiet'  => array('룸식당', '조용한 식당', '개별룸'),
		);

		$extra = isset($by_purpose[$c['purpose']]) ? $by_purpose[$c['purpose']] : array('맛집');

		foreach ($extra as $e)
		{
			$queries[] = $base . ' ' . $e;
		}

		$queries[] = $base . ' 맛집';

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

		// 네이버 지도 길찾기/검색 링크
		$r['map_url'] = 'https://map.naver.com/p/search/' . rawurlencode($r['name']);

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
