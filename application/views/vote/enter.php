<div class="wrap" style="max-width:560px;padding-top:64px;padding-bottom:80px">
	<h1 style="font-size:34px;font-weight:900;letter-spacing:-.035em;margin:0 0 8px">투표방 참여</h1>
	<p style="color:var(--muted);margin:0 0 26px">받은 코드를 넣으면 바로 들어갑니다.</p>

	<div class="panel">
		<?php if ($error): ?>
			<p class="notice" style="margin-top:0;border-left-color:var(--danger);background:rgba(194,50,31,.06)">
				<?= h($error) ?>
			</p>
		<?php endif; ?>

		<form method="get" action="<?= base_url('vote') ?>">
			<div class="field">
				<label for="join-code">투표 코드</label>
				<input type="text" id="join-code" name="code" maxlength="12" required
				       value="<?= h($code) ?>" placeholder="예: K7M2PQXD" autocomplete="off"
				       style="letter-spacing:.2em;font-weight:800;font-size:20px;text-align:center;text-transform:uppercase">
			</div>
			<button class="btn btn-soju btn-lg" type="submit" style="width:100%">참여하기</button>
		</form>
	</div>

	<p style="margin-top:22px;color:var(--muted);font-size:14px">
		아직 투표방이 없다면 <a href="<?= base_url() ?>" style="font-weight:700">먼저 추천을 받아보세요</a>.
		후보를 담으면 바로 투표방을 만들 수 있습니다.
	</p>
</div>
