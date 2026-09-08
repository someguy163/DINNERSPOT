<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 장소 저장소
 *
 * - DB 후보 조회 (반경 바운딩박스 + 조건)
 * - 네이버 결과 upsert (캐시 적재)
 * - 네이버가 주지 않는 회식 속성(예산/수용인원/룸 등) 추정
 */
class Place_model extends CI_Model {

	/** 카테고리 사전 캐시 */
	protected $cat_cache = NULL;

	/** 위도 1도 ≈ 111,320m */
	const M_PER_DEG_LAT = 111320;

	public function __construct()
	{
		parent::__construct();
	}

	// =========================================================
	//  조회
	// =========================================================

	public function get($id)
	{
		$row = $this->db->where('id', (int) $id)->get('t_places')->row_array();

		return $row ?: NULL;
	}

	public function get_many(array $ids)
	{
		$ids = array_filter(array_map('intval', $ids));

		if (empty($ids))
		{
			return array();
		}

		return $this->db->where_in('id', $ids)->get('t_places')->result_array();
	}

	/**
	 * 추천 후보 조회.
	 *
	 * 좌표가 있으면 바운딩박스로 1차 필터링하고,
	 * 결과가 너무 적으면 반경을 단계적으로 넓혀 재조회한다.
	 *
	 * @param  array $c normalize_criteria() 결과
	 * @return array
	 */
	public function find_candidates(array $c)
	{
		// 카테고리 사전을 먼저 캐시에 올려둔다. 아래 쿼리를 짜는 도중에
		// categories() 가 호출되면 공유 쿼리빌더가 오염되기 때문에,
		// 여기서 미리 DB 를 한 번 때려 이후 호출이 전부 캐시 히트가 되게 한다.
		$this->categories();

		$has_geo = ( ! empty($c['lat']) && ! empty($c['lng']));

		if ($has_geo)
		{
			// 반경보다 넉넉히 잡아야 "조금 벗어났지만 좋은 곳"도 후보에 든다
			foreach (array(1.8, 3.0, 6.0) as $mul)
			{
				$rows = $this->query_box($c, (int) ($c['radius'] * $mul));

				if (count($rows) >= 12)
				{
					return $rows;
				}

				$last = $rows;
			}

			return $last;
		}

		return $this->query_keyword($c);
	}

	/** 바운딩박스 조회 */
	protected function query_box(array $c, $radius_m)
	{
		$lat = (float) $c['lat'];
		$lng = (float) $c['lng'];

		$d_lat = $radius_m / self::M_PER_DEG_LAT;
		$cos   = max(0.1, cos(deg2rad($lat)));
		$d_lng = $radius_m / (self::M_PER_DEG_LAT * $cos);

		$this->db->from('t_places')
			->where('is_active', 1)
			->where('lat >=', $lat - $d_lat)
			->where('lat <=', $lat + $d_lat)
			->where('lng >=', $lng - $d_lng)
			->where('lng <=', $lng + $d_lng);

		$this->apply_soft_filters($c);

		return $this->db->limit(300)->get()->result_array();
	}

	/** 좌표 없이 키워드로 조회 */
	protected function query_keyword(array $c)
	{
		$kw = trim((string) $c['keyword']);

		// !! 쿼리빌더를 건드리기 전에 먼저 계산한다.
		// CI3 의 $this->db 는 요청당 하나뿐인 공유 인스턴스라서, from()/where() 로
		// 쿼리를 짜는 중간에 다른 테이블(t_categories)을 조회하면 그 상태가
		// 지금 만들고 있는 쿼리에 섞여 들어간다.
		// (실제로 "FROM t_places, t_categories" + 그룹 미닫힘 + 떠돌이 ORDER BY 로
		//  ERROR 1064 가 났다. codes_matching_keyword() 는 내부에서 categories() 를 부른다.)
		$codes = ($kw !== '') ? $this->codes_matching_keyword($kw) : array();

		$this->db->from('t_places')->where('is_active', 1);

		if ($kw !== '')
		{
			// category_raw(네이버 원본 분류)도 같이 본다. 이걸 빼면 "고기", "회",
			// "일식" 처럼 가장 흔한 검색어가 0건이 된다 — 상호명에 업종이
			// 안 들어간 집이 대부분이기 때문이다.
			//
			// 주의: OR 조건은 반드시 group_start/group_end 안에 있어야 한다.
			// 그룹 밖에서 or_where 를 걸면 is_active 나 source 필터까지
			// OR 로 새어나가 비활성 행이 결과에 섞인다.
			$this->db->group_start()
				->like('name', $kw)
				->or_like('category_raw', $kw)
				->or_like('address', $kw)
				->or_like('road_address', $kw)
				->or_like('tags', $kw);

			// 검색어가 카테고리 사전의 키워드에 걸리면 그 업종 전체를 후보에 넣는다.
			// "고기" -> bbq, "회" -> seafood 처럼 사람이 쓰는 말로 업종을 찾게 한다.
			if ( ! empty($codes))
			{
				$this->db->or_where_in('category_code', $codes);
			}

			$this->db->group_end();
		}

		$this->apply_soft_filters($c);

		return $this->db->order_by('rating', 'DESC')->limit(200)->get()->result_array();
	}

	/**
	 * strict 모드일 때만 SQL 단계에서 잘라낸다.
	 * 아니면 점수 엔진이 감점으로 처리하도록 전부 넘긴다.
	 *
	 * 소스 필터는 strict 와 무관하게 항상 적용한다.
	 */
	protected function apply_soft_filters(array $c)
	{
		$this->apply_source_filter($c);

		if (empty($c['strict']))
		{
			return;
		}

		if ( ! empty($c['categories']))
		{
			$this->db->where_in('category_code', $c['categories']);
		}
		if ( ! empty($c['need_room']))    $this->db->where('has_room', 1);
		if ( ! empty($c['need_parking'])) $this->db->where('has_parking', 1);
		if ( ! empty($c['need_late']))    $this->db->where('open_late', 1);
	}

	/**
	 * 추천 후보의 출처를 제한한다.
	 *
	 *   naver : 네이버로 수집한 장소만 (config source_mode 기본값)
	 *   db    : 직접 등록/보정한 장소만
	 *   both  : 제한 없음
	 *
	 * $c['source_mode'] 는 Spot_service 가 결정해서 넣어준다.
	 * (키가 없으면 naver -> db 로 폴백된 값이 들어온다)
	 */
	protected function apply_source_filter(array $c)
	{
		$mode = isset($c['source_mode']) ? $c['source_mode'] : 'both';

		if ($mode === 'naver')
		{
			$this->db->where('source', 'naver');
		}
		elseif ($mode === 'db')
		{
			$this->db->where('source', 'manual');
		}

		// 카페·디저트는 회식 장소가 아니다. 목적별 감점(-6)만으로는
		// 역 바로 앞 베이커리가 거리 점수로 1위를 먹는 일이 생긴다
		// (실측: 잠실역에서 백화점 베이커리가 1위). 명시적으로 고른 경우에만 포함한다.
		$picked = isset($c['categories']) ? (array) $c['categories'] : array();

		if ( ! in_array('cafe', $picked, TRUE))
		{
			$this->db->where('category_code !=', 'cafe');
		}
	}

	/** 소스별 보유 건수 (화면 안내용) */
	public function source_counts()
	{
		$rows = $this->db->select('source, COUNT(*) AS cnt', FALSE)
			->where('is_active', 1)
			->group_by('source')
			->get('t_places')
			->result_array();

		$out = array('naver' => 0, 'manual' => 0);

		foreach ($rows as $r)
		{
			$out[$r['source']] = (int) $r['cnt'];
		}

		return $out;
	}

	/** 추천 노출 카운트 */
	public function bump_hits(array $ids)
	{
		$ids = array_filter(array_map('intval', $ids));

		if (empty($ids))
		{
			return;
		}

		$this->db->set('hit_count', 'hit_count + 1', FALSE)
			->where_in('id', $ids)
			->update('t_places');
	}

	public function bump_picks(array $ids)
	{
		$ids = array_filter(array_map('intval', $ids));

		if (empty($ids))
		{
			return;
		}

		$this->db->set('pick_count', 'pick_count + 1', FALSE)
			->where_in('id', $ids)
			->update('t_places');
	}

	// =========================================================
	//  네이버 결과 적재
	// =========================================================

	/**
	 * 네이버 정규화 결과를 upsert 한다.
	 *
	 * 이미 있는 레코드는 좌표/전화/주소만 갱신하고,
	 * 사람이 보정한 값(avg_price, max_party 등)은 건드리지 않는다.
	 *
	 * @return int 신규 등록 건수
	 */
	public function upsert_naver(array $rows)
	{
		if (empty($rows))
		{
			return 0;
		}

		$new = 0;
		$now = date('Y-m-d H:i:s');

		foreach ($rows as $r)
		{
			if (empty($r['source_key']) OR empty($r['name']))
			{
				continue;
			}

			$exists = $this->db
				->select('id, naver_rank, attrs_verified')
				->where('source', 'naver')
				->where('source_key', $r['source_key'])
				->get('t_places')
				->row_array();

			$rank = isset($r['naver_rank']) ? (int) $r['naver_rank'] : 0;

			if ($exists)
			{
				$upd = array(
					'name'         => $r['name'],
					'address'      => $r['address'],
					'road_address' => $r['road_address'],
					'phone'        => $r['phone'],
					'homepage'     => $r['homepage'],
					'category_raw' => $r['category_raw'],
					'lat'          => $r['lat'] ?: 0,
					'lng'          => $r['lng'] ?: 0,
					'synced_at'    => $now,
				);

				// 순위는 더 좋은(작은) 값만 반영. 0 은 미상이므로 덮어쓰지 않는다.
				$prev = (int) $exists['naver_rank'];

				if ($rank > 0 && ($prev === 0 OR $rank < $prev))
				{
					$upd['naver_rank'] = $rank;
				}

				// 사람이 보정한 속성(attrs_verified=1)은 손대지 않는다.
				// 아직 추정값인 행은 업종이 바뀌었을 수 있으니 재추정한다.
				if (empty($exists['attrs_verified']))
				{
					$upd = array_merge($upd, $this->guess_attributes($r));
				}

				$this->db->where('id', $exists['id'])->update('t_places', $upd);

				continue;
			}

			$guess = $this->guess_attributes($r);

			// 음식점이 아니면 is_active=0 으로 넣어둔다.
			// 아예 버리면 다음 수집 때 또 판정해야 하므로, 판정 결과를 남겨
			// find_candidates(is_active=1) 에서 자동으로 빠지게 한다.
			$is_food = $this->is_food_place($r['category_raw'], $r['name']);

			if ( ! $is_food)
			{
				$guess['memo']      = '음식점 아님으로 판정 (' . $r['category_raw'] . ')';
				$guess['is_active'] = 0;
			}

			$this->db->insert('t_places', array_merge(array(
				'source'         => 'naver',
				'source_key'     => $r['source_key'],
				'name'           => $r['name'],
				'category_raw'   => $r['category_raw'],
				'address'        => $r['address'],
				'road_address'   => $r['road_address'],
				'phone'          => $r['phone'],
				'homepage'       => $r['homepage'],
				'lat'            => $r['lat'] ?: 0,
				'lng'            => $r['lng'] ?: 0,
				'naver_rank'     => $rank,
				'attrs_verified' => 0,
				'synced_at'      => $now,
			), $guess));

			$new++;
		}

		return $new;
	}

	/**
	 * 네이버 분류 문자열이 "밥 먹을 수 있는 곳" 인지 판정한다.
	 *
	 * 지역검색에 "OO 맛집" 을 던지면 음식점이 아닌 것도 섞여 온다.
	 * 실제로 수집된 예: "스포츠,오락>보드카페", "방탈출카페".
	 * 회식 장소 후보에 방탈출카페가 오르면 안 되므로 여기서 걸러낸다.
	 *
	 * @param  string $raw  네이버 category 문자열
	 * @param  string $name 상호명
	 * @return bool
	 */
	public function is_food_place($raw, $name = '')
	{
		// 상호명은 보지 않는다. 정상 음식점 상호에 비음식 단어가 흔히 들어간다:
		//   "맥도날드 마리오아울렛점"(양식>햄버거)
		//   "이성당 롯데백화점잠실점"(카페,디저트>베이커리)
		// 상호명까지 훑으면 이런 곳이 음식점 아님으로 잘못 걸린다.
		// $name 은 시그니처 호환을 위해 남겨두고 판정에는 쓰지 않는다.
		$hay = mb_strtolower(trim((string) $raw), 'UTF-8');

		if ($hay === '')
		{
			return TRUE;   // 분류를 모르면 통과 (수집 자체를 막지는 않는다)
		}

		// 허용 목록(화이트리스트)이 아니라 **차단 목록**으로 판정한다.
		//
		// 네이버는 최상위를 생략하고 요리명부터 주는 경우가 많다:
		//   "육류,고기요리>소고기구이", "이탈리아음식>스파게티,파스타전문"
		// 허용 목록 방식으로 하면 이런 정상 음식점이 조용히 버려진다
		// (실측: 143곳 중 소고기구이 1곳 + 파스타 전문점 3곳이 오탐 제외됨).
		// "맛집" 질의에 섞여 오는 비음식점은 종류가 한정적이라 열거하는 편이 안전하다.
		$blocked = array(
			// 오락·여가
			'스포츠,오락', '방탈출', '보드카페', '보드게임', 'pc방', '피시방',
			'노래방', '노래연습장', '당구', '스크린골프', '볼링', '만화방', '오락실',
			// 쇼핑·유통
			'쇼핑,유통', '종합도소매', '아울렛', '백화점', '대형마트', '편의점',
			'슈퍼마켓', '면세점',
			// 생활·서비스
			// 주의: '스파' 는 '스파게티' 에 부분일치한다. '시장' 은 '수산시장' 에 걸린다.
			// 부분일치로 음식점을 삼킬 수 있는 짧은 토큰은 넣지 않고
			// 상위 분류('생활,편의')로 잡는다.
			'생활,편의', '미용', '헤어', '네일', '마사지', '사우나', '찜질방',
			'세탁', '부동산', '금융', '은행', '보험', '주차장', '주유소',
			// 숙박·의료·교육
			'숙박', '호텔', '모텔', '펜션', '게스트하우스',
			'의료', '병원', '의원', '치과', '한의원', '약국',
			'교육,학문', '학원', '어학원', '교습',
			// 기타
			'문화,예술', '관광,명소', '여행,교통', '종교', '회사,단체', '공공,기관',
			'자동차', '카센터', '반려동물', '꽃집', '화원',
		);

		foreach ($blocked as $b)
		{
			if (mb_strpos($hay, $b) !== FALSE)
			{
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * 네이버 분류 문자열로부터 회식 관련 속성을 추정한다.
	 *
	 * 네이버 지역검색은 가격/좌석/주차 정보를 주지 않는다.
	 * 그래서 업종별 통계적 기본값을 넣어두고, 운영하면서
	 * 관리자가 실제 값으로 덮어쓰는 것을 전제로 한다.
	 * (rating 은 추정하지 않고 0 으로 둬서 점수 엔진이 중립 처리한다)
	 */
	public function guess_attributes(array $r)
	{
		$code = $this->map_category($r['category_raw'], $r['name']);

		// code => [1인 평균가, 가격대, 최대인원, 룸, 심야]
		$table = array(
			'bbq'      => array(28000, 3, 60, 1, 1),
			'korean'   => array(13000, 2, 40, 0, 0),
			'stew'     => array(11000, 1, 40, 0, 1),
			'seafood'  => array(45000, 4, 45, 1, 0),
			'chicken'  => array(20000, 2, 45, 0, 1),
			'chinese'  => array(20000, 2, 50, 1, 0),
			'japanese' => array(30000, 3, 30, 1, 0),
			'western'  => array(27000, 3, 35, 0, 0),
			'asian'    => array(14000, 2, 30, 0, 0),
			'noodle'   => array(12000, 1, 35, 0, 0),
			'buffet'   => array(22000, 2, 90, 1, 0),
			'izakaya'  => array(25000, 3, 40, 1, 1),
			'hof'      => array(20000, 2, 60, 0, 1),
			'bunsik'   => array(9000,  1, 25, 0, 0),
			'cafe'     => array(8000,  1, 30, 0, 0),
			'etc'      => array(18000, 2, 0,  0, 0),
		);

		$d = isset($table[$code]) ? $table[$code] : $table['etc'];

		return array(
			'category_code' => $code,
			'avg_price'     => $d[0],
			'price_level'   => $d[1],
			'max_party'     => $d[2],
			'has_room'      => $d[3],
			'has_parking'   => 0,
			'open_late'     => $d[4],
			'rating'        => 0,
			'review_count'  => 0,
			'memo'          => '네이버 자동수집 (속성은 업종 기준 추정값)',
		);
	}

	// =========================================================
	//  카테고리
	// =========================================================

	public function categories()
	{
		if ($this->cat_cache === NULL)
		{
			$this->cat_cache = $this->db
				->order_by('sort_order', 'ASC')
				->get('t_categories')
				->result_array();
		}

		return $this->cat_cache;
	}

	/**
	 * 네이버 분류 문자열/상호명 -> 내부 카테고리 코드
	 * 예: "음식점>한식>육류,고기" -> bbq
	 */
	public function map_category($raw, $name = '')
	{
		$raw = trim((string) $raw);

		if ($raw === '' && trim((string) $name) === '')
		{
			return 'etc';
		}

		// 네이버 분류는 "음식점>한식>육류,고기요리>곱창,막창,양" 처럼 계층 문자열이다.
		// 오른쪽이 구체적이므로 **뒤에서부터** 매칭한다.
		//
		// 통째로 훑으면 넓은 토큰이 구체적인 잎을 이긴다:
		//   "한식>생선회"      -> '한식' 이 먼저 걸려 한식 (해산물이어야 함)
		//   "한식>찜닭"        -> 한식 (치킨이어야 함)
		//   "한식>국수"        -> 한식 (면/국수여야 함)
		//   "음식점>일식>덮밥" -> 다행히 일식이지만 순서 운에 의존
		$segments = array_reverse(array_filter(array_map('trim', explode('>', $raw))));

		// 마지막 폴백으로 전체 문자열 + 상호명도 본다
		$segments[] = $raw . ' ' . $name;

		foreach ($segments as $seg)
		{
			$hit = $this->match_segment($seg);

			if ($hit !== NULL)
			{
				return $hit;
			}
		}

		return 'etc';
	}

	/** 분류 한 조각을 사전과 대조한다. 못 찾으면 NULL. */
	protected function match_segment($seg)
	{
		$seg = mb_strtolower(trim((string) $seg), 'UTF-8');

		if ($seg === '')
		{
			return NULL;
		}

		foreach ($this->categories() as $cat)
		{
			if ($cat['code'] === 'etc' OR $cat['keywords'] === '')
			{
				continue;
			}

			foreach (explode(',', $cat['keywords']) as $kw)
			{
				$kw = trim(mb_strtolower($kw, 'UTF-8'));

				if ($kw !== '' && mb_strpos($seg, $kw) !== FALSE)
				{
					return $cat['code'];
				}
			}
		}

		return NULL;
	}

	/**
	 * 검색어가 걸리는 카테고리 코드 목록.
	 *
	 * 사전의 label 또는 keywords 에 검색어가 포함되면 그 코드를 돌려준다.
	 * "고기" -> bbq, "회" -> seafood, "일식" -> japanese.
	 *
	 * @param  string $kw
	 * @return array  코드 배열
	 */
	public function codes_matching_keyword($kw)
	{
		$kw = trim(mb_strtolower((string) $kw, 'UTF-8'));

		if ($kw === '' OR mb_strlen($kw, 'UTF-8') < 1)
		{
			return array();
		}

		$out = array();

		foreach ($this->categories() as $cat)
		{
			if ($cat['code'] === 'etc')
			{
				continue;
			}

			// 표시명 자체가 걸리는 경우 ("일식", "고기/구이")
			if (mb_strpos(mb_strtolower($cat['label'], 'UTF-8'), $kw) !== FALSE)
			{
				$out[] = $cat['code'];
				continue;
			}

			foreach (explode(',', $cat['keywords']) as $k)
			{
				$k = trim(mb_strtolower($k, 'UTF-8'));

				// 검색어가 키워드를 포함하거나 키워드가 검색어를 포함하면 매칭.
				// "고기집" 으로 검색해도 키워드 "고기" 에 걸리게 한다.
				if ($k !== '' && (mb_strpos($k, $kw) !== FALSE OR mb_strpos($kw, $k) !== FALSE))
				{
					$out[] = $cat['code'];
					break;
				}
			}
		}

		return array_values(array_unique($out));
	}

	/** code => 카테고리 행 */
	public function category_map()
	{
		$out = array();

		foreach ($this->categories() as $c)
		{
			$out[$c['code']] = $c;
		}

		return $out;
	}

	// =========================================================
	//  지역
	// =========================================================

	public function areas()
	{
		return $this->db
			->where('is_active', 1)
			->order_by('sort_order', 'ASC')
			->order_by('name', 'ASC')
			->get('t_areas')
			->result_array();
	}

	/**
	 * 시/도 -> 역 목록으로 묶어서 돌려준다. 2단 선택 UI 용.
	 *
	 * @return array [ ['sido'=>'서울', 'count'=>52, 'areas'=>[...]], ... ]
	 */
	public function areas_grouped()
	{
		$groups = array();

		foreach ($this->areas() as $a)
		{
			$sido = $a['sido'] !== '' ? $a['sido'] : '기타';

			if ( ! isset($groups[$sido]))
			{
				$groups[$sido] = array('sido' => $sido, 'count' => 0, 'areas' => array());
			}

			$groups[$sido]['areas'][] = $a;
			$groups[$sido]['count']++;
		}

		// 시/도 표시 순서는 sort_order 가 이미 권역순으로 매겨져 있어
		// 첫 등장 순서를 그대로 쓴다.
		return array_values($groups);
	}

	/** 시/도 목록만 */
	public function sido_list()
	{
		return array_column($this->areas_grouped(), 'sido');
	}

	/**
	 * 좌표가 없는 지역을 네이버 지역검색으로 보정한다.
	 *
	 * 역/상권 좌표를 추측으로 채우지 않고 0 으로 둔 행을 실제 좌표로 메운다.
	 * 네이버 키가 없으면 아무것도 하지 않는다.
	 *
	 * @param  int $limit 한 번에 처리할 건수 (API 호출량 제어)
	 * @return array ['done'=>int, 'failed'=>array, 'skipped'=>string]
	 */
	public function geocode_pending_areas($limit = 20)
	{
		$this->load->library('naver_local');

		if ( ! $this->naver_local->is_enabled())
		{
			return array('done' => 0, 'failed' => array(), 'skipped' => '네이버 키 미설정');
		}

		$rows = $this->db
			->where('is_active', 1)
			->where('geo_verified', 0)
			->limit((int) $limit)
			->get('t_areas')
			->result_array();

		$done   = 0;
		$failed = array();

		foreach ($rows as $a)
		{
			// "강남역" 은 그대로, 상권명은 시군구를 붙여야 정확히 잡힌다
			$query = ($a['kind'] === 'station')
				? $a['name']
				: trim($a['sido'] . ' ' . $a['sigungu'] . ' ' . $a['name']);

			$hits = $this->naver_local->search($query, 'random');

			$found = NULL;

			foreach ($hits as $h)
			{
				if ( ! empty($h['lat']) && ! empty($h['lng']))
				{
					$found = $h;
					break;
				}
			}

			if ($found === NULL)
			{
				$failed[] = $a['name'];
				continue;
			}

			$this->db->where('id', $a['id'])->update('t_areas', array(
				'lat'          => $found['lat'],
				'lng'          => $found['lng'],
				'geo_verified' => 1,
				'geo_source'   => 'naver',
			));

			$done++;
		}

		return array('done' => $done, 'failed' => $failed, 'skipped' => '');
	}

	/** 좌표 미확인 지역 수 */
	public function pending_geo_count()
	{
		return (int) $this->db->where('is_active', 1)
			->where('geo_verified', 0)
			->count_all_results('t_areas');
	}

	public function area($id)
	{
		$row = $this->db->where('id', (int) $id)->get('t_areas')->row_array();

		return $row ?: NULL;
	}

	/**
	 * 이름으로 지역 찾기.
	 *
	 * 정확일치 -> 앞부분 일치 -> 부분일치 순으로 시도한다.
	 * 한 글자 검색어는 지역으로 해석하지 않는다 — "회"(횟집을 찾는 의도)가
	 * LIKE '%회%' 로 "회기역" 에 걸려 엉뚱한 지역으로 끌려가기 때문이다.
	 * 그런 검색어는 호출자가 업종 검색으로 처리하게 NULL 을 돌려준다.
	 */
	public function find_area_by_name($name)
	{
		$name = trim((string) $name);

		if ($name === '' OR mb_strlen($name, 'UTF-8') < 2)
		{
			return NULL;
		}

		// 1) 정확일치 ("강남역")
		$row = $this->db->where('name', $name)
			->where('is_active', 1)
			->limit(1)
			->get('t_areas')
			->row_array();

		if ($row)
		{
			return $row;
		}

		// 2) 앞부분 일치 ("강남" -> "강남역")
		$row = $this->db->like('name', $name, 'after')
			->where('is_active', 1)
			->order_by('sort_order', 'ASC')
			->limit(1)
			->get('t_areas')
			->row_array();

		if ($row)
		{
			return $row;
		}

		// 3) 부분일치 (마지막 수단)
		$row = $this->db->like('name', $name)
			->where('is_active', 1)
			->order_by('sort_order', 'ASC')
			->limit(1)
			->get('t_areas')
			->row_array();

		return $row ?: NULL;
	}

	// =========================================================
	//  로그
	// =========================================================

	public function log_search($keyword, array $criteria, $count, $naver_hit, $elapsed_ms, $ip = '')
	{
		$this->db->insert('t_search_logs', array(
			'keyword'    => mb_substr((string) $keyword, 0, 120),
			'criteria'   => json_encode($criteria, JSON_UNESCAPED_UNICODE),
			'result_cnt' => (int) $count,
			'naver_hit'  => $naver_hit ? 1 : 0,
			'elapsed_ms' => (int) $elapsed_ms,
			'ip'         => mb_substr((string) $ip, 0, 45),
		));
	}
}
