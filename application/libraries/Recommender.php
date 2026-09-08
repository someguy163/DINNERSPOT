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

	/**
	 * 회식 목적 프리셋
	 *  weight : 가중치 배수
	 *  prefer : 선호 카테고리(가산점)
	 *  avoid  : 목적에 어울리지 않는 카테고리(감점)
	 *  needs  : 사실상 필수로 보는 편의옵션
	 */
	protected $purposes = array(
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
		$cfg = function ($k, $d) { return $this->CI->config->item($k, 'dinnerspot') ?: $d; };

		$c = array(
			'area_id'    => isset($input['area_id']) ? (int) $input['area_id'] : 0,
			'keyword'    => isset($input['keyword']) ? trim((string) $input['keyword']) : '',
			'lat'        => isset($input['lat']) ? (float) $input['lat'] : 0,
			'lng'        => isset($input['lng']) ? (float) $input['lng'] : 0,
			'radius'     => isset($input['radius']) ? (int) $input['radius'] : (int) $cfg('default_radius', 800),
			'headcount'  => isset($input['headcount']) ? (int) $input['headcount'] : (int) $cfg('default_headcount', 6),
			'budget'     => isset($input['budget']) ? (int) $input['budget'] : (int) $cfg('default_budget', 25000),
			'purpose'    => isset($input['purpose']) ? (string) $input['purpose'] : 'team',
			'categories' => array(),
			'need_room'    => ! empty($input['need_room']),
			'need_parking' => ! empty($input['need_parking']),
			'need_late'    => ! empty($input['need_late']),
			'no_alcohol'   => ! empty($input['no_alcohol']),
			'strict'       => ! empty($input['strict']),
			// 후보 출처 강제 지정. 비어 있으면 Spot_service 가 config 로 결정한다.
			// naver / db / both 비교용으로 쿼리스트링에 붙일 수 있다.
			'source_mode'  => isset($input['source_mode']) ? (string) $input['source_mode'] : '',
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
				$code = preg_replace('/[^a-z_]/', '', strtolower(trim($code)));

				if ($code !== '')
				{
					$c['categories'][] = $code;
				}
			}

			$c['categories'] = array_values(array_unique($c['categories']));
		}

		$c['radius']    = max(200, min(10000, $c['radius']));
		$c['headcount'] = max(1, min(300, $c['headcount']));
		$c['budget']    = max(3000, min(500000, $c['budget']));

		return $c;
	}

	/**
	 * 후보에 점수를 매겨 정렬된 결과를 돌려준다.
	 *
	 * @param  array $places   t_places 테이블 행 배열
	 * @param  array $criteria normalize_criteria() 결과
	 * @param  int   $limit
	 * @return array
	 */
	public function rank(array $places, array $criteria, $limit = 20)
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

		return array_slice($scored, 0, $limit);
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
		$max  = (int) $p['max_party'];
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
		if (empty($criteria['categories']))
		{
			return 70;
		}

		return in_array($p['category_code'], $criteria['categories'], TRUE) ? 100 : 20;
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

		$max = (int) $p['max_party'];

		if ($max > 0 && $max < (int) $criteria['headcount'])
		{
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * 같은 업종이 상위에 몰리는 것을 완화.
	 * 동일 카테고리 3번째부터 점수를 조금씩 깎아 순서를 섞는다.
	 */
	protected function diversify(array $scored)
	{
		$seen = array();

		foreach ($scored as $i => $row)
		{
			$code = $row['category_code'];
			$n    = isset($seen[$code]) ? $seen[$code] : 0;

			if ($n >= 2)
			{
				$scored[$i]['score'] = round(max(0, $row['score'] - ($n - 1) * 2.5), 1);
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

		$verified = ! array_key_exists('attrs_verified', $p) OR ! empty($p['attrs_verified']);
		$price    = (int) $p['avg_price'];

		// 추정 가격으로 "예산에 딱 맞음" 이라고 단언하면 거짓말이 된다.
		// 실측값일 때만 단언하고, 추정값이면 업종 평균이라는 걸 밝힌다.
		if ($price > 0 && $verified && $parts['budget'] >= 90)
		{
			$out[] = '예산에 딱 맞음';
		}
		elseif ($price > 0 && $verified && $price < $criteria['budget'] * 0.75)
		{
			$out[] = '예산보다 저렴';
		}
		elseif ($price > 0 && ! $verified && $price <= $criteria['budget'])
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

		if ($max > 0 && $max >= $head * 1.5)
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

		if ( ! empty($criteria['categories']))
		{
			$w['category'] = max($w['category'] * 5, 34);
		}

		return $w;
	}
}
