<?php
/**
 * 공공데이터 CSV -> t_areas 시드 SQL 생성기 (CLI 전용)
 *
 * 왜 DB 에 바로 넣지 않는가:
 *   기준 데이터의 정본은 `sql/02_seed.sql` 이다. DB 에만 넣으면 시드를 다시 돌리는
 *   순간 어긋나고, 무엇이 사람 손으로 들어온 값인지 추적할 수 없다. 그래서 이 도구는
 *   **붙여넣을 SQL 만 만든다.** 사람이 눈으로 보고 시드 파일에 넣는다.
 *
 * 왜 CSV 인가:
 *   역 목록 + 좌표를 한 번에 주는 검증된 REST 엔드포인트를 찾지 못했다.
 *   가장 넓은 자료(전국도시철도역사정보표준데이터, 1,073행)는 파일 내려받기로 제공된다.
 *   자세한 조사 결과는 README.md 의 "지역 정보 갱신" 절에 적었다.
 *
 * 사용법:
 *   php tools/areas_from_csv.php <csv파일> --source=<출처URL> [옵션]
 *
 * 옵션:
 *   --source=URL        좌표의 출처. 좌표가 있는 행에는 **필수** (추적 불가한 좌표 금지)
 *   --kind=station      station | district        (기본 station)
 *   --sido=서울         시/도를 강제 지정. 없으면 시도 컬럼 -> 주소에서 추론
 *   --only=서울,경기    이 시/도만 출력
 *   --start-sort=1230   sort_order 시작값 (기본 1230 — 현재 시드 마지막이 1220)
 *   --sort-step=10      sort_order 증가폭 (기본 10)
 *   --name-suffix=역    이름이 그 글자로 끝나지 않으면 붙인다 (기본 없음)
 *   --limit=200         최대 출력 행 수
 *   --encoding=CP949    입력 인코딩 강제 (기본: UTF-8 인지 보고 아니면 CP949)
 *   --dry-run           SQL 대신 인식한 컬럼 매핑과 통계만 출력
 *
 * 컬럼은 헤더 이름으로 찾는다 — 자료마다 헤더가 다르므로 별칭 표를 두고 맞춘다.
 * 이름/위도/경도를 못 찾으면 추측하지 않고 그 자리에서 멈춘다.
 */

if (PHP_SAPI !== 'cli')
{
	exit("CLI 로만 실행한다.\n");
}

// -------------------------------------------------------------
//  컬럼 별칭. 헤더에서 공백·괄호·밑줄을 지운 뒤 비교한다.
// -------------------------------------------------------------
$ALIAS = array(
	'name'    => array('역사명', '역명', '역이름', '역사한글명', '한글역사명', '정류장명',
	                   'stationname', 'stationnm', 'stinnm', 'stnnm', 'sttnnm', 'name',
	                   '지역명', '상권명', '행정동명'),
	'lat'     => array('위도', '역위도', '위도좌표', 'latmap', 'lat', 'latitude', 'ycoord', 'y좌표'),
	'lng'     => array('경도', '역경도', '경도좌표', 'gramap', 'lng', 'lon', 'long',
	                   'longitude', 'xcoord', 'x좌표'),
	'line'    => array('노선명', '노선', '호선', 'linename', 'lnnm', 'line', '운영노선명'),
	'addr'    => array('도로명주소', '소재지도로명주소', '역사도로명주소', '주소', '지번주소',
	                   '소재지지번주소', 'address', 'adres'),
	'sido'    => array('시도명', '시도', '광역시도', 'sido', 'ctprvnnm'),
	'sigungu' => array('시군구명', '시군구', 'sigungu', 'signgunm'),
);

// 주소 앞머리 -> 시드가 쓰는 시/도 표기
$SIDO_MAP = array(
	'서울특별시' => '서울', '서울시' => '서울', '서울' => '서울',
	'부산광역시' => '부산', '부산시' => '부산', '부산' => '부산',
	'대구광역시' => '대구', '대구시' => '대구', '대구' => '대구',
	'인천광역시' => '인천', '인천시' => '인천', '인천' => '인천',
	'광주광역시' => '광주', '광주시' => '광주', '광주' => '광주',
	'대전광역시' => '대전', '대전시' => '대전', '대전' => '대전',
	'울산광역시' => '울산', '울산시' => '울산', '울산' => '울산',
	'세종특별자치시' => '세종', '세종시' => '세종', '세종' => '세종',
	'경기도' => '경기', '경기' => '경기',
	'강원특별자치도' => '강원', '강원도' => '강원', '강원' => '강원',
	'충청북도' => '충북', '충북' => '충북',
	'충청남도' => '충남', '충남' => '충남',
	'전북특별자치도' => '전북', '전라북도' => '전북', '전북' => '전북',
	'전라남도' => '전남', '전남' => '전남',
	'경상북도' => '경북', '경북' => '경북',
	'경상남도' => '경남', '경남' => '경남',
	'제주특별자치도' => '제주', '제주도' => '제주', '제주' => '제주',
);

// -------------------------------------------------------------
//  인자
// -------------------------------------------------------------
$argv_rest = array();
$opt = array(
	'source' => '', 'kind' => 'station', 'sido' => '', 'only' => '',
	'start-sort' => '1230', 'sort-step' => '10', 'name-suffix' => '',
	'limit' => '0', 'encoding' => '', 'dry-run' => FALSE,
);

foreach (array_slice($argv, 1) as $a)
{
	if (strpos($a, '--') === 0)
	{
		$kv = explode('=', substr($a, 2), 2);
		$k  = $kv[0];

		if ( ! array_key_exists($k, $opt))
		{
			fwrite(STDERR, "알 수 없는 옵션: --$k\n");
			exit(2);
		}

		$opt[$k] = isset($kv[1]) ? $kv[1] : TRUE;
	}
	else
	{
		$argv_rest[] = $a;
	}
}

if (empty($argv_rest))
{
	fwrite(STDERR, "사용법: php tools/areas_from_csv.php <csv파일> --source=<출처URL> [옵션]\n");
	fwrite(STDERR, "옵션 설명은 이 파일 상단 주석에 있다.\n");
	exit(2);
}

$csv_path = $argv_rest[0];

if ( ! is_file($csv_path))
{
	fwrite(STDERR, "파일이 없다: $csv_path\n");
	exit(2);
}

if ($opt['kind'] !== 'station' && $opt['kind'] !== 'district')
{
	fwrite(STDERR, "--kind 는 station 또는 district 만 된다.\n");
	exit(2);
}

$only = array_filter(array_map('trim', explode(',', (string) $opt['only'])), 'strlen');

// -------------------------------------------------------------
//  읽기 + 인코딩
// -------------------------------------------------------------
$raw = file_get_contents($csv_path);

if ($raw === FALSE)
{
	fwrite(STDERR, "읽을 수 없다: $csv_path\n");
	exit(2);
}

// BOM 제거
if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0)
{
	$raw = substr($raw, 3);
}

$enc = (string) $opt['encoding'];

if ($enc === '')
{
	// 공공데이터 CSV 는 CP949(EUC-KR 확장)가 많다. UTF-8 로 안 읽히면 CP949 로 본다.
	$enc = mb_check_encoding($raw, 'UTF-8') ? 'UTF-8' : 'CP949';
}

if (strtoupper($enc) !== 'UTF-8')
{
	$raw = mb_convert_encoding($raw, 'UTF-8', $enc);
}

$lines = preg_split("/\r\n|\n|\r/", $raw);
$rows  = array();

foreach ($lines as $ln)
{
	if (trim($ln) === '')
	{
		continue;
	}

	$rows[] = str_getcsv($ln);
}

if (count($rows) < 2)
{
	fwrite(STDERR, "데이터 행이 없다 (헤더만 있거나 빈 파일).\n");
	exit(2);
}

// -------------------------------------------------------------
//  헤더 매핑
// -------------------------------------------------------------
$header = array_shift($rows);
$norm   = array();

foreach ($header as $i => $h)
{
	$norm[$i] = strtolower(preg_replace('/[\s()\[\]_\-\.]/u', '', (string) $h));
}

$col = array();

foreach ($ALIAS as $field => $names)
{
	foreach ($norm as $i => $h)
	{
		if ($h === '')
		{
			continue;
		}

		if (in_array($h, $names, TRUE))
		{
			$col[$field] = $i;
			break;
		}
	}
}

// 정확일치로 못 찾으면 부분일치를 한 번 더 시도한다 ("역사명(한글)" 같은 헤더)
foreach ($ALIAS as $field => $names)
{
	if (isset($col[$field]))
	{
		continue;
	}

	foreach ($norm as $i => $h)
	{
		if ($h === '' || in_array($i, $col, TRUE))
		{
			continue;
		}

		foreach ($names as $n)
		{
			if (mb_strlen($n) >= 2 && mb_strpos($h, $n) !== FALSE)
			{
				$col[$field] = $i;
				break 2;
			}
		}
	}
}

$missing = array();

foreach (array('name', 'lat', 'lng') as $need)
{
	if ( ! isset($col[$need]))
	{
		$missing[] = $need;
	}
}

if ($missing)
{
	fwrite(STDERR, "필수 컬럼을 찾지 못했다: " . implode(', ', $missing) . "\n");
	fwrite(STDERR, "이 파일의 헤더: " . implode(' | ', $header) . "\n");
	fwrite(STDERR, "추측하지 않고 멈춘다. 이 파일 상단의 별칭 표에 헤더 이름을 추가하라.\n");
	exit(3);
}

// -------------------------------------------------------------
//  행 정리
// -------------------------------------------------------------
function ds_sido_of($addr, $map)
{
	$addr = trim((string) $addr);

	if ($addr === '')
	{
		return '';
	}

	$first = preg_split('/\s+/u', $addr)[0];

	return isset($map[$first]) ? $map[$first] : '';
}

function ds_sigungu_of($addr)
{
	$parts = preg_split('/\s+/u', trim((string) $addr));

	if (count($parts) < 2)
	{
		return '';
	}

	// "경기도 수원시 팔달구 ..." -> "수원시 팔달구", "서울특별시 강남구 ..." -> "강남구"
	$out = array();

	for ($i = 1; $i < min(4, count($parts)); $i++)
	{
		$p = $parts[$i];

		if (preg_match('/(시|군|구)$/u', $p))
		{
			$out[] = $p;
			continue;
		}

		break;
	}

	return implode(' ', $out);
}

function ds_q($s)
{
	return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $s) . "'";
}

$out        = array();
$seen       = array();
$skip_name  = 0;
$skip_sido  = 0;
$skip_dup   = 0;
$no_geo     = 0;
$bad_geo    = 0;
$suffix     = (string) $opt['name-suffix'];
$limit      = (int) $opt['limit'];

foreach ($rows as $r)
{
	$name = isset($r[$col['name']]) ? trim((string) $r[$col['name']]) : '';
	$name = preg_replace('/\s+/u', ' ', $name);

	if ($name === '')
	{
		$skip_name++;
		continue;
	}

	if ($suffix !== '' && mb_substr($name, -mb_strlen($suffix)) !== $suffix)
	{
		$name .= $suffix;
	}

	$addr = isset($col['addr'], $r[$col['addr']]) ? trim((string) $r[$col['addr']]) : '';

	if ((string) $opt['sido'] !== '')
	{
		$sido = (string) $opt['sido'];
	}
	elseif (isset($col['sido'], $r[$col['sido']]) && trim((string) $r[$col['sido']]) !== '')
	{
		$v    = trim((string) $r[$col['sido']]);
		$sido = isset($SIDO_MAP[$v]) ? $SIDO_MAP[$v] : $v;
	}
	else
	{
		$sido = ds_sido_of($addr, $SIDO_MAP);
	}

	if ($sido === '')
	{
		// 시/도를 모르면 지역 선택 UI 의 1단이 '기타' 로 뭉친다. 추측하지 않고 건너뛴다.
		$skip_sido++;
		continue;
	}

	if ($only && ! in_array($sido, $only, TRUE))
	{
		continue;
	}

	if (isset($col['sigungu'], $r[$col['sigungu']]) && trim((string) $r[$col['sigungu']]) !== '')
	{
		$sigungu = trim((string) $r[$col['sigungu']]);
	}
	else
	{
		$sigungu = ds_sigungu_of($addr);
	}

	$key = $name . '|' . $sido;

	if (isset($seen[$key]))
	{
		// 같은 역이 노선마다 한 행씩 들어 있는 자료가 많다. 첫 행만 쓰고 노선은 합친다.
		$i = $seen[$key];

		if (isset($col['line'], $r[$col['line']]))
		{
			$ln = trim((string) $r[$col['line']]);

			if ($ln !== '' && strpos($out[$i]['line'], $ln) === FALSE)
			{
				$out[$i]['line'] = ($out[$i]['line'] === '') ? $ln : $out[$i]['line'] . '·' . $ln;
			}
		}

		$skip_dup++;
		continue;
	}

	$lat_s = isset($r[$col['lat']]) ? trim((string) $r[$col['lat']]) : '';
	$lng_s = isset($r[$col['lng']]) ? trim((string) $r[$col['lng']]) : '';
	$lat   = 0.0;
	$lng   = 0.0;

	if (is_numeric($lat_s) && is_numeric($lng_s))
	{
		$la = (float) $lat_s;
		$lo = (float) $lng_s;

		// 한반도 범위 밖이면 좌표계가 WGS84 가 아니거나(TM 등) 열이 뒤바뀐 자료다.
		// 변환을 추측하지 않고 좌표 없음으로 둔다 — 틀린 좌표는 없는 좌표보다 나쁘다.
		if ($la >= 33.0 && $la <= 39.0 && $lo >= 124.0 && $lo <= 132.0)
		{
			$lat = $la;
			$lng = $lo;
		}
		else
		{
			$bad_geo++;
		}
	}

	if ($lat === 0.0)
	{
		$no_geo++;
	}

	$line = '';

	if (isset($col['line'], $r[$col['line']]))
	{
		$line = trim((string) $r[$col['line']]);
	}

	$seen[$key] = count($out);
	$out[]      = array(
		'name' => $name, 'sido' => $sido, 'sigungu' => $sigungu,
		'line' => $line, 'lat' => $lat, 'lng' => $lng,
	);

	if ($limit > 0 && count($out) >= $limit)
	{
		break;
	}
}

// -------------------------------------------------------------
//  출처 검사 — 좌표가 있으면 출처 URL 이 반드시 있어야 한다
// -------------------------------------------------------------
$with_geo = 0;

foreach ($out as $o)
{
	if ($o['lat'] !== 0.0)
	{
		$with_geo++;
	}
}

$source = trim((string) $opt['source']);

if ($with_geo > 0 && $source === '' && $opt['dry-run'] !== TRUE)
{
	fwrite(STDERR, "좌표가 있는 행이 {$with_geo}개인데 --source 가 비었다.\n");
	fwrite(STDERR, "좌표는 출처를 추적할 수 있어야 한다. 자료 페이지 URL 을 --source= 로 넘겨라.\n");
	exit(4);
}

// -------------------------------------------------------------
//  출력
// -------------------------------------------------------------
$stat = sprintf(
	"-- 입력 %s (인코딩 %s) · 데이터 %d행 -> 출력 %d곳 (좌표 있음 %d · 좌표 없음 %d)\n"
	. "-- 건너뜀: 이름없음 %d · 시도불명 %d · 같은역합침 %d · 좌표범위밖 %d\n",
	basename($csv_path), $enc, count($rows), count($out), $with_geo, $no_geo,
	$skip_name, $skip_sido, $skip_dup, $bad_geo
);

if ($opt['dry-run'] === TRUE)
{
	echo "인식한 컬럼:\n";

	foreach ($ALIAS as $f => $_)
	{
		echo sprintf("  %-8s %s\n", $f,
			isset($col[$f]) ? ('[' . $col[$f] . '] ' . $header[$col[$f]]) : '(없음)');
	}

	echo "\n" . $stat;

	foreach (array_slice($out, 0, 10) as $o)
	{
		echo sprintf("  %s | %s | %s | %s | %.7f, %.7f\n",
			$o['name'], $o['sido'], $o['sigungu'], $o['line'], $o['lat'], $o['lng']);
	}

	if (count($out) > 10)
	{
		echo '  ... 그리고 ' . (count($out) - 10) . "곳\n";
	}

	exit(0);
}

if ( ! $out)
{
	fwrite(STDERR, "출력할 행이 없다.\n" . $stat);
	exit(3);
}

$sort = (int) $opt['start-sort'];
$step = max(1, (int) $opt['sort-step']);

echo "-- ---------------- 직접 추가하는 지역 (자동 생성) ----------------\n";
echo $stat;
echo "-- 생성: tools/areas_from_csv.php · " . date('Y-m-d H:i') . "\n";
echo "-- sql/02_seed.sql 의 \"직접 추가하는 지역\" 블록에 붙여넣는다.\n";
echo "-- 좌표 0 인 행은 /admin/areas 의 \"네이버로 좌표 보정\" 이 채운다.\n";
echo "INSERT INTO `t_areas`\n";
echo "  (`name`,`sido`,`sigungu`,`kind`,`line_info`,`lat`,`lng`,`geo_verified`,`geo_source`,`sort_order`)\n";
echo "VALUES\n";

$parts = array();

foreach ($out as $o)
{
	$verified = ($o['lat'] !== 0.0) ? 1 : 0;
	$src      = $verified ? $source : '';

	$parts[] = sprintf("(%s,%s,%s,%s,%s,%s,%s,%d,%s,%d)",
		ds_q($o['name']), ds_q($o['sido']), ds_q($o['sigungu']),
		ds_q($opt['kind']), ds_q($o['line']),
		number_format($o['lat'], 7, '.', ''), number_format($o['lng'], 7, '.', ''),
		$verified, ds_q($src), $sort);

	$sort += $step;
}

echo implode(",\n", $parts) . "\n";
echo "ON DUPLICATE KEY UPDATE\n";
echo "  `sigungu` = VALUES(`sigungu`), `kind` = VALUES(`kind`),\n";
echo "  `line_info` = VALUES(`line_info`),\n";
echo "  `lat` = IF(VALUES(`geo_verified`) = 0 AND `geo_verified` = 1, `lat`, VALUES(`lat`)),\n";
echo "  `lng` = IF(VALUES(`geo_verified`) = 0 AND `geo_verified` = 1, `lng`, VALUES(`lng`)),\n";
echo "  `geo_source` = IF(VALUES(`geo_verified`) = 0 AND `geo_verified` = 1, `geo_source`, VALUES(`geo_source`)),\n";
echo "  `geo_verified` = GREATEST(`geo_verified`, VALUES(`geo_verified`)),\n";
echo "  `sort_order` = VALUES(`sort_order`);\n";
