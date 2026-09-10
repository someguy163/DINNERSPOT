<?php
/**
 * 관리자 비밀번호 해시 생성기 (CLI 전용)
 *
 * 왜 필요한가:
 *   admin_password 를 평문으로 두면 설정 파일이 읽히는 순간 그대로 노출된다.
 *   로컬 개발에서는 평문도 받지만(Admin::password_matches), **공개 서버에
 *   올릴 때는 해시를 써야 한다.**
 *
 * 사용법:
 *   php tools/admin_hash.php '원하는비밀번호'
 *
 *   출력된 한 줄을 application/config/dinnerspot_local.php 에 붙여넣는다.
 *   그 파일은 .gitignore 로 빠지므로 git 에 올라가지 않는다.
 *
 * 주의:
 *   비밀번호를 명령줄 인자로 주면 셸 히스토리에 남는다. 인자를 생략하면
 *   표준입력으로 받으므로, 신경 쓰인다면 이렇게 쓴다:
 *       php tools/admin_hash.php
 *   (한 줄 입력하고 Enter)
 */

if (PHP_SAPI !== 'cli')
{
	exit("CLI 로만 실행한다.\n");
}

$pw = isset($argv[1]) ? $argv[1] : NULL;

if ($pw === NULL)
{
	fwrite(STDERR, "비밀번호를 입력하고 Enter (화면에 보입니다): ");
	$pw = fgets(STDIN);
	$pw = ($pw === FALSE) ? '' : rtrim($pw, "\r\n");
}

if ($pw === '')
{
	fwrite(STDERR, "빈 비밀번호는 쓸 수 없다 — 빈 값이면 /admin 이 아예 꺼진다.\n");
	exit(2);
}

if (strlen($pw) < 8)
{
	fwrite(STDERR, "경고: " . strlen($pw) . "자다. 공개 서버에 올릴 거면 12자 이상을 권한다.\n\n");
}

$hash = password_hash($pw, PASSWORD_DEFAULT);

if ($hash === FALSE OR $hash === NULL)
{
	fwrite(STDERR, "해시 생성에 실패했다.\n");
	exit(1);
}

// 만든 해시가 실제로 검증되는지 확인하고 출력한다.
// (알고리즘 · php.ini 설정 문제로 조용히 어긋나는 일을 막는다)
if ( ! password_verify($pw, $hash))
{
	fwrite(STDERR, "만든 해시가 검증되지 않는다 — PHP 설정을 확인하라.\n");
	exit(1);
}

echo "\n아래 한 줄을 application/config/dinnerspot_local.php 에 넣으세요.\n";
echo "(이미 admin_password 줄이 있으면 그 줄을 지우고 이걸 쓰세요)\n\n";
echo "\$config['admin_password'] = '", $hash, "';\n\n";
echo "검증 완료 · 알고리즘 ", password_get_info($hash)['algoName'], "\n";
echo "이 값은 되돌릴 수 없습니다. 비밀번호 자체를 따로 기억해 두세요.\n\n";
