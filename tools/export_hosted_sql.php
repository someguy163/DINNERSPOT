<?php
/**
 * 공유 호스팅용 SQL 추출기 (CLI 전용)
 *
 * 왜 필요한가:
 *   sql/01_schema.sql 은 `CREATE DATABASE dinnerspot` 과 `USE dinnerspot` 으로
 *   시작한다. 로컬 XAMPP 에서는 그게 편하지만 **공유 호스팅에서는 둘 다 막힌다**:
 *
 *     - DB 는 업체가 만들어 주고 이름도 업체가 정한다(예: epiz_1234_dinnerspot).
 *       CREATE DATABASE 권한이 없어 그 줄에서 오류가 난다.
 *     - phpMyAdmin 은 이미 그 DB 안에서 열리므로 USE 도 필요 없고,
 *       엉뚱한 이름을 USE 하면 역시 오류다.
 *
 *   그래서 그 두 종류의 문장만 떼어낸 파일을 만든다. 원본은 손대지 않는다 —
 *   정본은 sql/ 이고, 여기서 나오는 것은 **업로드용 사본**이다.
 *
 * 사용법:
 *   php tools/export_hosted_sql.php
 *   php tools/export_hosted_sql.php --out=C:/tmp/upload
 *
 * 만들어지는 것 (기본 dist/ 폴더, .gitignore 로 빠진다):
 *   dist/01_schema.hosted.sql
 *   dist/02_seed.hosted.sql
 *
 * phpMyAdmin 에서 내 DB 를 고른 뒤 [가져오기] 로 01 -> 02 순서로 올린다.
 */

if (PHP_SAPI !== 'cli')
{
	exit("CLI 로만 실행한다.\n");
}

$ROOT = dirname(__DIR__);
$out  = $ROOT . '/dist';

foreach (array_slice($argv, 1) as $a)
{
	if (preg_match('/^--out=(.+)$/', $a, $m))
	{
		$out = rtrim($m[1], '/\\');
		continue;
	}

	fwrite(STDERR, "알 수 없는 옵션: $a\n");
	exit(2);
}

if ( ! is_dir($out) && ! mkdir($out, 0777, TRUE))
{
	fwrite(STDERR, "폴더를 만들 수 없다: $out\n");
	exit(1);
}

$files = array(
	'sql/01_schema.sql' => '01_schema.hosted.sql',
	'sql/02_seed.sql'   => '02_seed.hosted.sql',
);

$total_removed = 0;

foreach ($files as $src => $dstName)
{
	$path = $ROOT . '/' . $src;

	if ( ! is_file($path))
	{
		fwrite(STDERR, "원본이 없다: $src\n");
		exit(1);
	}

	$sql = file_get_contents($path);

	if ($sql === FALSE)
	{
		fwrite(STDERR, "읽을 수 없다: $src\n");
		exit(1);
	}

	$lines   = preg_split("/\r\n|\n|\r/", $sql);
	$kept    = array();
	$removed = array();
	$skip_to_semicolon = FALSE;

	foreach ($lines as $line)
	{
		/* CREATE DATABASE 는 여러 줄에 걸쳐 있다(문자셋 지정이 다음 줄에 온다).
		 * 그래서 세미콜론이 나올 때까지 계속 버린다. */
		if ($skip_to_semicolon)
		{
			$removed[] = $line;

			if (strpos($line, ';') !== FALSE)
			{
				$skip_to_semicolon = FALSE;
			}

			continue;
		}

		$t = ltrim($line);

		if (preg_match('/^CREATE\s+DATABASE\b/i', $t))
		{
			$removed[] = $line;
			$skip_to_semicolon = (strpos($line, ';') === FALSE);
			continue;
		}

		if (preg_match('/^(USE|DROP\s+DATABASE)\b/i', $t))
		{
			$removed[] = $line;
			continue;
		}

		$kept[] = $line;
	}

	$head = "-- ---------------------------------------------------------------\n"
		. "-- $dstName  —  공유 호스팅 업로드용 (자동 생성 · 직접 고치지 마세요)\n"
		. "--\n"
		. "--   원본 : $src   ← 정본은 이쪽입니다\n"
		. "--   생성 : php tools/export_hosted_sql.php\n"
		. "--\n"
		. "--   원본에서 CREATE DATABASE · USE · DROP DATABASE 문을 떼어냈습니다.\n"
		. "--   공유 호스팅은 DB 를 업체가 만들어 주고 그 권한을 주지 않습니다.\n"
		. "--   phpMyAdmin 에서 **내 DB 를 먼저 고른 뒤** 가져오기 하세요.\n"
		. "-- ---------------------------------------------------------------\n\n";

	$dst = $out . '/' . $dstName;

	if (file_put_contents($dst, $head . implode("\n", $kept)) === FALSE)
	{
		fwrite(STDERR, "쓸 수 없다: $dst\n");
		exit(1);
	}

	printf("  %-18s -> %s  (%d줄 중 %d줄 제거)\n",
		basename($src), $dstName, count($lines), count($removed));

	foreach ($removed as $r)
	{
		if (trim($r) !== '')
		{
			echo "        빼냄: ", trim($r), "\n";
		}
	}

	$total_removed += count($removed);
}

echo "\n만든 곳: $out\n";

if ($total_removed === 0)
{
	echo "\n※ 제거한 문장이 없습니다. 원본에 CREATE DATABASE/USE 가 없다면\n";
	echo "   이미 호스팅용이거나 파일 형식이 바뀐 것입니다 — 확인하세요.\n";
}

echo "\n다음 순서로 올립니다:\n";
echo "  1) 호스팅 패널에서 MySQL DB 를 만들고 이름 · 계정 · 비밀번호를 받는다\n";
echo "  2) phpMyAdmin 에서 **그 DB 를 클릭**해 선택한다\n";
echo "  3) [가져오기] 로 01_schema.hosted.sql -> 02_seed.hosted.sql 순서로 올린다\n";
echo "  4) application/config/dinnerspot_local.php 에 db_* 값을 적는다\n";
echo "     (자세한 순서는 README 의 \"무료 호스팅에 올리기\" 절)\n\n";
