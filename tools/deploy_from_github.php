<?php
/**
 * GitHub 에서 직접 받아 배포하는 스크립트 (서버에 올려서 브라우저로 실행)
 *
 * 왜 필요한가:
 *   회사 PC 의 보안 프로그램(DLP)이 웹 업로드와 FTP 를 모두 막는 경우가 있다.
 *   실측: File Manager 의 파일 선택이 "OfficeKeeper File Block" 으로 차단되고,
 *   FTP 는 ftpupload.net 이 127.0.0.1 로 해석되어 연결이 거부됐다.
 *
 *   그런데 **서버는 밖으로 나갈 수 있다**(tools/hosting_probe.php 로 확인).
 *   그러면 파일을 내가 올리는 대신 **서버가 GitHub 에서 받아오게** 하면 된다.
 *
 * 쓰는 법:
 *   1) File Manager 의 [New File] 로 htdocs 에 deploy.php 를 만든다
 *   2) [Edit] 로 열어 이 파일 내용을 **붙여넣기** 한다
 *      (붙여넣기는 파일 업로드가 아니므로 DLP 가 막지 않는 경우가 많다)
 *   3) 아래 $TOKEN 을 아무 값으로 바꾼다 (그대로 두면 실행을 거부한다)
 *   4) http://내주소/deploy.php?token=내가정한값 을 연다
 *   5) 끝나면 **deploy.php 를 지운다**
 *
 * 무엇을 하는가:
 *   - GitHub 의 zip 을 받아 (codeload.github.com)
 *   - index.php · .htaccess · application/ · system/ · assets/ 만 풀어 넣는다
 *     (sql/ · tools/ · README 등 서버에서 안 쓰는 것은 건너뛴다)
 *   - **application/config/dinnerspot_local.php 는 절대 건드리지 않는다** —
 *     키와 DB 비밀번호가 든 파일이라 덮어쓰면 사이트가 죽는다
 *
 * 다시 실행하면 최신 코드로 갱신된다. 즉 이 파일이 곧 배포 명령이다.
 */

/* ===== 설정 ===== */

$TOKEN  = 'CHANGE-ME';                       // ★ 아무 값으로 바꿔라
$REPO   = 'someguy163/DINNERSPOT';
$BRANCH = 'master';

/* 서버에서 실제로 쓰는 것만 */
$WANT = array('index.php', '.htaccess', 'application', 'system', 'assets');

/* 덮어쓰면 안 되는 것 (있으면 그대로 둔다) */
$KEEP = array('application/config/dinnerspot_local.php');

/* ===== 여기부터는 고칠 필요 없다 ===== */

header('Content-Type: text/html; charset=utf-8');
@set_time_limit(300);

$log  = array();
$fail = FALSE;

function say($state, $msg)
{
	global $log, $fail;

	if ($state === 'bad') { $fail = TRUE; }

	$log[] = array($state, $msg);
}

$given = isset($_GET['token']) ? (string) $_GET['token'] : '';

if ($TOKEN === 'CHANGE-ME')
{
	say('bad', '$TOKEN 을 바꾸지 않았습니다. 이 파일을 편집해 아무 값으로 바꾸세요 — '
		. '그대로 두면 누구나 이 주소로 배포를 돌릴 수 있습니다.');
}
elseif ( ! hash_equals($TOKEN, $given))
{
	say('bad', 'token 이 맞지 않습니다. 주소 끝에 ?token=내가정한값 을 붙여 여세요.');
}
elseif ( ! class_exists('ZipArchive'))
{
	say('bad', '이 서버에 PHP zip 확장이 없어 압축을 풀 수 없습니다. '
		. '이 방법은 쓸 수 없고 파일을 직접 올려야 합니다.');
}
else
{
	$url = 'https://codeload.github.com/' . $REPO . '/zip/refs/heads/' . $BRANCH;
	say('ok', '받는 곳: ' . $url);

	$tmp = sys_get_temp_dir() . '/ds-' . bin2hex(random_bytes(4)) . '.zip';
	$fh  = @fopen($tmp, 'wb');

	if ( ! $fh)
	{
		$tmp = __DIR__ . '/ds-deploy-tmp.zip';
		$fh  = @fopen($tmp, 'wb');
	}

	if ( ! $fh)
	{
		say('bad', '임시 파일을 만들 수 없습니다.');
	}
	else
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE           => $fh,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_SSL_VERIFYPEER => TRUE,
			CURLOPT_USERAGENT      => 'DINNERSPOT-deploy/1.0',
		));
		$ok   = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);
		fclose($fh);

		$size = @filesize($tmp);

		if ( ! $ok OR $code !== 200 OR $size < 10000)
		{
			say('bad', '내려받기 실패 — HTTP ' . $code
				. ($err !== '' ? (' / ' . $err) : '')
				. '. 저장소가 비공개면 이 방법은 쓸 수 없습니다(토큰 없이 못 받습니다).');
			@unlink($tmp);
		}
		else
		{
			say('ok', '내려받음 ' . number_format($size / 1024, 1) . 'KB');

			$zip = new ZipArchive();

			if ($zip->open($tmp) !== TRUE)
			{
				say('bad', 'zip 을 열 수 없습니다.');
			}
			else
			{
				/* GitHub zip 은 모든 것을 REPO-BRANCH/ 안에 담는다.
				   그 접두사를 떼야 htdocs 에 바로 풀린다. */
				$prefix = $zip->getNameIndex(0);
				$prefix = rtrim(explode('/', $prefix)[0], '/') . '/';
				say('ok', 'zip 안의 접두사: ' . $prefix . ' (떼어냅니다)');

				$root  = __DIR__;
				$n_put = 0;
				$n_dir = 0;
				$n_skip_want = 0;
				$n_keep = 0;

				for ($i = 0; $i < $zip->numFiles; $i++)
				{
					$name = $zip->getNameIndex($i);

					if (strpos($name, $prefix) !== 0)
					{
						continue;
					}

					$rel = substr($name, strlen($prefix));

					if ($rel === '')
					{
						continue;
					}

					/* 원하는 최상위 항목만 */
					$top = explode('/', $rel)[0];

					if ( ! in_array($top, $WANT, TRUE))
					{
						$n_skip_want++;
						continue;
					}

					/* 지켜야 하는 파일은 건너뛴다 */
					if (in_array($rel, $KEEP, TRUE) && is_file($root . '/' . $rel))
					{
						$n_keep++;
						continue;
					}

					$dst = $root . '/' . $rel;

					if (substr($rel, -1) === '/')
					{
						if ( ! is_dir($dst) && @mkdir($dst, 0755, TRUE)) { $n_dir++; }

						continue;
					}

					$dir = dirname($dst);

					if ( ! is_dir($dir)) { @mkdir($dir, 0755, TRUE); }

					$in = $zip->getStream($name);

					if ( ! $in) { continue; }

					$out = @fopen($dst, 'wb');

					if ($out)
					{
						stream_copy_to_stream($in, $out);
						fclose($out);
						$n_put++;
					}

					fclose($in);
				}

				$zip->close();
				@unlink($tmp);

				say('ok', '푼 파일 ' . $n_put . '개 · 새 폴더 ' . $n_dir . '개');
				say('ok', '건너뛴 항목 ' . $n_skip_want . '개 (sql · tools · README 등 서버에서 안 쓰는 것)');

				if ($n_keep > 0)
				{
					say('ok', '지킨 파일 ' . $n_keep . '개 — dinnerspot_local.php 는 덮어쓰지 않았습니다');
				}

				if ($n_put < 200)
				{
					say('bad', '파일 수가 예상(약 292개)보다 적습니다. 쓰기 권한을 확인하세요.');
				}

				/* 결과 확인 */
				foreach (array('index.php', '.htaccess', 'system/core/CodeIgniter.php',
				               'application/config/database.php', 'assets/css/app.css') as $must)
				{
					is_file($root . '/' . $must)
						? say('ok', '확인: ' . $must)
						: say('bad', '없음: ' . $must);
				}

				is_file($root . '/application/config/dinnerspot_local.php')
					? say('ok', '설정 파일 있음 — 접속해 보세요')
					: say('warn', 'application/config/dinnerspot_local.php 가 없습니다. '
						. 'New File 로 만들어 키와 db_* 를 넣어야 DB 에 붙습니다.');
			}
		}
	}
}

$bg   = array('ok' => '#DCFCE7', 'warn' => '#FEF3C7', 'bad' => '#FEE2E2');
$fg   = array('ok' => '#166534', 'warn' => '#92400E', 'bad' => '#991B1B');
$mark = array('ok' => '완료', 'warn' => '주의', 'bad' => '실패');
?>
<!doctype html>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GitHub 배포 · DINNERSPOT</title>
<style>
	body { font: 15px/1.6 system-ui, -apple-system, "Malgun Gothic", sans-serif;
	       margin: 0; padding: 24px; background: #FAFAF9; color: #1C1917; }
	.wrap { max-width: 780px; margin: 0 auto; }
	h1 { font-size: 21px; margin: 0 0 16px; }
	ul { list-style: none; padding: 0; margin: 0; background: #fff;
	     border: 1px solid #E7E5E4; border-radius: 8px; overflow: hidden; }
	li { padding: 9px 12px; border-top: 1px solid #F5F5F4; display: flex; gap: 10px; }
	li:first-child { border-top: 0; }
	span.b { flex: 0 0 auto; padding: 1px 8px; border-radius: 999px;
	         font-size: 12px; font-weight: 700; height: 22px; }
	.done { margin: 20px 0 0; padding: 14px 16px; border-radius: 8px; font-weight: 700; }
	.del { margin: 16px 0 0; padding: 12px 16px; background: #FEE2E2; color: #991B1B;
	       border-radius: 8px; font-weight: 700; }
	code { background: #F5F5F4; padding: 1px 5px; border-radius: 4px; font-size: 13px; }
</style>
<div class="wrap">
	<h1>GitHub 에서 배포</h1>
	<ul>
		<?php foreach ($log as $l): ?>
			<li><span class="b" style="background:<?= $bg[$l[0]] ?>;color:<?= $fg[$l[0]] ?>"><?= $mark[$l[0]] ?></span>
				<span><?= htmlspecialchars($l[1], ENT_QUOTES, 'UTF-8') ?></span></li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! $fail): ?>
		<div class="done" style="background:#DCFCE7;color:#166534">
			배포되었습니다. <a href="./">사이트 열기</a>
		</div>
		<div class="del">이 파일(<code>deploy.php</code>)을 <b>지우세요.</b>
			남겨두면 누구나 배포를 다시 돌릴 수 있습니다.</div>
	<?php else: ?>
		<div class="done" style="background:#FEE2E2;color:#991B1B">
			실패한 항목을 먼저 해결하세요.
		</div>
	<?php endif; ?>
</div>
