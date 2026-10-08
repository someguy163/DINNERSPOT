<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * DINNERSPOT 공용 헬퍼
 */

if ( ! function_exists('h'))
{
	/** HTML 이스케이프 축약 */
	function h($str)
	{
		return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
	}
}

if ( ! function_exists('ds_asset'))
{
	/**
	 * 캐시 무효화용 mtime 을 붙인 정적 자원 URL
	 *
	 * $path 는 assets/ 아래의 상대경로(`css/app.css`)다. 존재 확인 경로에
	 * 'assets/' 를 빼먹으면 file_exists 가 항상 FALSE 가 되어 매 요청 time() 이
	 * 붙고, 파일이 그대로인데도 URL 이 계속 바뀌어 브라우저 캐시가 완전히 죽는다.
	 */
	function ds_asset($path)
	{
		$path = ltrim($path, '/');
		$full = FCPATH . 'assets/' . $path;
		$ver  = file_exists($full) ? filemtime($full) : time();

		return base_url('assets/' . $path) . '?v=' . $ver;
	}
}

if ( ! function_exists('ds_won'))
{
	/** 12000 -> "1.2만원", 9500 -> "9,500원" */
	function ds_won($amount)
	{
		$amount = (int) $amount;

		if ($amount <= 0)
		{
			return '가격 미상';
		}
		if ($amount >= 10000)
		{
			$man = round($amount / 10000, 1);
			$man = ($man == (int) $man) ? (int) $man : $man;

			return $man . '만원';
		}

		return number_format($amount) . '원';
	}
}

if ( ! function_exists('ds_distance_label'))
{
	/** 미터 -> "도보 3분 · 240m" 형태 라벨 */
	function ds_distance_label($meters)
	{
		if ($meters === NULL || $meters < 0)
		{
			return '';
		}

		$meters = (int) round($meters);
		$walk   = max(1, (int) round($meters / 67)); // 도보 약 67m/분

		$dist = ($meters >= 1000)
			? round($meters / 1000, 1) . 'km'
			: $meters . 'm';

		return ($meters <= 1600)
			? '도보 ' . $walk . '분 · ' . $dist
			: $dist;
	}
}

if ( ! function_exists('ds_haversine'))
{
	/** 두 좌표 사이 거리(m) */
	function ds_haversine($lat1, $lng1, $lat2, $lng2)
	{
		$r = 6371000;
		$p1 = deg2rad((float) $lat1);
		$p2 = deg2rad((float) $lat2);
		$dp = deg2rad((float) $lat2 - (float) $lat1);
		$dl = deg2rad((float) $lng2 - (float) $lng1);

		$a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

		return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
	}
}

if ( ! function_exists('ds_strip_naver_tag'))
{
	/** 네이버 검색결과 title 의 <b> 태그와 엔티티 제거 */
	function ds_strip_naver_tag($str)
	{
		$str = strip_tags((string) $str);

		return trim(html_entity_decode($str, ENT_QUOTES, 'UTF-8'));
	}
}

if ( ! function_exists('ds_locality_hint'))
{
	/**
	 * 주소에서 검색에 쓸 지역 힌트만 뽑는다 -> "서울 강남구 역삼동"
	 *
	 * 네이버 지역검색의 address / roadAddress 는 뒤에 건물명 · 층 · 호수와
	 * 가게 이름까지 붙어 온다 (예: "서울특별시 강남구 역삼동 619-5 지하1층/2층 다몽집").
	 * 그대로 검색어에 넣으면 지도에서 아무것도 안 잡히므로 앞쪽 행정구역만 남긴다.
	 *
	 * 지번주소를 우선 쓴다. 3번째 토큰이 "역삼동" 처럼 깨끗하게 끝나기 때문이다
	 * (표본 400곳 중 356곳). 도로명주소는 "강남대로94길 28" 이라 힌트로 못 쓴다.
	 */
	function ds_locality_hint($address)
	{
		$parts = preg_split('/\s+/u', trim((string) $address), -1, PREG_SPLIT_NO_EMPTY);

		if ( ! $parts)
		{
			return '';
		}

		// 시/도는 줄여 쓴다. "남구 대명동" 은 여러 도시에 있어 시/도가 있어야 갈린다.
		$sido = preg_replace('/(특별자치[시도]|특별시|광역시|자치시|[시도])$/u', '', $parts[0]);
		$out  = ($sido !== '') ? array($sido) : array();

		// 읍/면/동/가/리 를 만나면 거기서 멈춘다. 그 뒤는 번지 · 층 · 호수 · 건물명이다.
		for ($i = 1, $n = min(count($parts), 5); $i < $n; $i++)
		{
			$out[] = $parts[$i];

			if (preg_match('/[읍면동가리]\d*$/u', $parts[$i]))
			{
				break;
			}

			// 시 · 군 · 구 만 계속 따라간다 ("수원시 팔달구 인계동")
			if ( ! preg_match('/[시군구]$/u', $parts[$i]))
			{
				array_pop($out);
				break;
			}
		}

		return implode(' ', $out);
	}
}

if ( ! function_exists('ds_naver_route_url'))
{
	/**
	 * 네이버 지도 길찾기 링크. 좌표가 있으면 그 지점으로 바로 보낸다.
	 *
	 * 출발지는 비워 둔다(-). 결과 화면은 고른 역을 알지만 상세 화면은 모르고,
	 * 어차피 네이버가 현재 위치나 최근 출발지를 채워 준다. 양쪽을 같게 둔다.
	 * 대중교통(transit)이 기본이다 — 역 근처를 고르는 앱이라 자동차보다 맞다.
	 *
	 * 쉼표는 경로 구분자라 인코딩하지 않는다. 이름만 인코딩한다.
	 */
	function ds_naver_route_url($lat, $lng, $name)
	{
		$lat = (float) $lat;
		$lng = (float) $lng;

		// 좌표가 없으면 길찾기를 걸 수 없다 — 이름으로 찾는 쪽으로 보낸다
		if ($lat === 0.0 OR $lng === 0.0)
		{
			return ds_naver_map_url($name);
		}

		return 'https://map.naver.com/p/directions/-/'
			. $lng . ',' . $lat . ',' . rawurlencode(ds_strip_naver_tag($name))
			. '/-/transit';
	}
}

if ( ! function_exists('ds_link_label'))
{
	/**
	 * 네이버가 준 link(=homepage) 가 실제로 무엇인지 이름 붙인다.
	 *
	 * 이 값을 전부 "홈페이지" 라고 부르면 거짓이 된다 — 실측하면 링크가 있는
	 * 326곳 중 인스타그램 102 · 네이버블로그 33 · 캐치테이블 32 이고, 가게가
	 * 직접 운영하는 홈페이지는 오히려 적다. 누르기 전에 어디로 가는지 알려준다.
	 */
	function ds_link_label($url)
	{
		$host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

		if ($host === '')
		{
			return '홈페이지';
		}

		$map = array(
			'instagram.com'   => '인스타그램',
			'blog.naver.com'  => '네이버 블로그',
			'cafe.naver.com'  => '네이버 카페',
			'catchtable.co.kr'=> '예약 · 캐치테이블',
			'tabling.co.kr'   => '예약 · 테이블링',
			'youtube.com'     => '유튜브',
			'youtu.be'        => '유튜브',
			'facebook.com'    => '페이스북',
			'smartstore.naver.com' => '네이버 스마트스토어',
			'booking.naver.com'    => '네이버 예약',
		);

		foreach ($map as $needle => $label)
		{
			// app.catchtable.co.kr 처럼 앞에 뭔가 붙어도 잡히게 끝을 맞춰 본다
			if ($host === $needle OR substr($host, -strlen('.' . $needle)) === '.' . $needle)
			{
				return $label;
			}
		}

		return '홈페이지';
	}
}

if ( ! function_exists('ds_naver_map_url'))
{
	/**
	 * 네이버 지도 검색 링크. 목록 카드와 상세 페이지가 같은 링크를 쓴다.
	 *
	 * 검색어는 "가게이름 + 시도 구 동" 까지만 넣는다. 주소를 통째로 붙이면
	 * 층 · 호수 · 중복된 가게 이름 때문에 지도에서 검색이 안 된다.
	 */
	function ds_naver_map_url($name, $address = '')
	{
		// 이름 뒤 괄호 설명은 검색을 방해한다 -> "육향(가산점)" => "육향"
		$q = trim(preg_replace('/\s*[\(（][^\)）]*[\)）]\s*/u', ' ', ds_strip_naver_tag($name)));
		$q = ($q !== '') ? $q : ds_strip_naver_tag($name);

		$hint = ds_locality_hint($address);

		if ($hint !== '')
		{
			// 이름에 이미 있는 토큰은 또 넣지 않는다
			$add = array();

			foreach (explode(' ', $hint) as $t)
			{
				if ($t !== '' && mb_strpos($q, $t) === FALSE)
				{
					$add[] = $t;
				}
			}

			if ($add)
			{
				$q .= ' ' . implode(' ', $add);
			}
		}

		return 'https://map.naver.com/p/search/' . rawurlencode($q);
	}
}

if ( ! function_exists('ds_token'))
{
	/** 32자 랜덤 토큰 */
	function ds_token($len = 32)
	{
		if (function_exists('random_bytes'))
		{
			return substr(bin2hex(random_bytes((int) ceil($len / 2))), 0, $len);
		}

		return substr(md5(uniqid((string) mt_rand(), TRUE)), 0, $len);
	}
}

if ( ! function_exists('ds_room_code'))
{
	/** 사람이 옮겨적기 쉬운 방 코드 (혼동문자 0,O,1,I,L 제외) */
	function ds_room_code($len = 8)
	{
		$pool = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$max  = strlen($pool) - 1;
		$code = '';

		for ($i = 0; $i < $len; $i++)
		{
			$code .= $pool[mt_rand(0, $max)];
		}

		return $code;
	}
}

if ( ! function_exists('ds_time_ago'))
{
	/** "3분 전" 형태 */
	function ds_time_ago($datetime)
	{
		if (empty($datetime))
		{
			return '';
		}

		$diff = time() - strtotime($datetime);

		if ($diff < 60)   return '방금';
		if ($diff < 3600) return floor($diff / 60) . '분 전';
		if ($diff < 86400) return floor($diff / 3600) . '시간 전';

		return floor($diff / 86400) . '일 전';
	}
}

if ( ! function_exists('ds_score_grade'))
{
	/** 점수 -> 등급 라벨/색 클래스 */
	function ds_score_grade($score)
	{
		if ($score >= 85) return array('label' => '강력추천', 'class' => 'grade-s');
		if ($score >= 72) return array('label' => '추천',     'class' => 'grade-a');
		if ($score >= 58) return array('label' => '무난',     'class' => 'grade-b');

		return array('label' => '차선책', 'class' => 'grade-c');
	}
}

if ( ! function_exists('ds_criteria_params'))
{
	/**
	 * 검색 조건을 파라미터 배열로 되돌린다.
	 *
	 * $_GET 을 그대로 쓰지 않는 이유: /recommend 는 POST 도 받으므로
	 * POST 로 들어온 요청에서는 $_GET 이 비어 있어 페이지 링크가
	 * 조건을 전부 잃는다. 정규화된 criteria 에서 다시 만든다.
	 *
	 * 페이지 링크(ds_criteria_query)와 폼의 hidden 입력(ds_criteria_inputs)이
	 * 같은 목록을 써야 한다. 한쪽에만 항목을 더하면 그 조건이 조용히 사라진다.
	 *
	 * @param  array $c    Recommender::normalize_criteria() 결과
	 * @param  array $over 덮어쓸 값 (예: array('page' => 3))
	 * @return array
	 */
	function ds_criteria_params(array $c, array $over = array())
	{
		$q = array();

		// 기준점: 실제로 값이 있는 것만 싣는다.
		// 지역을 골랐다면 좌표는 싣지 않는다 — resolve_origin() 이 area_id 를
		// 먼저 보므로 중복이고, 그 좌표는 사용자가 준 게 아니라 지역 사전에서
		// 채워 넣은 값이라 링크에 남으면 "내 위치로 찾은 결과" 처럼 보인다.
		if ( ! empty($c['area_id']))
		{
			$q['area_id'] = (int) $c['area_id'];
		}
		elseif ( ! empty($c['lat']) && ! empty($c['lng']))
		{
			$q['lat'] = $c['lat'];
			$q['lng'] = $c['lng'];
		}

		// find 와 같은 이유로 empty() 를 쓰지 않는다 — 검색어가 '0' 이면
		// empty('0') 이 TRUE 라서 페이지 링크와 찾기 폼에서 keyword 가 조용히
		// 빠졌다. keyword 는 기준점이자 후보 집합을 정하는 값이라 find 보다
		// 파급이 크다: 실측으로 area_id 없이 keyword=0 은 24곳이었는데
		// 2페이지 링크(keyword 없음)를 따라가면 43곳의 다른 집합이 나오고
		// 검색창은 비어 버렸다. 빈 문자열만 제외한다.
		if (isset($c['keyword']) && (string) $c['keyword'] !== '') $q['keyword'] = $c['keyword'];

		$q['headcount'] = (int) $c['headcount'];
		$q['budget']    = (int) $c['budget'];
		$q['radius']    = (int) $c['radius'];
		$q['purpose']   = $c['purpose'];

		if ( ! empty($c['categories']))
		{
			$q['categories'] = $c['categories'];
		}

		foreach (array('need_room', 'need_parking', 'need_late', 'no_alcohol', 'strict') as $flag)
		{
			if ( ! empty($c[$flag]))
			{
				$q[$flag] = 1;
			}
		}

		// 결과 안에서 찾은 말은 페이지를 넘겨도 유지되어야 한다.
		// empty() 를 쓰면 안 된다 — 찾는 말이 '0' 일 때 empty('0') 이 TRUE 라서
		// 페이지 링크에서만 find 가 조용히 빠졌다. 화면은 '전체 200곳 중 109곳'
		// 이라고 걸러졌다고 말하는데 2페이지를 누르면 걸러내기가 풀린 200곳이
		// 나오고 순위 번호도 딴 값이 됐다(실측). 빈 문자열만 제외한다.
		if (isset($c['find']) && (string) $c['find'] !== '') $q['find'] = $c['find'];

		if ( ! empty($c['per_page'])) $q['per_page'] = (int) $c['per_page'];

		// source_mode 는 싣지 않는다. Spot_service 가 키 유무를 보고 자동으로
		// 결정한 값이 criteria 에 들어와 있어서, 그대로 링크에 남기면 사람이
		// 고르지 않은 값을 고정하게 된다(키 없는 환경에서 빈 결과가 된다).
		// 비교용으로 직접 붙이는 파라미터라 링크가 유지할 대상이 아니다.

		return array_merge($q, $over);
	}
}

if ( ! function_exists('ds_criteria_query'))
{
	/**
	 * 검색 조건을 쿼리스트링으로 (페이지 링크용).
	 *
	 * @return string  '?a=1&b=2'  (빈 조건이면 '')
	 */
	function ds_criteria_query(array $c, array $over = array())
	{
		$q = ds_criteria_params($c, $over);

		return $q ? ('?' . http_build_query($q)) : '';
	}
}

if ( ! function_exists('ds_criteria_inputs'))
{
	/**
	 * 검색 조건을 폼의 hidden 입력으로.
	 *
	 * GET 폼은 action 에 붙인 쿼리스트링을 브라우저가 버리므로,
	 * 조건을 유지하려면 hidden 으로 다시 실어야 한다.
	 *
	 * @return string  HTML
	 */
	function ds_criteria_inputs(array $c, array $over = array())
	{
		$out = '';

		foreach (ds_criteria_params($c, $over) as $k => $v)
		{
			// NULL 은 "이 항목을 빼라" 는 뜻이다 (http_build_query 와 같은 규칙).
			// 폼에 name 만 남은 빈 hidden 을 심으면 같은 이름의 실제 입력과
			// 둘이 제출되어 어느 쪽이 이기는지가 순서에 좌우된다.
			if ($v === NULL)
			{
				continue;
			}

			if (is_array($v))
			{
				foreach ($v as $one)
				{
					$out .= '<input type="hidden" name="' . h($k) . '[]" value="' . h($one) . '">';
				}

				continue;
			}

			$out .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
		}

		return $out;
	}
}
