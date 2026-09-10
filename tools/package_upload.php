<?php
/**
 * 업로드용 폴더 만들기 (CLI 전용)
 *
 * 왜 필요한가:
 *   FTP 로 올릴 때 "어떤 파일을 올려야 하는지" 가 가장 헷갈린다. 저장소에는
 *   서버에서 쓰지 않는 것들(개발 도구 · SQL 원본 · 문서 · git 설정)이 섞여 있다.
 *   올려도 동작에는 문제가 없지만, 그만큼 업로드가 느려지고 무엇이 필요한
 *   파일인지 알 수 없게 된다.
 *
 *   이 도구는 **서버가 실제로 쓰는 것만** dist/upload/ 에 복사한다.
 *   그 폴더 안의 내용을 호스팅의 공개 폴더(htdocs · public_html)에 그대로 넣으면 된다.
 *
 * 사용법:
 *   php tools/package_upload.php
 *   php tools/package_upload.php --with-secret   비밀 설정 파일까지 포함
 *   php tools/package_upload.php --out=C:/tmp/up
 *
 * 기본적으로 dinnerspot_local.php(키 · DB 비밀번호)는 **넣지 않는다.**
 * 실수로 그 폴더를 공개하거나 압축해서 남에게 보내는 사고를 막기 위해서다.
 * FTP 로 그 파일만 따로 올리는 편이 안전하다. 한 번에 하려면 --with-secret.
 */

if (PHP_SAPI !== 'cli')
{
	exit("CLI 로만 실행한다.\n");
}

$ROOT = dirname(__DIR__);
$out  = $ROOT . '/dist/upload';
$with_secret = FALSE;

foreach (array_slice($argv, 1) as $a)
{
	if ($a === '--with-secret') { $with_secret = TRUE; continue; }

	if (preg_match('/^--out=(.+)$/', $a, $m))
	{
		$out = rtrim($m[1], '/\\');
		continue;
	}

	fwrite(STDERR, "알 수 없는 옵션: $a\n");
	exit(2);
}

/* 서버가 실제로 쓰는 것 */
$include = array(
	'index.php'    => '프론트 컨트롤러 — 모든 요청이 여기로 들어온다',
	'.htaccess'    => 'index.php 숨기기 · 정적파일 예외 · sql/log 차단',
	'application/' => '이 프로젝트 코드 (컨트롤러 · 뷰 · 설정)',
	'system/'      => 'CodeIgniter 3.1.13 본체',
	'assets/'      => 'CSS · JS · 이미지',
);

/* 서버에서 쓰지 않는 것 — 왜 빼는지 함께 남긴다 */
$exclude_note = array(
	'sql/'        => 'DB 는 phpMyAdmin 으로 넣는다 (dist/*.hosted.sql)',
	'tools/'      => '내 PC 에서 돌리는 CLI 도구',
	'README.md'   => '문서',
	'.claude/'    => '에이전트 설정',
	'composer.json / license.txt / readme.rst / .editorconfig / .gitignore' => '개발용',
);

/* 절대 올리면 안 되는 것 */
$deny = array(
	'application/config/dinnerspot_local.php'         => '실제 키 · DB 비밀번호',
	'application/logs/*.php'                          => '내 PC 의 로그',
	'application/cache/sessions/*'                     => '내 PC 의 세션',
	'application/cache/ds_admin_login.json'            => '로그인 시도 기록',
);

function rmtree($dir)
{
	if ( ! is_dir($dir)) { return; }

	foreach (scandir($dir) as $f)
	{
		if ($f === '.' OR $f === '..') { continue; }

		$p = $dir . '/' . $f;
		is_dir($p) ? rmtree($p) : @unlink($p);
	}

	@rmdir($dir);
}

/** 복사하면서 제외 규칙을 적용한다. 돌려주는 값은 array(파일수, 바이트) */
function copy_tree($src, $dst, $skip)
{
	$files = 0;
	$bytes = 0;

	if ( ! is_dir($dst) && ! mkdir($dst, 0777, TRUE))
	{
		fwrite(STDERR, "폴더를 만들 수 없다: $dst\n");
		exit(1);
	}

	foreach (scandir($src) as $f)
	{
		if ($f === '.' OR $f === '..') { continue; }

		$sp = $src . '/' . $f;
		$dp = $dst . '/' . $f;

		if ($skip($sp, $f))
		{
			continue;
		}

		if (is_dir($sp))
		{
			list($n, $b) = copy_tree($sp, $dp, $skip);
			$files += $n;
			$bytes += $b;
			continue;
		}

		if ( ! copy($sp, $dp))
		{
			fwrite(STDERR, "복사 실패: $sp\n");
			exit(1);
		}

		$files++;
		$bytes += (int) filesize($sp);
	}

	return array($files, $bytes);
}

echo "\n업로드용 폴더 만들기\n";
echo str_repeat('-', 68), "\n";

rmtree($out);

if ( ! mkdir($out, 0777, TRUE))
{
	fwrite(STDERR, "폴더를 만들 수 없다: $out\n");
	exit(1);
}

$total_files = 0;
$total_bytes = 0;

$skip = function ($path, $name) use ($with_secret) {
	$p = str_replace('\\', '/', $path);

	// 비밀 설정 파일
	if (substr($p, -strlen('config/dinnerspot_local.php')) === 'config/dinnerspot_local.php')
	{
		return ! $with_secret;
	}

	// 내 PC 에서 생긴 것들. 서버에서 새로 만들어진다.
	if (strpos($p, '/application/cache/sessions') !== FALSE) { return TRUE; }
	if (strpos($p, '/application/cache/') !== FALSE && substr($name, -5) === '.json') { return TRUE; }
	if (strpos($p, '/application/logs/') !== FALSE && substr($name, -4) === '.php'
		&& $name !== 'index.php') { return TRUE; }

	if ($name === '.DS_Store' OR substr($name, -4) === '.bak') { return TRUE; }

	return FALSE;
};

foreach ($include as $item => $why)
{
	$src = $ROOT . '/' . rtrim($item, '/');

	if ( ! file_exists($src))
	{
		fwrite(STDERR, "없다: $item\n");
		exit(1);
	}

	if (is_dir($src))
	{
		list($n, $b) = copy_tree($src, $out . '/' . rtrim($item, '/'), $skip);
	}
	else
	{
		copy($src, $out . '/' . $item);
		$n = 1;
		$b = (int) filesize($src);
	}

	$total_files += $n;
	$total_bytes += $b;

	printf("  담음  %-14s %5d개  %6.1fMB   %s\n", $item, $n, $b / 1048576, $why);
}

/* 세션 폴더는 비어 있어도 있어야 편하다. CI 가 없으면 만들지만,
   호스팅에 따라 상위 폴더 권한 때문에 실패하는 경우가 있다. */
$sessdir = $out . '/application/cache/sessions';

if ( ! is_dir($sessdir)) { @mkdir($sessdir, 0777, TRUE); }

echo "\n";

foreach ($exclude_note as $item => $why)
{
	printf("  제외  %-14s %s\n", $item, $why);
}

echo "\n  올리면 안 되는 것 (이 폴더에 없는지 확인했다)\n";

foreach ($deny as $item => $why)
{
	printf("        %-42s %s\n", $item, $why);
}

/* 실제로 비밀 파일이 섞여 들어갔는지 확인한다 — 말로만 하지 않는다 */
$leak = $out . '/application/config/dinnerspot_local.php';

if (is_file($leak))
{
	if ($with_secret)
	{
		echo "\n  ★ --with-secret 이므로 dinnerspot_local.php 가 **포함**되어 있다.\n";
		echo "     이 폴더를 압축해 남에게 보내거나 공개 저장소에 올리지 마라.\n";
	}
	else
	{
		fwrite(STDERR, "\n★ 빠졌어야 할 비밀 파일이 들어 있다. 중단한다.\n");
		exit(1);
	}
}
else
{
	echo "\n  확인: dinnerspot_local.php 없음 — FTP 로 그 파일만 따로 올려라.\n";
}

printf("\n  합계 %d개 파일 · %.1fMB\n", $total_files, $total_bytes / 1048576);
echo "  만든 곳: ", str_replace('\\', '/', $out), "\n";

echo "\n다음 순서\n";
echo str_repeat('-', 68), "\n";
echo "  1) php tools/export_hosted_sql.php   ← phpMyAdmin 에 올릴 SQL 만들기\n";
echo "  2) 호스팅 패널에서 MySQL DB 생성 (이름 · 계정 · 비번 · 호스트 적어두기)\n";
echo "  3) phpMyAdmin 에서 그 DB 를 클릭 -> 가져오기 -> 01 그다음 02\n";
echo "  4) dinnerspot_local.php 에 키와 db_* 를 채우기\n";
echo "  5) 이 폴더 **안의 내용**을 공개 폴더(htdocs · public_html)에 업로드\n";
echo "     (이 폴더 자체를 올리면 주소에 /upload/ 가 붙는다)\n";
echo "  6) dinnerspot_local.php 를 application/config/ 에 따로 업로드\n";
echo "  7) 브라우저로 접속 -> /guide 에서 네이버 연결 확인\n";
echo "\n  자세한 설명은 README 의 \"10. 무료 호스팅에 올리기\"\n\n";
