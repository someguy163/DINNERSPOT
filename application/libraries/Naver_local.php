<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 네이버 지역검색 클라이언트
 *
 * 같은 지역검색을 **발급처가 두 곳**이라 엔드포인트와 인증 헤더가 다르다.
 * 응답 본문 형식(items[].title/category/address/roadAddress/mapx/mapy)은 동일하다.
 *
 *   apihub     : 네이버 클라우드 플랫폼 > NAVER API HUB
 *                GET https://naverapihub.apigw.ntruss.com/search/v1/local
 *                X-NCP-APIGW-API-KEY-ID / X-NCP-APIGW-API-KEY
 *
 *   developers : 네이버 개발자센터 (developers.naver.com)
 *                GET https://openapi.naver.com/v1/search/local.json
 *                X-Naver-Client-Id / X-Naver-Client-Secret
 *
 * config 의 naver_api_mode 가 'auto'(기본)면 apihub 를 먼저 시도하고,
 * 인증 오류가 나면 developers 로 한 번 더 시도한 뒤 성공한 쪽을 요청 내내 쓴다.
 * 어느 쪽 키를 넣든 그냥 동작하게 하는 것이 목적이다.
 *
 * 어느 경로든 한 번의 호출로 최대 5건(display=5, start=1)만 돌려준다.
 * 그래서 "지역명 + 키워드" 조합으로 질의를 여러 번 나눠 던지고
 * 결과를 합쳐서 DB 에 캐싱하는 방식으로 후보 풀을 만든다.
 */
class Naver_local {

	/** 모드별 엔드포인트 */
	protected $endpoints = array(
		'apihub'     => 'https://naverapihub.apigw.ntruss.com/search/v1/local',
		'developers' => 'https://openapi.naver.com/v1/search/local.json',
	);

	/** 확정된 모드 (NULL 이면 아직 미확정) */
	protected $mode = NULL;

	/** @var CI_Controller */
	protected $CI;

	protected $client_id;
	protected $client_secret;
	protected $timeout;
	protected $display;

	/** 마지막 호출에서 발생한 오류 메시지 */
	protected $last_error = '';

	/** 이번 요청에서 실제로 네이버를 몇 번 때렸는지 */
	protected $call_count = 0;

	public function __construct()
	{
		$this->CI = &get_instance();
		$this->CI->config->load('dinnerspot', TRUE, TRUE);

		$this->client_id     = (string) $this->cfg('naver_client_id');
		$this->client_secret = (string) $this->cfg('naver_client_secret');
		$this->timeout       = (int) ($this->cfg('naver_timeout') ?: 4);
		$this->display       = (int) ($this->cfg('naver_display') ?: 5);
	}

	protected function cfg($key, $default = NULL)
	{
		$val = $this->CI->config->item($key, 'dinnerspot');

		return ($val === NULL) ? $default : $val;
	}

	/** 키가 세팅되어 있는지 */
	public function is_enabled()
	{
		return ($this->client_id !== '' && $this->client_secret !== '');
	}

	public function last_error()
	{
		return $this->last_error;
	}

	public function call_count()
	{
		return $this->call_count;
	}

	/**
	 * 단일 질의
	 *
	 * @param  string $query 검색어 (예: "강남역 고기집")
	 * @param  string $sort  'random' | 'comment'(리뷰순). 생략하면 config 값
	 * @return array  정규화된 장소 배열. 각 원소에 naver_rank(1부터) 가 붙는다
	 */
	public function search($query, $sort = NULL)
	{
		$sort = $sort ?: (string) ($this->cfg('naver_sort') ?: 'comment');

		$this->last_error = '';

		if ( ! $this->is_enabled())
		{
			$this->last_error = 'API 키 미설정';

			return array();
		}

		$query = trim((string) $query);

		if ($query === '')
		{
			return array();
		}

		$sort = in_array($sort, array('random', 'comment'), TRUE) ? $sort : 'comment';

		// 모드가 아직 안 정해졌으면 후보를 순서대로 시도한다.
		// 인증 오류일 때만 다음 후보로 넘어가고, 성공하면 그 모드로 고정한다.
		$json = NULL;

		foreach ($this->modes_to_try() as $mode)
		{
			$json = $this->request($mode, $query, $sort);

			if ($json !== NULL)
			{
				$this->mode = $mode;

				// 앞선 모드가 인증 오류로 실패했다가 이쪽에서 성공한 경우다.
				// 여기서 지우지 않으면 last_error 에 죽은 오류가 남아
				// search_many() 가 is_fatal_error() 로 오해해 첫 질의에서 멈추고,
				// diagnose() 도 성공했는데 "연결 실패" 라고 보고한다.
				$this->last_error = '';
				break;
			}

			// 인증 문제가 아니면(쿼터·네트워크 등) 다른 모드로 바꿔도 소용없다
			if ( ! $this->is_auth_error())
			{
				break;
			}
		}

		if ($json === NULL)
		{
			return array();
		}

		if (empty($json['items']) OR ! is_array($json['items']))
		{
			return array();
		}

		$out  = array();
		$rank = 0;

		foreach ($json['items'] as $item)
		{
			$rank++;
			$row = $this->normalize($item);

			if ($row !== NULL)
			{
				// 리뷰순 정렬로 받았으므로 응답 순서가 곧 인기도 순위다.
				// 네이버는 평점/리뷰수를 주지 않아서 이 값이 유일한 인기도 신호다.
				$row['naver_rank'] = $rank;
				$out[] = $row;
			}
		}

		return $out;
	}

	/** 확정됐거나 설정된 모드. 미확정이면 시도할 순서를 돌려준다. */
	protected function modes_to_try()
	{
		if ($this->mode !== NULL)
		{
			return array($this->mode);
		}

		$cfg = (string) ($this->cfg('naver_api_mode') ?: 'auto');

		if (isset($this->endpoints[$cfg]))
		{
			return array($cfg);
		}

		// auto: 클라우드 플랫폼(API HUB) 을 먼저 본다.
		// 개발자센터 키는 Client ID 가 길고 대소문자가 섞여 있어 그쪽을 먼저 시도한다.
		return preg_match('/^[a-z0-9]{10}$/', $this->client_id)
			? array('apihub', 'developers')
			: array('developers', 'apihub');
	}

	/** 현재 사용 중인(또는 확정된) 모드 */
	public function mode()
	{
		return $this->mode;
	}

	/**
	 * 한 모드로 한 번 호출한다.
	 *
	 * @return array|NULL 파싱된 JSON. 실패하면 NULL 이고 last_error 가 채워진다.
	 */
	protected function request($mode, $query, $sort)
	{
		$params = array(
			'query'   => $query,
			'display' => max(1, min(5, $this->display)),
			'start'   => 1,
			'sort'    => $sort,
		);

		if ($mode === 'apihub')
		{
			$params['format'] = 'json';
			$headers = array(
				'X-NCP-APIGW-API-KEY-ID: ' . $this->client_id,
				'X-NCP-APIGW-API-KEY: ' . $this->client_secret,
			);
		}
		else
		{
			$headers = array(
				'X-Naver-Client-Id: ' . $this->client_id,
				'X-Naver-Client-Secret: ' . $this->client_secret,
			);
		}

		$url = $this->endpoints[$mode] . '?' . http_build_query($params);
		$raw = $this->http_get($url, $headers);

		if ($raw === FALSE)
		{
			return NULL;
		}

		$json = json_decode($raw, TRUE);

		if ( ! is_array($json))
		{
			$this->last_error = '응답 파싱 실패';

			return NULL;
		}

		// 개발자센터 형식 오류
		if (isset($json['errorCode']))
		{
			$this->last_error = '[' . $json['errorCode'] . '] '
				. (isset($json['errorMessage']) ? $json['errorMessage'] : '네이버 API 오류');

			return NULL;
		}

		// API Gateway 형식 오류
		if (isset($json['error']))
		{
			$e = $json['error'];
			$this->last_error = '[' . (isset($e['errorCode']) ? $e['errorCode'] : '?') . '] '
				. (isset($e['message']) ? $e['message'] : '')
				. (isset($e['details']) ? ' - ' . $e['details'] : '');

			return NULL;
		}

		if ( ! isset($json['items']))
		{
			$this->last_error = '예상과 다른 응답';

			return NULL;
		}

		return $json;
	}

	/**
	 * 이 질의를 최근에 던졌는가.
	 *
	 * naver_cache_hours 안에 던진 질의는 결과가 같으므로 다시 호출하지 않는다.
	 * 캐시 단위가 "질의" 라는 점이 중요하다 - "지역" 단위로 잡으면 같은 지역에서
	 * 새 카테고리를 골랐을 때 그 질의가 한 번도 나가지 않는다.
	 */
	protected function query_is_fresh($query)
	{
		$hours = (int) ($this->cfg('naver_cache_hours') ?: 24);

		if ($hours <= 0)
		{
			return FALSE;
		}

		$row = $this->CI->db
			->select('id')
			->where('query_hash', sha1(trim((string) $query)))
			->where('fetched_at >=', date('Y-m-d H:i:s', time() - $hours * 3600))
			->get('t_naver_queries')
			->row_array();

		return ! empty($row);
	}

	/** 질의를 던졌다고 기록한다 (있으면 시각만 갱신) */
	protected function remember_query($query, $hits)
	{
		$query = trim((string) $query);
		$hash  = sha1($query);
		$now   = date('Y-m-d H:i:s');

		$exists = $this->CI->db->select('id')->where('query_hash', $hash)
			->get('t_naver_queries')->row_array();

		if ($exists)
		{
			$this->CI->db->where('id', $exists['id'])->update('t_naver_queries', array(
				'hit_count'  => (int) $hits,
				'fetched_at' => $now,
			));

			return;
		}

		$this->CI->db->insert('t_naver_queries', array(
			'query_hash' => $hash,
			'query_text' => mb_substr($query, 0, 191, 'UTF-8'),
			'hit_count'  => (int) $hits,
			'fetched_at' => $now,
		));
	}

	/** 마지막 오류가 인증/경로 문제라 다른 모드를 시도해볼 만한가 */
	protected function is_auth_error()
	{
		foreach (array('[024]', '[028]', '[300]', '[200]', 'HTTP 401', 'HTTP 403', 'HTTP 404') as $sig)
		{
			if (strpos($this->last_error, $sig) !== FALSE)
			{
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * 여러 질의를 순차 호출해 중복 제거한 결과를 돌려준다.
	 *
	 * @param  array $queries 검색어 목록
	 * @param  int   $limit   최대 질의 수
	 * @return array
	 */
	public function search_many(array $queries, $limit = NULL)
	{
		$limit = $limit ?: (int) ($this->cfg('naver_max_queries') ?: 6);
		$seen  = array();
		$out   = array();
		$sent  = 0;

		foreach ($queries as $q)
		{
			if ($sent >= $limit)
			{
				break;
			}

			// 질의 단위 캐시. 같은 질의를 최근에 던졌으면 건너뛴다.
			// 지역 단위로 막으면 새 카테고리에 대한 질의가 영원히 안 나간다.
			if ($this->query_is_fresh($q))
			{
				continue;
			}

			$sent++;
			$rows = $this->search($q);

			// **실패한 질의는 캐시에 기록하지 않는다.**
			// 기록해버리면 네트워크 한 번 끊긴 것만으로 그 질의가
			// naver_cache_hours(기본 24시간) 동안 봉인되어, 키를 고쳐도
			// 그 지역이 하루 종일 0건으로 남는다.
			// 호출이 성공하고 결과가 0건인 경우는 last_error 가 비어 있으므로
			// 지금까지처럼 정상 캐싱된다.
			if ($this->last_error === '')
			{
				$this->remember_query($q, count($rows));
			}

			// 키가 틀렸거나 쿼터를 넘긴 상황이면 남은 질의를 던져도 결과가 같다.
			// 할당량을 더 태우지 않도록 즉시 중단한다.
			if ($this->is_fatal_error())
			{
				break;
			}

			foreach ($rows as $row)
			{
				$key = $row['source_key'];

				// 여러 질의에 걸쳐 나오면 가장 좋은(작은) 순위를 남긴다.
				// "강남역 회식" 에서 1위인 집이 "강남역 맛집" 에서 4위여도 1위로 본다.
				if (isset($seen[$key]))
				{
					$at = $seen[$key];

					if ($row['naver_rank'] < $out[$at]['naver_rank'])
					{
						$out[$at]['naver_rank'] = $row['naver_rank'];
					}

					continue;
				}

				$seen[$key] = count($out);
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * 연결 진단.
	 *
	 * 실제로 한 번 호출해서 결과를 사람이 읽을 수 있는 형태로 돌려준다.
	 * 특히 네이버 클라우드 플랫폼(NCP) 자격증명을 개발자센터 검색 API 자리에
	 * 넣는 실수를 짚어준다 - 발급처가 달라서 헷갈리기 쉽다.
	 *
	 * @return array ok / status / message / hint / sample
	 */
	public function diagnose()
	{
		if ( ! $this->is_enabled())
		{
			return array(
				'ok'      => FALSE,
				'status'  => 'empty',
				'message' => 'Client ID / Secret 이 비어 있습니다.',
				'hint'    => 'application/config/dinnerspot_local.php 에 넣으세요. '
					. 'dinnerspot.php 에 넣으면 local 파일이 덮어써서 무시됩니다.',
				'sample'  => array(),
			);
		}

		$rows = $this->search('강남역 회식', 'comment');
		$err  = $this->last_error();

		if ($err === '' && ! empty($rows))
		{
			$names = array();

			foreach (array_slice($rows, 0, 3) as $r)
			{
				$names[] = $r['name'];
			}

			$where = ($this->mode === 'apihub')
				? '클라우드 플랫폼 NAVER API HUB'
				: '네이버 개발자센터';

			return array(
				'ok'      => TRUE,
				'status'  => 'ok',
				'message' => count($rows) . '건을 받았습니다. ' . $where . ' 경유로 정상 연결되었습니다.',
				'hint'    => '',
				'sample'  => $names,
			);
		}

		if ($err === '')
		{
			return array(
				'ok'      => TRUE,
				'status'  => 'empty_result',
				'message' => '호출은 성공했지만 결과가 0건입니다.',
				'hint'    => '인증은 통과했습니다. 검색어에 해당하는 장소가 없을 뿐입니다.',
				'sample'  => array(),
			);
		}

		// 자격증명 형태로 발급처를 추정한다.
		// NCP API Gateway 키는 ID 가 소문자+숫자 10자, Secret 이 영숫자 40자다.
		$looks_ncp = (preg_match('/^[a-z0-9]{10}$/', $this->client_id)
			&& preg_match('/^[A-Za-z0-9]{40}$/', $this->client_secret));

		$hint = '';

		if (strpos($err, '[024]') !== FALSE)
		{
			$hint = $looks_ncp
				? '클라우드 플랫폼 자격증명 형태입니다. NAVER API HUB 콘솔에서 Application 에 '
					. '"NAVER 검색 > 지역" 이 등록되어 있는지, 인증 정보의 Client ID/Secret 을 '
					. '그대로 넣었는지 확인하세요.'
				: '개발자센터의 "내 애플리케이션" 에서 해당 앱에 "검색" API 가 '
					. '추가되어 있는지 확인하세요.';
		}
		elseif (strpos($err, '[012]') !== FALSE OR strpos($err, '[028]') !== FALSE)
		{
			$hint = '앱에 "검색" API 가 추가되어 있지 않거나 호출 권한이 없습니다. '
				. '개발자센터 > 내 애플리케이션 > API 설정에서 "검색" 을 추가하세요.';
		}
		elseif (strpos($err, 'HTTP 429') !== FALSE)
		{
			$hint = '일일 호출 한도를 넘었습니다. 내일 다시 시도하거나 '
				. 'naver_max_queries / naver_cache_hours 를 조절하세요.';
		}
		elseif (strpos($err, 'cURL') !== FALSE)
		{
			$hint = '네트워크에서 openapi.naver.com 에 닿지 못했습니다. 방화벽/프록시를 확인하세요.';
		}

		return array(
			'ok'      => FALSE,
			'status'  => 'error',
			'message' => $err,
			'hint'    => $hint,
			'sample'  => array(),
		);
	}

	/**
	 * 지도(Maps) Key ID 진단.
	 *
	 * maps.js 는 브라우저에서 v3/auth 를 호출해 인증한다. 실패 원인이
	 * "키가 등록되지 않음" 인지 "서비스 URL 미등록" 인지 밖에서는 구분이 안 되는데,
	 * **없는 키를 넣었을 때와 응답이 같은지** 비교하면 갈라낼 수 있다.
	 *   - 없는 키와 응답이 같다  -> Maps 에 등록되지 않은 Key ID
	 *   - 없는 키와 응답이 다르다 -> 키는 있고 서비스 URL/Dynamic Map 설정 문제
	 *
	 * @return array
	 */
	public function diagnose_map($service_url)
	{
		$key = (string) $this->cfg('naver_map_key_id', '');

		if ($key === '')
		{
			return array(
				'ok'      => FALSE,
				'status'  => 'empty',
				'message' => 'Key ID 가 비어 있습니다.',
				'hint'    => '지도를 쓰지 않는다면 그대로 두어도 됩니다. 목록·투표는 정상 동작합니다.',
			);
		}

		$mine  = $this->map_auth($key, $service_url);
		$bogus = $this->map_auth('zzzz999999', $service_url);

		if ($mine['code'] === 200)
		{
			return array(
				'ok'      => TRUE,
				'status'  => 'ok',
				'message' => '지도 인증에 성공했습니다.',
				'hint'    => '',
			);
		}

		// 없는 키와 응답이 같으면 = Maps 가 이 키를 모른다
		if ($mine['code'] === $bogus['code'] && $mine['body'] === $bogus['body'])
		{
			return array(
				'ok'      => FALSE,
				'status'  => 'unknown_key',
				'message' => 'HTTP ' . $mine['code'] . ' · 네이버 지도가 이 Key ID 를 모릅니다 '
					. '(존재하지 않는 키를 넣었을 때와 응답이 같습니다).',
				'hint'    => 'console.ncloud.com/maps/application 에서 Application 을 등록하고, '
					. '거기 표시되는 Client ID 를 넣으세요. 계정 인증키(Access Key ID)나 '
					. '개발자센터 Client ID 와는 다른 값입니다.',
			);
		}

		return array(
			'ok'      => FALSE,
			'status'  => 'config',
			'message' => 'HTTP ' . $mine['code'] . ' · 키는 인식되지만 인증이 거부되었습니다.',
			'hint'    => 'Application 수정 화면에서 "Dynamic Map" 이 체크되어 있는지, '
				. 'Web 서비스 URL 에 ' . $service_url . ' 이 등록되어 있는지 확인하세요.',
		);
	}

	/** v3/auth 한 번 호출 */
	protected function map_auth($key, $service_url)
	{
		$url = 'https://oapi.map.naver.com/v3/auth?ncpKeyId=' . rawurlencode($key)
			. '&url=' . rawurlencode($service_url)
			. '&time=' . (time() * 1000);

		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_HTTPHEADER     => array('Referer: ' . $service_url),
		));

		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return array('code' => $code, 'body' => (string) $body);
	}

	/**
	 * 재시도해도 소용없는 오류인지 (인증 실패 / 쿼터 초과 / 잘못된 앱)
	 */
	protected function is_fatal_error()
	{
		if ($this->last_error === '')
		{
			return FALSE;
		}

		// 024 인증실패, 010 시스템오류(앱 미등록), 012 잘못된 요청 주체,
		// 429 쿼터 초과, 401/403 권한 없음
		foreach (array('[024]', '[010]', '[012]', 'HTTP 401', 'HTTP 403', 'HTTP 429') as $sig)
		{
			if (strpos($this->last_error, $sig) !== FALSE)
			{
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * 네이버 item -> 내부 스키마
	 */
	protected function normalize(array $item)
	{
		$name = ds_strip_naver_tag(isset($item['title']) ? $item['title'] : '');

		if ($name === '')
		{
			return NULL;
		}

		$road    = isset($item['roadAddress']) ? trim($item['roadAddress']) : '';
		$address = isset($item['address']) ? trim($item['address']) : '';

		list($lat, $lng) = $this->to_wgs84(
			isset($item['mapx']) ? $item['mapx'] : 0,
			isset($item['mapy']) ? $item['mapy'] : 0
		);

		return array(
			'source'       => 'naver',
			'source_key'   => $this->make_key($name, $road !== '' ? $road : $address),
			'name'         => $name,
			'category_raw' => isset($item['category']) ? trim($item['category']) : '',
			'address'      => $address,
			'road_address' => $road,
			'phone'        => isset($item['telephone']) ? trim($item['telephone']) : '',
			'homepage'     => isset($item['link']) ? trim($item['link']) : '',
			'lat'          => $lat,
			'lng'          => $lng,
		);
	}

	/**
	 * 상호 + 주소로 중복 판정 키 생성
	 */
	protected function make_key($name, $address)
	{
		$norm = preg_replace('/\s+/u', '', $name . '|' . $address);

		return substr(sha1(mb_strtolower($norm, 'UTF-8')), 0, 40);
	}

	/**
	 * mapx/mapy 좌표 변환.
	 *
	 * 지역검색 API 는 현재 WGS84 경위도를 10^7 배한 정수를 준다
	 * (예: mapx=1270276620 -> 127.0276620).
	 * 과거 KATEC 좌표(6자리대)로 내려오는 경우가 있어 값의 자릿수로 분기한다.
	 *
	 * @return array [lat, lng]
	 */
	protected function to_wgs84($mapx, $mapy)
	{
		$x = (float) $mapx;
		$y = (float) $mapy;

		if ($x <= 0 OR $y <= 0)
		{
			return array(0.0, 0.0);
		}

		// 10^7 스케일 (정상 케이스)
		if ($x > 1000000)
		{
			$lng = $x / 10000000;
			$lat = $y / 10000000;
		}
		// 이미 경위도로 내려온 케이스
		elseif ($x > 100 && $x < 200)
		{
			$lng = $x;
			$lat = $y;
		}
		// 그 외(구 KATEC 등)는 신뢰할 수 없으므로 좌표 없음 처리
		else
		{
			return array(0.0, 0.0);
		}

		// 대한민국 범위를 벗어나면 버린다
		if ($lat < 32 OR $lat > 40 OR $lng < 124 OR $lng > 132)
		{
			return array(0.0, 0.0);
		}

		return array(round($lat, 7), round($lng, 7));
	}

	/**
	 * cURL GET
	 */
	protected function http_get($url, array $headers = array())
	{
		if ( ! function_exists('curl_init'))
		{
			$this->last_error = 'cURL 확장이 없습니다';

			return FALSE;
		}

		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_SSL_VERIFYPEER => TRUE,
			CURLOPT_HTTPHEADER     => array_merge($headers, array('Accept: application/json')),
		));

		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		$this->call_count++;

		if ($body === FALSE)
		{
			$this->last_error = 'cURL 오류: ' . $err;
			log_message('error', 'Naver_local cURL: ' . $err);

			return FALSE;
		}

		if ($code !== 200)
		{
			$this->last_error = 'HTTP ' . $code;
			log_message('error', 'Naver_local HTTP ' . $code . ' : ' . substr($body, 0, 300));

			// 401/403 등도 본문에 errorCode 가 있으므로 그대로 넘긴다
			return $body;
		}

		return $body;
	}
}
