<div class="wrap">
	<div class="result-head">
		<div>
			<h1>투표 만들기</h1>
			<p class="crumbs"><span>후보 <b><?= count($places) ?>곳</b>을 팀에 물어봅니다</span></p>
		</div>
		<!-- href 는 폴백이다. 이력이 없을 때 history.back() 은 about:blank 로 간다. -->
		<a class="btn btn-ghost btn-sm js-back" href="<?= base_url() ?>">후보 다시 고르기</a>
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

				<div class="field">
					<span class="fld-cap" id="v-mode-cap">참여 방식</span>
					<div class="mode-pick" role="radiogroup" aria-labelledby="v-mode-cap">
						<label class="chip">
							<input type="radio" name="join_mode" value="open" checked> 누구나 링크로 참여
						</label>
						<label class="chip">
							<input type="radio" name="join_mode" value="invite"> 명단으로 1인 1링크
						</label>
					</div>
					<p class="invite-note" id="v-mode-hint" style="margin:8px 0 0">
						링크 하나를 단체방에 뿌립니다. 참여자가 이름을 직접 적습니다.
					</p>
				</div>

				<div class="field" id="v-roster-field" hidden>
					<label for="v-roster">참석자 명단</label>
					<textarea id="v-roster" name="roster" rows="6"
					          placeholder="한 줄에 한 명, 또는 콤마로 구분&#10;김대리&#10;박사원&#10;이과장&#10;&#10;또는: 김대리, 박사원, 이과장"></textarea>
					<p class="invite-note" id="v-roster-count" style="margin:8px 0 0" role="status" aria-live="polite">
						아직 아무도 없습니다.
					</p>
					<p class="invite-note" style="margin:0">
						명단에 오른 사람 수가 곧 참석 인원입니다(최대 100명).
						동명이인은 <b>박사원A</b>, <b>박사원B</b> 처럼 구분해 주세요.
						만들면 <b>사람별 초대 링크 목록</b>이 나오고, 거기서 각자에게 하나씩 보냅니다.
					</p>
				</div>

				<div class="grid-2" id="v-count-row">
					<div class="field" id="v-head-field">
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
