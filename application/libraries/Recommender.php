<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 회식 장소 추천 점수 엔진
 *
 * 후보 장소 목록 + 검색 조건을 받아서
 *   1) 항목별 0~100 점 산출
 *   2) 목적(purpose)에 따라 가중치 보정
 *   3) 가중 합산 -> 최종 점수
 *   4) 같은 업종 쏠림 완화 리랭킹
 *   5) 사람이 읽을 수 있는 추천 사유 생성
 * 까지 담당한다.
 *
 * 순수 계산만 하므로 DB/네이버와는 무관하다.
 */
class Recommender {

	/** @var CI_Controller */
	protected $CI;

	/** 기본 가중치 (config: score_weights) */
	protected $base_weights;

	/** 평점 베이지안 보정 상수 */
	const RATING_PRIOR_MEAN  = 3.9;
	const RATING_PRIOR_COUNT = 50;

	/** 업종 쏠림 완화 감점 상한 (diversify) */
	const DIVERSIFY_MAX_PENALTY = 10;

	/**
	 * 회식 목적 프리셋
	 *  weight : 가중치 배수
	 *  prefer : 선호 카테고리(가산점)
	 *  avoid  : 목적에 어울리지 않는 카테고리(감점)
	 *  needs  : 사실상 필수로 보는 편의옵션
	 */
	protected $purposes = array(
		/* 목적을 따지지 않는 선택지.
		 * weight 가 비어 있으면 weights_for() 가 배수를 곱하지 않고,
		 * prefer/avoid 가 비어 있으면 purpose_bonus() 가 0 을 준다.
		 * needs 가 비어 있으니 need_room/need_late 도 강제하지 않는다.
		 * 그래서 이 항목은 다른 코드를 고치지 않고도 "가중치 없음" 이 된다. */
		'any' => array(
			'label'  => '무엇이든',
			'desc'   => '목적에 따른 가점·감점 없이 거리·예산·인원만으로',
			'weight' => array(),
			'prefer' => array(),
			'avoid'  => array(),
			'needs'  => array(),
		),
		'team' => array(
			'label'  => '팀 회식',
			'desc'   => '적당한 예산에 다 같이 앉을 수 있는 곳',
			'weight' => array(),
			'prefer' => array('bbq', 'korean', 'stew', 'chicken'),
			'avoid'  => array('cafe'),
			'needs'  => array(),
		),
		'client' => array(
			'label'  => '접대 · 손님',
			'desc'   => '조용하고 평이 좋은 곳, 예산은 넉넉히',
			'weight' => array('rating' => 1.5, 'amenity' => 1.4, 'budget' => 0.6),
			'prefer' => array('korean', 'japanese', 'western', 'seafood'),
			'avoid'  => array('buffet', 'bunsik', 'hof', 'noodle', 'cafe'),
			'needs'  => array('room'),
		),
		'cheap' => array(
			'label'  => '가성비',
			'desc'   => '1인당 부담 적게, 많이 먹을 수 있는 곳',
			'weight' => array('budget' => 1.8, 'rating' => 0.8, 'amenity' => 0.7),
			'prefer' => array('stew', 'korean', 'buffet', 'bunsik', 'noodle'),
			'avoid'  => array(),
			'needs'  => array(),
		),
		'after' => array(
			'label'  => '2차 · 술자리',
			'desc'   => '늦게까지 하는 술집 위주',
			'weight' => array('distance' => 1.5, 'capacity' => 0.7),
			'prefer' => array('izakaya', 'hof', 'chicken', 'bunsik'),
			'avoid'  => array('buffet', 'korean', 'western', 'cafe'),
			'needs'  => array('late'),
		),
		'quiet' => array(
			'label'  => '조용한 자리',
			'desc'   => '대화가 되는 룸/분리된 좌석',
			'weight' => array('amenity' => 1.6, 'rating' => 1.2, 'distance' => 0.8),
			'prefer' => array('japanese', 'western', 'korean'),
			'avoid'  => array('buffet', 'hof', 'bunsik'),
			'needs'  => array('room'),
		),
		// 회식이 아닌 쓰임. 단체석·수용인원이 의미가 없어 인원 비중을 크게 낮추고
		// 평점을 크게 본다. 카페가 정상적인 후보라서
		// Place_model 이 이 목적에서만 카페 제외를 풀어준다.
		//
		// avoid 에 bbq(고기/구이)가 들어 있는 이유: 네이버 수집분의 고깃집은
		// 추정 속성(룸·심야 있음, 1인 28,000원)이 예산·조건 점수를 동시에 밀어올려서
		// 감점이 없으면 데이트 상위권을 곱창·닭갈비집이 차지한다
		// (실측: bbq 를 avoid 에 넣기 전 판교역 2위 곱창집, 성수역 3위 곱창집,
		//  여의도역 3위 곱창집, 강남역 3위 닭갈비 — 6개 지역 상위 10건의 17%가 bbq).
		// 사용자가 음식 종류로 '고기/구이' 를 직접 고르면 purpose_bonus() 가
		// 조기 반환하므로 이 감점은 적용되지 않는다.
		'date' => array(
			'label'  => '데이트',
			'desc'   => '둘이 가기 좋은 곳. 카페·디저트도 후보에 들어갑니다',
			'weight' => array('capacity' => 0.15, 'rating' => 1.6, 'distance' => 1.1, 'amenity' => 0.5),
			'prefer' => array('western', 'japanese', 'cafe', 'cafe_fr', 'izakaya'),
			'avoid'  => array('buffet', 'hof', 'stew', 'bbq'),
			'needs'  => array(),
		),
	);

	public function __construct()
	{
		$this->CI = &get_instance();
		$this->CI->config->load('dinnerspot', TRUE, TRUE);

		$w = $this->CI->config->item('score_weights', 'dinnerspot');

		$this->base_weights = is_array($w) ? $w : array(
			'distance' => 28, 'budget' => 22, 'capacity' => 16,
			'rating'   => 15, 'amenity' => 12, 'category' => 7,
		);
	}

	/** 화면에서 쓰는 목적 목록 */
	public function purposes()
	{
		$out = array();

		foreach ($this->purposes as $code => $p)
		{
			$out[] = array('code' => $code, 'label' => $p['label'], 'desc' => $p['desc']);
		}

		return $out;
	}

	/**
	 * 조건 기본값 채우기 + 정규화
	 */
	public function normalize_criteria($input)
	{
		/* ?: 를 쓰면 안 된다 — 설정값 0 이 falsy 라서 기본값으로 되돌아간다.
		 * default_headcount / default_budget 에 0("이 조건을 안 본다")을 넣을 수
		 * 있어야 하므로, 값이 없을 때(NULL)만 기본값을 쓴다. */
		$cfg = function ($k, $d) {
			$v = $this->CI->config->item($k, 'dinnerspot');

			return ($v === NULL) ? $d : $v;
		};

		// 쿼리스트링은 어떤 키든 배열로 만들 수 있다(`?purpose[]=team`).
		// 배열을 그대로 (string)/trim() 에 넘기면 PHP 8 에서 경고나 TypeError 가
		// 나고 그 출력이 JSON 앞에 섞여 프론트의 res.json() 이 실패한다.
		// (int)/(float) 는 조용히 1 이 되어 엉뚱한 좌표·인원으로 검색된다.
		// 그래서 입구에서 스칼라만 통과시키고 나머지는 기본값으로 돌린다.
		$in = function ($k, $d) use ($input) {
			return (isset($input[$k]) && is_scalar($input[$k])) ? $input[$k] : $d;
		};

		$c = array(
			'area_id'    => (int) $in('area_id', 0),
			'keyword'    => trim((string) $in('keyword', '')),
			'lat'        => (float) $in('lat', 0),
			'lng'        => (float) $in('lng', 0),
			'radius'     => (int) $in('radius', (int) $cfg('default_radius', 800)),
			'headcount'  => (int) $in('headcount', (int) $cfg('default_headcount', 6)),
			'budget'     => (int) $in('budget', (int) $cfg('default_budget', 25000)),
			// 목적의 기본값도 설정에서 읽는다. 화면 기본값(form_meta)과 어긋나면
			// purpose 없는 요청과 폼 첫 화면이 서로 다른 결과를 낸다.
			'purpose'    => (string) $in('purpose', (string) $cfg('default_purpose', 'team')),
			'categories' => array(),
			// 자유 입력 키워드가 지목한 업종 코드. 점수용 소프트 신호이며
			// Spot_service 가 Place_model::codes_matching_keyword() 로 채운다
			// (사전 조회는 DB 라서 여기서 계산할 수 없다).
			'keyword_codes' => array(),
			'need_room'    => ! empty($input['need_room']),
			'need_parking' => ! empty($input['need_parking']),
			'need_late'    => ! empty($input['need_late']),
			'no_alcohol'   => ! empty($input['no_alcohol']),
			'strict'       => ! empty($input['strict']),
			// 후보 출처 강제 지정. 비어 있으면 Spot_service 가 config 로 결정한다.
			// naver / db / both 비교용으로 쿼리스트링에 붙일 수 있다.
			'source_mode'  => (string) $in('source_mode', ''),
			// 페이징. 점수는 전체 후보에 대해 매기고 여기서 잘라낸다.
			'page'     => (int) $in('page', 1),
			'per_page' => (int) $in('per_page', (int) $cfg('per_page', 10)),
			// 결과 목록 안에서 찾는 말. keyword 와 다르다 —
			// keyword 는 "어디를 검색할지"(지역/업종)이고,
			// find 는 이미 나온 순위표에서 걸러내는 말이다.
			// 점수를 바꾸지 않고 표시만 줄인다.
			'find'     => trim((string) $in('find', '')),
		);

		if ( ! isset($this->purposes[$c['purpose']]))
		{
			$c['purpose'] = 'team';
		}

		// 목적이 요구하는 옵션을 기본으로 켜준다
		foreach ($this->purposes[$c['purpose']]['needs'] as $need)
		{
			if ($need === 'room')  $c['need_room'] = TRUE;
			if ($need === 'late')  $c['need_late'] = TRUE;
		}

		if ( ! empty($input['categories']))
		{
			$cats = is_array($input['categories'])
				? $input['categories']
				: explode(',', (string) $input['categories']);

			foreach ($cats as $code)
			{
				// `?categories[][]=bbq` 로 중첩 배열이 들어올 수 있다.
				// trim() 에 배열을 넘기면 PHP 8 에서 TypeError 로 500 이 난다.
				if ( ! is_scalar($code))
				{
					continue;
				}

				$code = preg_replace('/[^a-z_]/', '', strtolower(trim((string) $code)));

				if ($code !== '')
				{
					$c['categories'][] = $code;
				}
			}

			$c['categories'] = array_values(array_unique($c['categories']));
		}

		$c['radius']    = max(200, min(10000, $c['radius']));

		/* 0 은 "이 조건을 따지지 않는다" 는 뜻이다 (화면의 인원 0 · 예산 '얼마든').
		 * 클램프에 그대로 넣으면 1 과 3000 으로 올라가 무관이 사라지므로
		 * 0 일 때만 건너뛴다. 음수는 입력으로 들어올 수 있으니 0 으로 눕힌다. */
		$c['headcount'] = ($c['headcount'] <= 0) ? 0 : max(1, min(300, $c['headcount']));
		$c['budget']    = ($c['budget']    <= 0) ? 0 : max(3000, min(500000, $c['budget']));
		$c['page']      = max(1, min(1000, $c['page']));
		$c['per_page']  = max(5, min(50, $c['per_page']));

		// 목록 내 검색어는 상호명/주소를 훑는 값이라 길 이유가 없다.
		// keyword 와 달리 네이버 질의로 나가지 않으므로 짧게 잘라도 안전하다.
		if (mb_strlen($c['find'], 'UTF-8') > 40)
		{
			$c['find'] = trim(mb_substr($c['find'], 0, 40, 'UTF-8'));
		}

		// 검색어 길이도 잘라낸다. 가장 긴 지역명이 10자('동대문역사문화공원역')라
		// 60자를 넘는 검색어는 실제 이름일 수 없는데, 그대로 넘기면 이 문자열이
		// 네이버 질의어가 되어 URL 이 수천 자로 불어난다. 실측: 300자 검색어로
		// 요청하면 게이트웨이가 HTTP 414(URI Too Long)로 거절하고, 실패한 질의는
		// 캐시되지 않으므로(의도된 동작) 요청마다 최대 naver_max_queries 회씩
		// 호출을 계속 태운다. 로그에 'Naver_local HTTP 414' 가 남는다.
		if (mb_strlen($c['keyword'], 'UTF-8') > 60)
		{
			$c['keyword'] = trim(mb_substr($c['keyword'], 0, 60, 'UTF-8'));
		}

		// 좌표는 지구상의 값이어야 한다. radius/headcount/budget 은 클램프하는데
		// 좌표만 검증이 없어서 `?lat=999&lng=999` 가 origin.type=coords 로 확정되고
		// 화면에 '999.00000, 999.00000' 이 기준점으로 찍힌 채 0건이 나왔다.
		// 클램프하면 엉뚱한 지점을 "확정" 하는 셈이라 **좌표 없음으로 되돌린다** —
		// resolve_origin 이 지역/키워드로 폴백하고 화면도 "좌표 없음" 이라고 말한다.
		if ($c['lat'] < -90 OR $c['lat'] > 90 OR $c['lng'] < -180 OR $c['lng'] > 180)
		{
			$c['lat'] = 0.0;
			$c['lng'] = 0.0;
		}

		return $c;
	}

	/**
	 * 후보에 점수를 매겨 정렬된 결과를 돌려준다.
	 *
	 * @param  array $places   t_places 테이블 행 배열
	 * @param  array $criteria normalize_criteria() 결과
	 * 페이징은 여기서 하지 않는다. **전체**를 점수순으로 세워서 돌려주고
	 * 페이지 자르기는 Spot_service 가 한다 — 21위부터를 보려면 그 전에
	 * 전체 순위가 존재해야 하기 때문이다.
	 *
	 * @param  int   $limit 순위에 남길 전체 최대 건수. 0 이면 제한 없음
	 * @return array
	 */
	public function rank(array $places, array $criteria, $limit = 0)
	{
		$weights = $this->weights_for($criteria['purpose'], $criteria);
		$total_w = array_sum($weights);
		$scored  = array();

		foreach ($places as $p)
		{
			$parts = array(
				'distance' => $this->score_distance($p, $criteria),
				'budget'   => $this->score_budget($p, $criteria),
				'capacity' => $this->score_capacity($p, $criteria),
				'rating'   => $this->score_rating($p),
				'amenity'  => $this->score_amenity($p, $criteria),
				'category' => $this->score_category($p, $criteria),
			);

			// strict 모드: 필수 조건 미충족이면 제외
			if ($criteria['strict'] && ! $this->passes_strict($p, $criteria))
			{
				continue;
			}

			$sum = 0;

			foreach ($parts as $k => $v)
			{
				$sum += $v * $weights[$k];
			}

			$score = $total_w > 0 ? ($sum / $total_w) : 0;
			$score += $this->purpose_bonus($p, $criteria);
			$score = max(0, min(100, $score));

			$row = $p;
			$row['distance_m'] = $this->distance_of($p, $criteria);
			$row['score']      = round($score, 1);
			$row['score_parts'] = array_map(function ($v) { return round($v); }, $parts);
			$row['reasons']    = $this->reasons($p, $criteria, $parts);

			$scored[] = $row;
		}

		usort($scored, function ($a, $b) {
			if ($a['score'] === $b['score'])
			{
				return ($a['distance_m'] === NULL ? PHP_INT_MAX : $a['distance_m'])
					<=> ($b['distance_m'] === NULL ? PHP_INT_MAX : $b['distance_m']);
			}

			return $b['score'] <=> $a['score'];
		});

		$scored = $this->diversify($scored);

		return ($limit > 0) ? array_slice($scored, 0, $limit) : $scored;
	}

	// =========================================================
	//  개별 점수
	// =========================================================

	protected function distance_of($p, $criteria)
	{
		if (empty($criteria['lat']) OR empty($criteria['lng']))
		{
			return NULL;
		}
		if (empty($p['lat']) OR empty($p['lng']))
		{
			return NULL;
		}

		return (int) round(ds_haversine($criteria['lat'], $criteria['lng'], $p['lat'], $p['lng']));
	}

	/** 가까울수록 높음. 반경 안이면 55점 이상 보장. */
	protected function score_distance($p, $criteria)
	{
		$d = $this->distance_of($p, $criteria);

		if ($d === NULL)
		{
			return 55; // 좌표를 모르면 중립
		}

		$r = max(100, (int) $criteria['radius']);

		if ($d <= $r * 0.25)
		{
			return 100;
		}
		if ($d <= $r)
		{
			// r*0.25 -> 100점, r -> 60점
			$t = ($d - $r * 0.25) / ($r * 0.75);

			return 100 - ($t * 40);
		}

		// 반경 밖은 급격히 감점
		$over = ($d - $r) / $r;

		return max(0, 60 - $over * 70);
	}

	/**
	 * 1인 예산 적합도. 초과에 더 민감하게.
	 *
	 * 가격이 업종 기준 추정값(attrs_verified=0)이면 만점을 주지 않는다.
	 * 추정값은 업종별로 다 같은 숫자라 "예산에 딱 맞음" 이 사실상 무의미한데,
	 * 이걸 만점으로 처리하면 실측 가격을 가진 장소를 밀어낸다.
	 */
	protected function score_budget($p, $criteria)
	{
		$price  = (int) $p['avg_price'];

		/* 예산 무관(0). weights_for() 가 이 항목 가중치를 0 으로 만들어 점수에는
		 * 영향이 없지만, max(1, 0) 으로 계산하면 ratio 가 폭주해 score_parts 에
		 * 0(최악)이 찍힌다. API 를 보는 쪽에 거짓이 되므로 중립값을 준다. */
		if ((int) $criteria['budget'] === 0)
		{
			return 50;
		}

		$budget = max(1, (int) $criteria['budget']);

		if ($price <= 0)
		{
			return 58; // 가격 미상은 중립보다 살짝 아래
		}

		$ratio = ($price - $budget) / $budget;

		if (abs($ratio) <= 0.15)
		{
			$score = 100;
		}
		elseif ($ratio > 0)
		{
			// 예산 초과
			$score = max(0, 100 - ($ratio - 0.15) * 190);
		}
		else
		{
			// 예산보다 저렴 - 감점 폭을 작게, 하한 50
			$score = max(50, 100 - (abs($ratio) - 0.15) * 60);
		}

		return $this->cap_if_estimated($p, $score, 78);
	}

	/** 인원 수용 */
	protected function score_capacity($p, $criteria)
	{
		$max = (int) $p['max_party'];

		// 인원 무관(0). 위 score_budget 과 같은 이유로 중립값을 준다.
		if ((int) $criteria['headcount'] === 0)
		{
			return 50;
		}

		$head = max(1, (int) $criteria['headcount']);

		if ($max <= 0)
		{
			// 미상. 소규모면 어디든 되므로 인원이 적을수록 후하게.
			return ($head <= 4) ? 78 : (($head <= 8) ? 60 : 40);
		}

		if ($max >= $head * 1.5) $score = 100;
		elseif ($max >= $head)       $score = 88;
		elseif ($max >= $head * 0.7) $score = 52;
		else                         $score = 12;

		return $this->cap_if_estimated($p, $score, 80);
	}

	/**
	 * 속성이 실측값이 아니면(attrs_verified=0) 점수 상한을 씌운다.
	 *
	 * 감점이 아니라 상한이다. 추정값이 만점을 받아 실측값을 이기는 것만 막고,
	 * 낮은 점수는 그대로 둔다. 후보가 전부 추정값이면 모두 같은 상한을 받으므로
	 * 상대 순위에는 영향이 없다.
	 */
	protected function cap_if_estimated($p, $score, $cap)
	{
		if (array_key_exists('attrs_verified', $p) && empty($p['attrs_verified']))
		{
			return min($score, $cap);
		}

		return $score;
	}

	/**
	 * 인기도.
	 *
	 * 1순위: 평점 (직접 등록/보정한 장소만 갖고 있다)
	 *        리뷰 수가 적으면 평균으로 끌어당기는 베이지안 보정.
	 * 2순위: 네이버 지역검색 결과 순위.
	 *        네이버는 평점·리뷰수를 주지 않는다. 대신 리뷰순(sort=comment)으로
	 *        요청하므로 응답 순서가 인기도 대리지표가 된다.
	 * 둘 다 없으면 중립.
	 */
	protected function score_rating($p)
	{
		$rating = (float) $p['rating'];
		$count  = (int) $p['review_count'];

		if ($rating > 0)
		{
			$adj = (($rating * $count) + (self::RATING_PRIOR_MEAN * self::RATING_PRIOR_COUNT))
				/ ($count + self::RATING_PRIOR_COUNT);

			// 3.0 -> 0점, 5.0 -> 100점 으로 스트레치 (변별력 확보)
			return max(0, min(100, ($adj - 3.0) / 2.0 * 100));
		}

		$rank = isset($p['naver_rank']) ? (int) $p['naver_rank'] : 0;

		if ($rank > 0)
		{
			// 1위 78 / 2위 70 / 3위 62 / 4위 54 / 5위 46, 하한 42.
			//
			// 상한을 78 로 잡은 이유: 네이버 순위는 질의어에 따라 흔들리는 값이고
			// 여러 질의의 최소 순위를 쓰기 때문에 1위가 흔하게 나온다. 이걸 높게 주면
			// 평점 4.8(약 85점)인 검증된 장소를 순위만 좋은 곳이 이겨버린다.
			// 평점 실측값의 상한(100)보다 확실히 낮게 둬서 대체 지표의 위치를 지킨다.
			return max(42, 78 - ($rank - 1) * 8);
		}

		return 55;
	}

	/** 편의 옵션 충족도 */
	protected function score_amenity($p, $criteria)
	{
		$reqs = array();

		if ($criteria['need_room'])    $reqs[] = ! empty($p['has_room']);
		if ($criteria['need_parking']) $reqs[] = ! empty($p['has_parking']);
		if ($criteria['need_late'])    $reqs[] = ! empty($p['open_late']);
		if ($criteria['no_alcohol'])   $reqs[] = ! empty($p['no_alcohol']);

		if (empty($reqs))
		{
			// 요구 조건이 없으면 갖춘 만큼 가산
			$score = 58;
			if ( ! empty($p['has_room']))    $score += 16;
			if ( ! empty($p['has_parking'])) $score += 16;
			if ( ! empty($p['open_late']))   $score += 10;

			return min(100, $score);
		}

		$met = count(array_filter($reqs));

		return ($met / count($reqs)) * 100;
	}

	/** 선택한 음식 종류 일치 */
	protected function score_category($p, $criteria)
	{
		if ( ! empty($criteria['categories']))
		{
			return in_array($p['category_code'], $criteria['categories'], TRUE) ? 100 : 20;
		}

		// 음식 종류를 고르지는 않았지만 검색어가 업종을 지목한 경우("회", "고기집").
		//
		// 하드 필터로 걸지는 않는다 — 검색어는 지역명이나 상호일 수도 있어서
		// 잘라내면 결과가 통째로 비는 사고가 난다. 대신 지목된 업종에 만점을 줘서
		// **문자열이 우연히 걸린 행보다 위로 올린다.**
		// (실측: keyword=회 는 tags 의 '회식'·상호의 '회관'에 걸린 고깃집이
		//  후보 131건 중 40건이었고, 이 신호가 없으면 상위 20건에 횟집이 0건이었다)
		if ( ! empty($criteria['keyword_codes']))
		{
			return in_array($p['category_code'], $criteria['keyword_codes'], TRUE) ? 100 : 30;
		}

		return 70;
	}

	/** 목적별 업종 보정 (+4 / -6) */
	protected function purpose_bonus($p, $criteria)
	{
		$def  = $this->purposes[$criteria['purpose']];
		$code = $p['category_code'];

		// 사용자가 음식 종류를 직접 골랐다면 그 선택이 목적 취향보다 우선한다
		if ( ! empty($criteria['categories']))
		{
			return in_array($code, $criteria['categories'], TRUE) ? 2 : 0;
		}

		if (in_array($code, $def['prefer'], TRUE))
		{
			return 4;
		}

		if ( ! empty($def['avoid']) && in_array($code, $def['avoid'], TRUE))
		{
			return -6;
		}

		return 0;
	}

	/** strict 모드 통과 여부 */
	protected function passes_strict($p, $criteria)
	{
		if ($criteria['need_room'] && empty($p['has_room']))       return FALSE;
		if ($criteria['need_parking'] && empty($p['has_parking'])) return FALSE;
		if ($criteria['need_late'] && empty($p['open_late']))      return FALSE;

		if ( ! empty($criteria['categories'])
			&& ! in_array($p['category_code'], $criteria['categories'], TRUE))
		{
			return FALSE;
		}

		$max  = (int) $p['max_party'];
		$head = (int) $criteria['headcount'];

		// 인원 무관(0)이면 수용인원으로 걸러내지 않는다
		if ($head > 0 && $max > 0 && $max < $head)
		{
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * 같은 업종이 상위에 몰리는 것을 완화.
	 * 동일 카테고리 3번째부터 점수를 조금씩 깎아 순서를 섞는다.
	 *
	 * 감점에는 **상한이 있고**, 섞을 업종이 하나뿐이면 아예 건너뛴다.
	 * 쏠림 완화는 상위권에서만 의미가 있는데 감점이 (n-1)*2.5 로 무한정
	 * 커지면 꼬리의 표시 점수와 등급이 거짓이 된다:
	 *   실측 — 강남역·8명·3만원·팀회식에서 음식 종류로 '고기/구이' 하나만 고르면
	 *   하드 필터 때문에 결과 20건이 전부 bbq 인데도 20위가 79.8 -> 34.7 로 깎여
	 *   등급이 '추천' 에서 '차선책' 으로 내려갔다(같은 조건에서 카테고리를
	 *   고르지 않으면 20위가 78.8 '추천'). 순서는 보존되므로 점수만 거짓이 된다.
	 * README 의 설명도 "3번째부터 소폭 감점" 이다.
	 */
	protected function diversify(array $scored)
	{
		// 섞을 업종이 하나뿐이면 감점해도 순서가 바뀌지 않는다 — 점수만 깎인다.
		$codes = array();

		foreach ($scored as $row)
		{
			$codes[$row['category_code']] = TRUE;
		}

		if (count($codes) <= 1)
		{
			return $scored;
		}

		$seen = array();

		foreach ($scored as $i => $row)
		{
			$code = $row['category_code'];
			$n    = isset($seen[$code]) ? $seen[$code] : 0;

			if ($n >= 2)
			{
				$penalty = min(self::DIVERSIFY_MAX_PENALTY, ($n - 1) * 2.5);

				$scored[$i]['score'] = round(max(0, $row['score'] - $penalty), 1);
			}

			$seen[$code] = $n + 1;
		}

		usort($scored, function ($a, $b) {
			return $b['score'] <=> $a['score'];
		});

		return $scored;
	}

	/**
	 * 사람이 읽는 추천 사유 (상위 3개)
	 *
	 * 목록 화면의 메타 줄(업종·거리·가격·수용인원)에 이미 보이는 값은
	 * 그대로 되풀이하지 않고, "그래서 왜 점수가 높은가"만 남긴다.
	 */
	protected function reasons($p, $criteria, $parts)
	{
		$out = array();
		$d   = $this->distance_of($p, $criteria);

		if ($d !== NULL && $d <= 300)
		{
			$out[] = '걸어서 바로';
		}

		// !! || 를 써야 한다. OR 는 = 보다 우선순위가 낮아
		//    `$x = A OR B;` 가 `($x = A) OR B;` 로 파싱되어 B 가 버려진다.
		//    이 줄이 OR 였을 때 attrs_verified=1 인 실측 장소가 추정값으로 취급되어
		//    "예산에 딱 맞음" 대신 "업종 평균 기준" 이 붙었다.
		$verified = ! array_key_exists('attrs_verified', $p) || ! empty($p['attrs_verified']);
		$price    = (int) $p['avg_price'];

		// 추정 가격으로 "예산에 딱 맞음" 이라고 단언하면 거짓말이 된다.
		// 실측값일 때만 단언하고, 추정값이면 업종 평균이라는 걸 밝힌다.
		// 예산 무관이면 "예산에 딱 맞음" 같은 말을 붙일 근거가 없다.
		$has_budget = ((int) $criteria['budget'] > 0);

		if ($has_budget && $price > 0 && $verified && $parts['budget'] >= 90)
		{
			$out[] = '예산에 딱 맞음';
		}
		elseif ($has_budget && $price > 0 && $verified && $price < $criteria['budget'] * 0.75)
		{
			$out[] = '예산보다 저렴';
		}
		elseif ($has_budget && $price > 0 && ! $verified && $price <= $criteria['budget'])
		{
			$out[] = '업종 평균 기준 예산 내';
		}

		$rank = isset($p['naver_rank']) ? (int) $p['naver_rank'] : 0;

		if ((float) $p['rating'] <= 0 && $rank > 0 && $rank <= 2)
		{
			$out[] = '네이버 검색 상위';
		}

		$max  = (int) $p['max_party'];
		$head = (int) $criteria['headcount'];

		// 인원 무관이면 head 가 0 이라 "0명 가능" 이 된다. 대신 수용 규모만 알린다.
		if ($head <= 0)
		{
			if ($max > 0)
			{
				$out[] = '최대 ' . $max . '명';
			}
		}
		elseif ($max > 0 && $max >= $head * 1.5)
		{
			$out[] = $head . '명 넉넉히 수용';
		}
		elseif ($max > 0 && $max >= $head)
		{
			$out[] = $head . '명 가능';
		}

		if ($parts['rating'] >= 70 && (float) $p['rating'] > 0)
		{
			$out[] = '평점 ' . number_format((float) $p['rating'], 1)
				. ' · 리뷰 ' . number_format((int) $p['review_count']);
		}

		$met = array();

		if ($criteria['need_room'] && ! empty($p['has_room']))       $met[] = '룸';
		if ($criteria['need_parking'] && ! empty($p['has_parking'])) $met[] = '주차';
		if ($criteria['need_late'] && ! empty($p['open_late']))      $met[] = '심야';

		if ($met)
		{
			$out[] = implode(' · ', $met) . ' 조건 충족';
		}

		return array_slice($out, 0, 3);
	}

	/**
	 * 최종 가중치 계산
	 *
	 * 음식 종류를 고르지 않으면 모든 후보의 category 점수가 70 으로 같아서
	 * 이 항목의 가중치는 순위에 영향을 주지 않는다. 반대로 직접 골랐다면
	 * 그건 사용자가 명시한 요구이므로 비중을 크게 올려 다른 항목에 묻히지 않게 한다.
	 */
	protected function weights_for($purpose, array $criteria = array())
	{
		$w = $this->base_weights;

		if (isset($this->purposes[$purpose]['weight']))
		{
			foreach ($this->purposes[$purpose]['weight'] as $k => $mul)
			{
				if (isset($w[$k]))
				{
					$w[$k] = $w[$k] * $mul;
				}
			}
		}

		/* 무관으로 고른 조건은 **가중치를 0 으로 만들어 분모에서도 뺀다.**
		 * 중립 점수(예: 50)를 주는 방식은 분모에 그대로 남아 나머지 항목의
		 * 차이를 눌러 버린다 — 0 으로 두면 rank() 의 $sum / $total_w 가
		 * 남은 항목만으로 다시 정규화된다. */
		if (isset($criteria['budget']) && (int) $criteria['budget'] === 0)
		{
			$w['budget'] = 0;
		}

		if (isset($criteria['headcount']) && (int) $criteria['headcount'] === 0)
		{
			$w['capacity'] = 0;
		}

		if ( ! empty($criteria['categories']))
		{
			$w['category'] = max($w['category'] * 5, 34);
		}
		elseif ( ! empty($criteria['keyword_codes']))
		{
			// 검색어에서 **추론한** 의도라 명시 선택보다 약하게 잡는다.
			// 이 비중이 기본값(7)이면 우연히 문자열이 걸린 다른 업종을
			// 뒤집지 못한다(실측: 7 이면 keyword=회 의 상위 20건에 횟집 0건).
			$w['category'] = max($w['category'] * 4, 28);
		}

		return $w;
	}
}
