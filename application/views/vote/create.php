<div class="wrap">
	<div class="result-head">
		<div>
			<h1>투표 만들기</h1>
			<p class="crumbs"><span>후보 <b><?= count($places) ?>곳</b>을 팀에 물어봅니다</span></p>
		</div>
		<a class="btn btn-ghost btn-sm" href="javascript:history.back()">후보 다시 고르기</a>
	</div>

	<?php if (count($places) < $min): ?>

		<div class="empty">
			<h2>후보가 부족합니다</h2>
			<p>추천 결과에서 <?= (int) $min ?>곳 이상 담아야 투표를 만들 수 있습니다.</p>
			<a class="btn btn-ember" href="<?= base_url() ?>">추천 받으러 가기</a>
		</div>

	<?php else: ?>

	<div class="split">
		<div class="panel">
			<h2>후보 <?= count($places) ?>곳</h2>
			<ul class="rank-list" style="border-top:1px solid var(--steel)">
				<?php foreach ($places as $i => $p): ?>
				<li class="rank" style="grid-template-columns:34px 1fr;padding:14px 0">
					<span class="rank-no num" style="font-size:20px"><?= $i + 1 ?></span>
					<div>
						<p class="rank-name" style="font-size:16px"><?= h($p['name']) ?></p>
						<p class="rank-meta">
							<span><?= h($p['category_emoji']) ?> <?= h($p['category_label']) ?></span>
							<span class="sep">·</span>
							<span>1인 <?= h(ds_won($p['avg_price'])) ?></span>
							<span class="sep">·</span>
							<span><?= h($p['road_address'] ?: $p['address']) ?></span>
						</p>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="panel">
			<h2>투표 설정</h2>

			<form id="create-form">
				<input type="hidden" name="place_ids" value="<?= h(implode(',', array_column($places, 'id'))) ?>">

				<div class="field">
					<label for="v-title">투표 제목</label>
					<input type="text" id="v-title" name="title" maxlength="60"
					       value="<?= h(date('n') . '월 회식 장소') ?>" required>
				</div>

				<div class="field">
					<label for="v-nick">내 이름 (방장)</label>
					<input type="text" id="v-nick" name="host_nick" maxlength="20"
					       placeholder="예: 김대리" required>
				</div>

				<div class="grid-2">
					<div class="field">
						<label for="v-head">참석 인원</label>
						<input type="number" id="v-head" name="headcount" min="0" max="300" value="0">
						<p class="hint">0이면 표시하지 않습니다</p>
					</div>
					<div class="field">
						<label for="v-choice">1인당 선택 수</label>
						<select id="v-choice" name="max_choice">
							<?php for ($n = 1; $n <= min(3, count($places)); $n++): ?>
								<option value="<?= $n ?>"><?= $n ?>곳</option>
							<?php endfor; ?>
						</select>
					</div>
				</div>

				<div class="field">
					<label for="v-deadline">마감 시각</label>
					<input type="datetime-local" id="v-deadline" name="deadline_at"
					       value="<?= date('Y-m-d\TH:i', strtotime('+1 day 18:00')) ?>">
					<p class="hint">비워두면 방장이 직접 마감할 때까지 열려 있습니다</p>
				</div>

				<div class="field">
					<label class="chip" style="font-size:14px">
						<input type="checkbox" name="allow_change" value="1" checked> 마음 바뀌면 다시 투표 허용
					</label>
				</div>

				<button class="btn btn-ember btn-lg" type="submit" id="create-btn" style="width:100%">
					투표방 만들기
				</button>
			</form>
		</div>
	</div>

	<?php endif; ?>
</div>

<script defer src="<?= ds_asset('js/vote-create.js') ?>"></script>
