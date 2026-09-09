<?php
/**
 * 관리자 관문.
 *
 * 계정 체계가 없고 비밀번호 하나가 전부다. 그래서 화면도 하나만 묻는다 —
 * vote/enter 의 좁은 단일 카드 레이아웃을 그대로 쓴다.
 *
 * $error  실패 메시지. 없으면 ''
 */
?>
<div class="wrap" style="max-width:460px;padding-top:72px;padding-bottom:90px">

	<h1 style="font-size:32px;font-weight:900;letter-spacing:-.035em;margin:0 0 8px">관리자</h1>
	<p style="color:var(--muted);margin:0 0 26px">
		전체 투표 현황을 보려면 관리자 비밀번호를 넣어주세요.
	</p>

	<div class="panel">
		<?php if ( ! empty($error)): ?>
			<p class="notice notice-danger" style="margin-top:0" role="alert">
				<b><?= h($error) ?></b>
			</p>
		<?php endif; ?>

		<form method="post" action="<?= base_url('admin/login') ?>">
			<div class="field">
				<label for="adm-pw">비밀번호</label>
				<input type="password" id="adm-pw" name="password" required
				       autocomplete="current-password" autofocus
				       aria-describedby="adm-pw-hint"
				       <?= ! empty($error) ? 'aria-invalid="true"' : '' ?>>
				<p class="hint" id="adm-pw-hint">
					<code>application/config/dinnerspot_local.php</code> 의 <b>admin_password</b> 값입니다.
					<code>dinnerspot.php</code> 쪽에 써도 맨 아래에서 local 파일을 include 하므로
					조용히 무시됩니다.
				</p>
			</div>

			<button class="btn btn-ember btn-lg" type="submit" style="width:100%">들어가기</button>
		</form>
	</div>

	<p style="margin-top:22px;color:var(--muted-2);font-size:13px">
		이 화면은 투표방 참여와 무관합니다.
		코드를 받아 투표하려면 <a href="<?= base_url('vote') ?>" style="color:var(--muted);font-weight:700">투표 참여</a>로 가세요.
	</p>
</div>
