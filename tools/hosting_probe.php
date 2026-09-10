<?php
/**
 * 호스팅 적합성 판별기 — **이 파일 하나만 먼저 올려서 확인한다**
 *
 * 왜 필요한가:
 *   무료 호스팅은 서버에서 외부로 나가는 연결(아웃바운드)을 막는 곳이 있다.
 *   막히면 네이버 지역검색 API 가 동작하지 않고, 지역 사전 632곳 중 샘플
 *   장소가 있는 12개 구를 뺀 나머지가 **0건**이 된다. 즉 쓸 수 없는 화면이 된다.
 *
 *   전체를 올린 뒤에 그걸 알게 되면 시간이 아깝다. 그래서 이 파일 **하나만**
 *   먼저 올려 브라우저로 열어 보고, 통과하면 본 배포를 진행한다.
 *
 * 쓰는 법:
 *   1) 이 파일을 호스팅의 공개 폴더(htdocs · public_html)에 올린다
 *   2) 브라우저로 http://내주소/hosting_probe.php 를 연다
 *   3) 결과를 보고 판단한다
 *   4) ★ 확인이 끝나면 **반드시 지운다** (서버 정보를 남에게 보여줄 이유가 없다)
 *
 * 이 파일은 CodeIgniter 없이 혼자 동작한다. 그래서 본 배포 전에 쓸 수 있다.
 *
 * 키를 넣지 않는다:
 *   네이버 인증까지 시험하려면 키가 필요하지만, 키를 서버에 미리 두는 것은
 *   위험하고 필요도 없다. **연결이 되는지만** 본다 — 키 없이 부르면 네이버가
 *   401 을 돌려주는데, 그 401 이 곧 "밖으로 나갈 수 있다" 는 증거다.
 *   연결 자체가 막히면 cURL 이 오류를 내고 응답 코드가 0 이 된다.
 */

header('Content-Type: text/html; charset=utf-8');

$FAIL = 0;
$WARN = 0;
$rows = array();

function row($state, $label, $detail)
{
	global $rows, $FAIL, $WARN;

	if ($state === 'bad')  { $FAIL++; }
	if ($state === 'warn') { $WARN++; }

	$rows[] = array($state, $label, $detail);
}

/* ---------------------------------------------------------------- PHP */

$php_ok = version_compare(PHP_VERSION, '7.0', '>=');
row($php_ok ? 'ok' : 'bad', 'PHP 버전', PHP_VERSION . ($php_ok ? '' : ' — 7.0 이상이 필요합니다'));

$need = array(
	'mysqli'   => 'DB 접속',
	'curl'     => '네이버 API 호출',
	'mbstring' => '한글 처리',
	'json'     => '응답 파싱',
	'openssl'  => 'HTTPS',
);

foreach ($need as $ext => $why)
{
	extension_loaded($ext)
		? row('ok', '확장 ' . $ext, $why)
		: row('bad', '확장 ' . $ext, $why . ' — 이 호스팅에서는 동작하지 않습니다');
}

/* ------------------------------------------------- 아웃바운드 (핵심) */

if ( ! function_exists('curl_init'))
{
	row('bad', '아웃바운드 연결', 'cURL 이 없어 확인할 수 없습니다');
}
else
{
	$targets = array(
		'naverapihub.apigw.ntruss.com' => 'https://naverapihub.apigw.ntruss.com/search/v1/local?query=test&format=json',
		'openapi.naver.com'            => 'https://openapi.naver.com/v1/search/local.json?query=test',
	);

	$reachable = 0;

	foreach ($targets as $name => $url)
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_SSL_VERIFYPEER => TRUE,
		));
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		$ms   = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
		curl_close($ch);

		if ($code > 0)
		{
			/* 401/400 이면 "연결은 됐고 인증만 없다" 는 뜻이다 — 우리가 원하는 답. */
			$reachable++;
			row('ok', '연결 ' . $name, 'HTTP ' . $code . ' · ' . $ms . 'ms — 밖으로 나갈 수 있습니다'
				. ($code === 401 ? ' (401 = 키를 안 보냈으니 정상)' : ''));
		}
		else
		{
			row('bad', '연결 ' . $name, '실패 — ' . ($err !== '' ? $err : '응답 없음'));
		}
	}

	if ($reachable === 0)
	{
		row('bad', '★ 결론', '이 호스팅은 아웃바운드가 막혀 있습니다. 네이버 수집이 동작하지 않습니다');
	}
	else
	{
		row('ok', '★ 결론', '네이버 API 를 부를 수 있습니다');
	}
}

/* ------------------------------------------------------------ 쓰기 권한 */

$dir = __DIR__ . '/_probe_write_test';

if (@mkdir($dir, 0777, TRUE) OR is_dir($dir))
{
	$f = $dir . '/t.txt';

	if (@file_put_contents($f, 'ok') !== FALSE)
	{
		row('ok', '폴더 쓰기', '세션 · 로그를 기록할 수 있습니다');
		@unlink($f);
	}
	else
	{
		row('bad', '폴더 쓰기', '파일을 쓸 수 없습니다 — 세션이 동작하지 않습니다');
	}

	@rmdir($dir);
}
else
{
	row('bad', '폴더 만들기', '폴더를 만들 수 없습니다 — 세션 폴더가 생기지 않습니다');
}

/* -------------------------------------------------------- mod_rewrite */

$mods = function_exists('apache_get_modules') ? apache_get_modules() : NULL;

if (is_array($mods))
{
	in_array('mod_rewrite', $mods, TRUE)
		? row('ok', 'mod_rewrite', '주소에서 index.php 를 숨길 수 있습니다')
		: row('bad', 'mod_rewrite', '꺼져 있습니다 — 링크가 404 가 됩니다');
}
else
{
	row('warn', 'mod_rewrite', '확인할 수 없습니다(PHP 가 모듈 목록을 안 줍니다). '
		. '본 배포 후 /guide 가 열리는지로 판단하세요');
}

/* --------------------------------------------------------------- 기타 */

row(ini_get('allow_url_fopen') ? 'ok' : 'warn', 'allow_url_fopen',
	ini_get('allow_url_fopen') ? '켜져 있음' : '꺼져 있음 (이 프로젝트는 cURL 만 씁니다 — 문제 없음)');

$max = (int) ini_get('max_execution_time');
row(($max === 0 OR $max >= 30) ? 'ok' : 'warn', 'max_execution_time',
	($max === 0 ? '무제한' : $max . '초')
	. ' — 지역 첫 조회에 네이버 호출 8회로 약 4초가 걸립니다');

$env_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '(없음)';
row('ok', '이 서버가 본 주소', $env_host . ' — 지도 콘솔의 Web 서비스 URL 에 이 주소를 등록하세요');

/* --------------------------------------------------------------- 출력 */

$color = array('ok' => '#166534', 'warn' => '#92400E', 'bad' => '#991B1B');
$bg    = array('ok' => '#DCFCE7', 'warn' => '#FEF3C7', 'bad' => '#FEE2E2');
$mark  = array('ok' => '통과', 'warn' => '주의', 'bad' => '안됨');
?>
<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>호스팅 적합성 확인 · DINNERSPOT</title>
<style>
	body { font: 15px/1.6 system-ui, -apple-system, "Malgun Gothic", sans-serif;
	       margin: 0; padding: 24px; background: #FAFAF9; color: #1C1917; }
	.wrap { max-width: 760px; margin: 0 auto; }
	h1 { font-size: 22px; margin: 0 0 4px; }
	p.sub { color: #57534E; margin: 0 0 20px; }
	table { width: 100%; border-collapse: collapse; background: #fff;
	        border: 1px solid #E7E5E4; border-radius: 8px; overflow: hidden; }
	td { padding: 9px 12px; border-top: 1px solid #F5F5F4; vertical-align: top; }
	tr:first-child td { border-top: 0; }
	td.s { width: 58px; }
	span.b { display: inline-block; padding: 1px 8px; border-radius: 999px;
	         font-size: 12px; font-weight: 700; }
	td.l { width: 210px; font-weight: 700; }
	td.d { color: #44403C; }
	.verdict { margin: 22px 0 0; padding: 14px 16px; border-radius: 8px; font-weight: 600; }
	.del { margin: 22px 0 0; padding: 12px 16px; background: #FEE2E2; color: #991B1B;
	       border-radius: 8px; font-weight: 700; }
	code { background: #F5F5F4; padding: 1px 5px; border-radius: 4px; font-size: 13px; }
</style>
<div class="wrap">
	<h1>호스팅 적합성 확인</h1>
	<p class="sub">DINNERSPOT 를 이 호스팅에 올릴 수 있는지 봅니다. 본 배포 전에 이 파일만 올려 확인하세요.</p>

	<table>
		<?php foreach ($rows as $r): ?>
			<tr>
				<td class="s"><span class="b" style="background:<?= $bg[$r[0]] ?>;color:<?= $color[$r[0]] ?>"><?= $mark[$r[0]] ?></span></td>
				<td class="l"><?= htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8') ?></td>
				<td class="d"><?= htmlspecialchars($r[2], ENT_QUOTES, 'UTF-8') ?></td>
			</tr>
		<?php endforeach; ?>
	</table>

	<?php if ($FAIL === 0): ?>
		<div class="verdict" style="background:#DCFCE7;color:#166534">
			올릴 수 있습니다<?= $WARN ? ' (주의 ' . $WARN . '개는 읽어 두세요)' : '' ?>.
			README 의 &ldquo;10. 무료 호스팅에 올리기&rdquo; 순서로 진행하세요.
		</div>
	<?php else: ?>
		<div class="verdict" style="background:#FEE2E2;color:#991B1B">
			막히는 것이 <?= $FAIL ?>개 있습니다.
			<?php if (isset($reachable) && $reachable === 0): ?>
				<br>아웃바운드가 막혀 있으면 네이버 수집이 안 되고, 지역 사전 632곳 중
				샘플 장소가 있는 12개 구를 뺀 나머지가 <b>0건</b>이 됩니다.
				유료 등급이나 가상 서버를 쓰거나, 내 PC 에서 미리 수집한 장소 데이터를
				함께 올리는 우회가 필요합니다.
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="del">
		확인이 끝나면 이 파일(<code>hosting_probe.php</code>)을 <b>지우세요.</b>
		서버의 PHP 버전과 설정을 남에게 보여줄 이유가 없습니다.
	</div>
</div>
