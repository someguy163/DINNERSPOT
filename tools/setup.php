<?php
/**
 * DINNERSPOT 설치 점검 · 실행기 (CLI 전용)
 *
 * 왜 이 파일이 있는가:
 *   git 에는 실제 키가 담긴 application/config/dinnerspot_local.php 가 올라가지
 *   않는다(.gitignore). 그래서 저장소를 새로 받으면 그 파일이 없고, DB 도 비어
 *   있다. 무엇이 빠졌는지 사람이 하나씩 확인하는 대신 이 스크립트가 점검하고,
 *   시킬 때만 고친다.
 *
 * 사용법:
 *   php tools/setup.php                점검만 한다. 아무것도 바꾸지 않는다
 *   php tools/setup.php --install      빠진 것을 만든다 (DB 생성 + 스키마 + 시드
 *                                      + local 설정 파일 복사)
 *   php tools/setup.php --seed         시드만 다시 넣는다 (기준 지역 · 샘플 장소)
 *
 * 옵션:
 *   --db-user=root      MySQL 계정 (기본: database.php 의 값)
 *   --db-pass=비번      MySQL 비밀번호 (기본: database.php 의 값)
 *   --db-host=localhost MySQL 호스트 (기본: database.php 의 값)
 *   --mysql=경로        mysql 실행파일 경로를 직접 지정
 *   --url=주소          mod_rewrite 확인에 쓸 주소
 *                       (기본: http://localhost/<이 폴더 이름>/)
 *   --force             ★ 위험. 테이블이 이미 있어도 01_schema.sql 을 돌린다.
 *                       DROP TABLE 로 시작하므로 투표 기록과 수집한 장소가
 *                       전부 사라진다. 이 옵션이 없으면 거부한다.
 *
 * 이 스크립트가 절대 하지 않는 일:
 *   - 키를 만들거나 어디서 가져오지 않는다 (사람이 발급해 넣어야 한다)
 *   - --force 없이 기존 테이블을 지우지 않는다
 *   - 네이버 API 를 호출하지 않는다
 */

if (PHP_SAPI !== 'cli')
{
	exit("CLI 로만 실행한다.\n");
}

$ROOT = dirname(__DIR__);

/* PHP 8 부터 mysqli 는 오류를 예외로 던진다. 이 스크립트는 "없으면 만든다" 를
 * 반환값으로 판단하므로 예외를 끄고 FALSE 를 받는다. */
if (function_exists('mysqli_report'))
{
	mysqli_report(MYSQLI_REPORT_OFF);
}

/* ------------------------------------------------------------------ 인자 */

$opt  = array('force' => FALSE, 'install' => FALSE, 'seed' => FALSE);
$argv_rest = array_slice($argv, 1);

foreach ($argv_rest as $a)
{
	if ($a === '--force')        { $opt['force']   = TRUE; continue; }
	if ($a === '--install')      { $opt['install'] = TRUE; continue; }
	if ($a === '--seed')         { $opt['seed']    = TRUE; continue; }
	if ($a === '-h' OR $a === '--help')
	{
		fwrite(STDERR, "사용법은 이 파일 상단 주석에 있다: tools/setup.php\n");
		exit(2);
	}

	if (preg_match('/^--(db-user|db-pass|db-host|mysql|url)=(.*)$/', $a, $m))
	{
		$opt[$m[1]] = $m[2];
		continue;
	}

	fwrite(STDERR, "알 수 없는 옵션: $a\n");
	exit(2);
}

/* ------------------------------------------------------------- 출력 도구 */

$FAIL  = 0;   // 반드시 고쳐야 하는 것
$WARN  = 0;   // 없어도 동작하지만 알아둘 것
$FIXED = 0;   // 이번 실행에서 실제로 고친 것 (점검 단계에서 센 FAIL 을 상쇄한다)

function line($mark, $label, $detail = '')
{
	/* printf 의 %-34s 는 바이트를 센다. 한글은 UTF-8 로 3바이트여서 그대로 쓰면
	 * 열이 밀린다. 화면 폭(한글 2칸)으로 직접 채운다. */
	$w = 0;

	foreach (preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY) as $ch)
	{
		$w += preg_match('/[\x{1100}-\x{115F}\x{2E80}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}\x{FF00}-\x{FF60}]/u', $ch) ? 2 : 1;
	}

	echo '  ', $mark, '  ', $label, str_repeat(' ', max(1, 34 - $w)), ' ', $detail, "\n";
}
function ok($label, $detail = '')   { line('OK  ', $label, $detail); }
function bad($label, $detail = '')  { global $FAIL; $FAIL++; line('안됨', $label, $detail); }
function warn($label, $detail = '') { global $WARN; $WARN++; line('주의', $label, $detail); }
function head($t)                   { echo "\n", $t, "\n", str_repeat('-', 72), "\n"; }
function todo($t)                   { global $TODO; $TODO[] = $t; }

$TODO = array();

echo "\nDINNERSPOT 설치 점검\n";
echo "폴더: $ROOT\n";
echo "모드: ", ($opt['install'] ? '--install (빠진 것을 만든다)'
	: ($opt['seed'] ? '--seed (시드만 다시 넣는다)' : '점검만 (아무것도 바꾸지 않는다)')), "\n";

/* =================================================================== 1 PHP */

head('1) PHP');

if (version_compare(PHP_VERSION, '7.2', '>='))
{
	ok('PHP 버전', PHP_VERSION);
}
else
{
	bad('PHP 버전', PHP_VERSION . ' — CodeIgniter 3 은 7.2 이상을 권한다');
	todo('PHP 를 7.2 이상으로 올린다 (XAMPP 8.2 권장)');
}

/* 확장은 README "필수 PHP 확장" 과 같은 목록이어야 한다 */
$need = array(
	'mysqli'   => 'DB 접속',
	'curl'     => '네이버 API 호출',
	'mbstring' => '한글 문자열 처리',
	'json'     => 'API 응답 파싱',
	'openssl'  => 'HTTPS 호출',
);

foreach ($need as $ext => $why)
{
	if (extension_loaded($ext))
	{
		ok("확장 $ext", $why);
	}
	else
	{
		bad("확장 $ext", "$why — php.ini 에서 extension=$ext 주석을 푼다");
		todo("php.ini 에서 extension=$ext 를 켜고 Apache 를 재시작한다");
	}
}

/* ============================================================ 2 쓰기 권한 */

head('2) 쓰기 권한  (세션 · 로그 · 로그인 시도 기록)');

foreach (array('application/cache', 'application/logs') as $rel)
{
	$dir = $ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

	if ( ! is_dir($dir))
	{
		bad($rel, '폴더가 없다');
		todo("$rel 폴더를 만든다");
		continue;
	}

	is_writable($dir)
		? ok($rel, '쓰기 가능')
		: bad($rel, '쓰기 불가 — 세션과 로그가 기록되지 않는다');
}

/* 세션 폴더는 CI 가 알아서 만든다(Session_files_driver). 없어도 문제 아니다. */
$sess = $ROOT . '/application/cache/sessions';
is_dir($sess)
	? ok('application/cache/sessions', count(glob($sess . '/*') ?: array()) . '개 파일')
	: ok('application/cache/sessions', '없음 — 첫 요청에서 CI 가 만든다');

/* ========================================================= 3 설정 파일 */

head('3) 설정 파일');

$local   = $ROOT . '/application/config/dinnerspot_local.php';
$example = $local . '.example';

if ( ! is_file($example))
{
	bad('dinnerspot_local.php.example', '템플릿이 없다 — 저장소가 온전하지 않다');
}
elseif (is_file($local))
{
	ok('dinnerspot_local.php', '있음');
}
elseif ($opt['install'])
{
	copy($example, $local)
		? ok('dinnerspot_local.php', '.example 에서 복사했다 — 값은 비어 있다')
		: bad('dinnerspot_local.php', '복사 실패 (권한 확인)');
	todo('application/config/dinnerspot_local.php 에 키를 넣는다 (파일 주석에 발급처가 있다)');
}
else
{
	warn('dinnerspot_local.php', '없음 — 키 없이 샘플 43곳으로 동작한다');
	todo('cp application/config/dinnerspot_local.php.example application/config/dinnerspot_local.php');
}

/* 키가 실제로 채워졌는지. 값은 절대 출력하지 않는다. */
$keys = array('naver_client_id' => '', 'naver_client_secret' => '', 'naver_map_key_id' => '', 'admin_password' => '');

if (is_file($local))
{
	$src = file_get_contents($local);

	foreach ($keys as $k => $_)
	{
		if (preg_match_all("/\\\$config\\['" . $k . "'\\]\\s*=\\s*'([^']*)'/", $src, $m))
		{
			$keys[$k] = trim(end($m[1]));
		}
	}
}

$has_search = ($keys['naver_client_id'] !== '' && $keys['naver_client_secret'] !== '');

$has_search
	? ok('네이버 지역검색 키', '설정됨 (' . strlen($keys['naver_client_id']) . '자 / '
		. strlen($keys['naver_client_secret']) . '자)')
	: warn('네이버 지역검색 키', '비어 있음 — 실제 상권 대신 샘플 43곳만 나온다');

if ( ! $has_search)
{
	todo('지역검색 키를 발급해 넣는다 → 넣은 뒤 확인:  <주소>/guide');
}

$keys['naver_map_key_id'] !== ''
	? ok('네이버 지도 Key ID', '설정됨')
	: warn('네이버 지도 Key ID', '비어 있음 — 상세 화면 지도와 "지도에서 기준점 조정" 이 빠진다');

$keys['admin_password'] !== ''
	? ok('관리자 비밀번호', '설정됨')
	: warn('관리자 비밀번호', '비어 있음 — /admin 이 404 (의도된 동작)');

/* ================================================================= 4 DB */

head('4) 데이터베이스');

/* database.php 의 값을 기본값으로 쓴다. include 하면 BASEPATH 가 필요하므로 읽어서 뽑는다. */
$dbsrc = @file_get_contents($ROOT . '/application/config/database.php');
$dbcfg = array('hostname' => 'localhost', 'username' => 'root', 'password' => '', 'database' => 'dinnerspot');

foreach ($dbcfg as $k => $def)
{
	if ($dbsrc !== FALSE && preg_match("/'" . $k . "'\\s*=>\\s*'([^']*)'/", $dbsrc, $m))
	{
		$dbcfg[$k] = $m[1];
	}
}

/* ★ 여기가 중요하다.
 * sql/01_schema.sql 과 02_seed.sql 은 파일 안에 `USE `dinnerspot`` 이 박혀 있다.
 * 그래서 mysql 에 DB 를 지정하지 않고 파일만 먹이면 **파일이 정한 DB** 에 들어간다.
 * database.php 의 DB 이름이 그와 다르면, 점검은 A 를 보면서 실행은 B 를 건드리는
 * 사고가 난다 (실제로 그렇게 다른 DB 의 테이블을 DROP 한 적이 있다).
 * 그러니 두 이름이 다르면 실행하지 않고 사람에게 알린다. */
$sql_db = NULL;
$schema_src = @file_get_contents($ROOT . '/sql/01_schema.sql');

if ($schema_src !== FALSE && preg_match('/^\s*USE\s+`?([A-Za-z0-9_]+)`?/mi', $schema_src, $m))
{
	$sql_db = $m[1];
}

$host = isset($opt['db-host']) ? $opt['db-host'] : $dbcfg['hostname'];
$user = isset($opt['db-user']) ? $opt['db-user'] : $dbcfg['username'];
$pass = isset($opt['db-pass']) ? $opt['db-pass'] : $dbcfg['password'];
$name = $dbcfg['database'];

echo "  (database.php: $user@$host / DB '$name')\n";

if ($sql_db !== NULL && $sql_db !== $name)
{
	bad('DB 이름 불일치', "database.php='$name' vs sql/*.sql='$sql_db'");
	echo "\n";
	echo "  sql/01_schema.sql 과 02_seed.sql 안에 USE `$sql_db` 가 박혀 있다.\n";
	echo "  이 상태로 --install 을 돌리면 '$name' 이 아니라 '$sql_db' 의 테이블을\n";
	echo "  DROP 하고 다시 만든다. 데이터가 사라질 수 있으므로 실행하지 않는다.\n\n";
	echo "  둘 중 하나로 맞춘다:\n";
	echo "    - application/config/database.php 의 'database' 를 '$sql_db' 로 바꾼다 (쉬움)\n";
	echo "    - 또는 sql/*.sql 의 CREATE DATABASE / USE 를 '$name' 으로 바꾼다\n";
	todo("DB 이름을 '$sql_db' 와 '$name' 중 하나로 통일한다");
	$opt['install'] = FALSE;
	$opt['seed']    = FALSE;
}

$conn = @new mysqli($host, $user, $pass);

if ($conn->connect_error)
{
	bad('MySQL 접속', $conn->connect_error);
	todo('XAMPP 컨트롤패널에서 MySQL 을 시작한다. 계정이 다르면 application/config/database.php 를 고친다');
	$conn = NULL;
}
else
{
	ok('MySQL 접속', $conn->server_info);
	$conn->set_charset('utf8mb4');

	$r  = $conn->query("SHOW DATABASES LIKE '" . $conn->real_escape_string($name) . "'");
	$db_exists = ($r && $r->num_rows > 0);

	if ($db_exists)
	{
		ok("DB '$name'", '있음');
	}
	elseif ($opt['install'])
	{
		$conn->query("CREATE DATABASE `" . str_replace('`', '', $name)
			. "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
			? ok("DB '$name'", '만들었다')
			: bad("DB '$name'", $conn->error);
		$db_exists = TRUE;
	}
	else
	{
		bad("DB '$name'", '없음');
		todo('php tools/setup.php --install  (또는 README 1. 설치의 mysql 명령)');
	}

	/* 테이블 */
	$tables = array();

	if ($db_exists && $conn->select_db($name))
	{
		if ($t = $conn->query('SHOW TABLES'))
		{
			while ($row = $t->fetch_array()) { $tables[] = $row[0]; }
		}
	}

	/* 기대 목록을 여기 적어두면 스키마가 바뀔 때 같이 안 고쳐져 거짓 경고가 난다.
	 * 그래서 01_schema.sql 에서 직접 읽는다. */
	$expect = array();
	$schema = @file_get_contents($ROOT . '/sql/01_schema.sql');

	if ($schema !== FALSE && preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $schema, $m))
	{
		$expect = array_unique($m[1]);
	}

	$missing = array_diff($expect, $tables);

	if ($db_exists && ! $missing)
	{
		ok('테이블', count($tables) . '개 — 필요한 9개가 모두 있다');
	}
	elseif ($db_exists)
	{
		count($tables) === 0
			? bad('테이블', '없음 — 스키마를 넣어야 한다')
			: bad('테이블', '부족: ' . implode(', ', $missing));
	}

	/* 행 수 */
	if ($db_exists && ! $missing)
	{
		foreach (array('t_areas' => '기준 지역', 't_places' => '장소') as $tb => $ko)
		{
			$q = $conn->query("SELECT COUNT(*) c FROM `$tb`");
			$c = $q ? (int) $q->fetch_assoc()['c'] : 0;

			if ($c > 0)
			{
				ok("$tb ($ko)", number_format($c) . '행');
			}
			else
			{
				warn("$tb ($ko)", '0행 — 시드를 넣어야 한다');
				todo('php tools/setup.php --seed');
			}
		}
	}
}

/* ------------------------------------------------ 스키마 · 시드 실행 */

if ($conn && ($opt['install'] OR $opt['seed']))
{
	head('4-1) SQL 실행');

	/* mysql 실행파일 찾기. XAMPP 기본 경로를 먼저 본다. */
	$mysql = isset($opt['mysql']) ? $opt['mysql'] : NULL;

	if ($mysql === NULL)
	{
		foreach (array('C:/xampp/mysql/bin/mysql.exe', '/usr/bin/mysql', '/usr/local/bin/mysql') as $cand)
		{
			if (is_file($cand)) { $mysql = $cand; break; }
		}
	}

	if ($mysql === NULL OR ! is_file($mysql))
	{
		bad('mysql 실행파일', '찾지 못했다 — --mysql=경로 로 지정한다');
		todo('mysql 클라이언트 경로를 --mysql= 로 알려주고 다시 실행한다');
	}
	else
	{
		ok('mysql 실행파일', $mysql);

		$auth = ' -h' . escapeshellarg($host) . ' -u' . escapeshellarg($user)
			. ($pass !== '' ? ' -p' . escapeshellarg($pass) : '')
			. ' --default-character-set=utf8mb4';

		/* 스키마: 테이블이 이미 있으면 --force 없이는 절대 돌리지 않는다.
		 * 01_schema.sql 은 DROP TABLE 로 시작한다. */
		$run_schema = $opt['install'] && ( ! empty($missing) OR $opt['force']);

		if ($opt['install'] && empty($missing) && ! $opt['force'])
		{
			warn('01_schema.sql', '테이블이 이미 있어 건너뛴다 (덮어쓰려면 --force — 데이터가 전부 사라진다)');
		}

		if ($run_schema)
		{
			if ($opt['force'] && empty($missing))
			{
				echo "\n  ★ --force: 기존 테이블을 DROP 한다. 투표 기록과 수집한 장소가 사라진다.\n";
				echo "     계속하려면 5초 안에 Ctrl+C 로 중단하지 않으면 진행한다...\n";
				sleep(5);
			}

			$cmd = escapeshellarg($mysql) . $auth . ' < ' . escapeshellarg($ROOT . '/sql/01_schema.sql');
			exec($cmd . ' 2>&1', $out, $rc);

			/* 종료코드만 믿지 않는다. 실제로 그 DB 에 테이블이 생겼는지 다시 센다.
			 * 예전에 "적용했다" 라고 하면서 엉뚱한 DB 에 들어간 적이 있다. */
			$after = array();

			if ($conn->select_db($name) && ($t2 = $conn->query('SHOW TABLES')))
			{
				while ($row = $t2->fetch_array()) { $after[] = $row[0]; }
			}

			$still = array_diff($expect, $after);

			if ($rc === 0 && ! $still)
			{
				ok('01_schema.sql', "적용했다 — '$name' 에 테이블 " . count($after) . '개');
				$missing = array();
				$FIXED++;   // 위에서 "테이블 없음" 으로 센 실패를 상쇄한다
			}
			else
			{
				bad('01_schema.sql', $still
					? ("'$name' 에 테이블이 안 생겼다: " . implode(', ', array_slice($still, 0, 4)))
					: trim(implode(' ', array_slice($out, 0, 3))));
			}
		}

		/* 시드는 멱등이다 (INSERT ... ON DUPLICATE KEY UPDATE) */
		$cmd = escapeshellarg($mysql) . $auth . ' ' . escapeshellarg($name)
			. ' < ' . escapeshellarg($ROOT . '/sql/02_seed.sql');
		exec($cmd . ' 2>&1', $out2, $rc2);

		if ($rc2 === 0)
		{
			$conn->select_db($name);
			$a = $conn->query('SELECT COUNT(*) c FROM t_areas');
			$p = $conn->query('SELECT COUNT(*) c FROM t_places');
			ok('02_seed.sql', '적용했다 — 지역 ' . ($a ? (int) $a->fetch_assoc()['c'] : '?')
				. '곳 / 장소 ' . ($p ? (int) $p->fetch_assoc()['c'] : '?') . '곳');
		}
		else
		{
			bad('02_seed.sql', trim(implode(' ', array_slice($out2, 0, 3))));
		}
	}
}

/* ========================================================= 5 웹 서버 */

head('5) 웹 서버 (Apache · mod_rewrite)');

$folder = basename($ROOT);
$url    = isset($opt['url']) ? rtrim($opt['url'], '/') . '/' : "http://localhost/$folder/";

echo "  (확인 주소: $url)\n";

if ( ! function_exists('curl_init'))
{
	warn('접속 확인', 'curl 확장이 없어 건너뛴다');
}
else
{
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_TIMEOUT => 10,
	                             CURLOPT_FOLLOWLOCATION => TRUE));
	$body = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err  = curl_error($ch);
	curl_close($ch);

	if ($code === 200)
	{
		ok('홈 화면', "HTTP 200 ($url)");

		/* base_url 이 이 폴더를 따라왔는지. 여기가 틀리면 CSS 가 깨진다. */
		strpos((string) $body, $url . 'assets/') !== FALSE
			? ok('base_url', '이 폴더를 따라간다 — 폴더 이름을 바꿔도 된다')
			: warn('base_url', '렌더된 자원 경로가 예상과 다르다. application/config/config.php 를 확인한다');

		/* 라우팅(mod_rewrite) 확인 — index.php 없이 열리는지 */
		$ch2 = curl_init($url . 'guide');
		curl_setopt_array($ch2, array(CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_TIMEOUT => 10,
		                              CURLOPT_FOLLOWLOCATION => TRUE));
		curl_exec($ch2);
		$c2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
		curl_close($ch2);

		$c2 === 200
			? ok('mod_rewrite', 'index.php 없이 /guide 가 열린다')
			: bad('mod_rewrite', "/guide 가 HTTP $c2 — AllowOverride All 과 mod_rewrite 를 확인한다");

		if ($c2 !== 200)
		{
			todo('httpd.conf 에서 mod_rewrite 를 켜고 htdocs 에 AllowOverride All 을 준다');
		}
	}
	elseif ($code === 0)
	{
		warn('홈 화면', "접속 불가 ($err) — Apache 가 꺼져 있거나 주소가 다르다");
		todo("XAMPP 에서 Apache 를 시작한다. 포트가 다르면 --url=http://localhost:8080/$folder/");
	}
	else
	{
		bad('홈 화면', "HTTP $code");
		todo('브라우저로 ' . $url . ' 를 열어 오류 메시지를 확인한다 (application/logs 도 본다)');
	}
}

/* mod_deflate 는 성능 문제일 뿐이라 주의로만 남긴다 */
warn('mod_deflate (선택)', '켜면 홈 HTML 127KB 가 크게 줄어든다 — .htaccess 주석 참고');

/* ============================================================== 마무리 */

head('결과');

$left = max(0, $FAIL - $FIXED);

printf("  고쳐야 할 것 %d개 · 알아둘 것 %d개%s\n", $left, $WARN,
	$FIXED > 0 ? " (이번 실행에서 {$FIXED}개 고쳤다)" : '');

if ($TODO)
{
	echo "\n  다음으로 할 일\n";

	foreach (array_values(array_unique($TODO)) as $i => $t)
	{
		echo '   ', $i + 1, ') ', $t, "\n";
	}
}

if ($left === 0)
{
	echo "\n  실행 준비가 끝났다:  $url\n";

	if ( ! $has_search)
	{
		echo "  (지역검색 키가 없어 샘플 43곳으로 동작한다. 추천 · 투표는 전부 된다.)\n";
	}
}

echo "\n";
exit($left > 0 ? 1 : 0);
