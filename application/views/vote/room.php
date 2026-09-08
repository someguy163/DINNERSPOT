<?php
$room    = $state['room'];
$options = $state['options'];
$closed  = ($room['status'] === 'closed');
?>
<section class="vote-head">
	<div class="wrap">
		<h1><?= h($room['title']) ?></h1>
		<p class="crumbs">
			<span><b><?= h($room['host_nick']) ?></b>님이 만든 투표</span>
			<?php if ($room['headcount'] > 0): ?>
				<span>참석 <b><?= (int) $room['headcount'] ?>명</b></span>
			<?php endif; ?>
			<span>1인 <b><?= (int) $room['max_choice'] ?>곳</b> 선택</span>
			<?php if ($room['deadline_at']): ?>
				<span>마감 <b><?= h(date('n/j(D) H:i', strtotime($room['deadline_at']))) ?></b></span>
			<?php endif; ?>
			<span id="head-status"><b><?= $closed ? '마감됨' : '진행 중' ?></b></span>
		</p>

		<div class="share">
			<span class="code-badge"><?= h($code) ?></span>
			<input type="text" id="share-url" value="<?= h($room_url) ?>" readonly aria-label="공유 링크">
			<button class="btn btn-sm" type="button" id="btn-copy">링크 복사</button>
			<button class="btn btn-sm btn-ember" type="button" id="btn-close" hidden>투표 마감</button>
		</div>
	</div>
</section>

<div class="wrap" style="margin-top:26px">
	<div class="split">

		<!-- 투표 -->
		<div class="panel" id="ballot-panel" <?= $closed ? 'hidden' : '' ?>>
			<h2>어디가 좋으세요?</h2>

			<form id="ballot-form">
				<div class="field">
					<label for="b-nick">이름</label>
					<input type="text" id="b-nick" name="nickname" maxlength="20"
					       placeholder="팀원이 알아볼 이름" autocomplete="off" required>
				</div>

				<ul class="ballot">
					<?php foreach ($options as $o): $p = $o['place']; ?>
					<li>
						<label>
							<input type="<?= $room['max_choice'] > 1 ? 'checkbox' : 'radio' ?>"
							       name="opt" class="opt-input" value="<?= (int) $o['id'] ?>">
							<span>
								<span class="b-name"><?= h($p['name'] ?? '이름 없음') ?></span>
								<span class="b-meta">
									<?php
									$bits = array();
									if ( ! empty($p['avg_price'])) $bits[] = '1인 ' . ds_won($p['avg_price']);
									if ( ! empty($p['distance_m'])) $bits[] = ds_distance_label($p['distance_m']);
									if ( ! empty($p['max_party'])) $bits[] = '최대 ' . (int) $p['max_party'] . '명';
									echo h(implode(' · ', $bits));
									?>
								</span>
							</span>
							<span class="tag"><?= h($p['road_address'] ?? ($p['address'] ?? '')) ?></span>
						</label>
					</li>
					<?php endforeach; ?>
				</ul>

				<div class="field" style="margin-top:18px">
					<label for="b-comment">한마디 (선택)</label>
					<input type="text" id="b-comment" name="comment" maxlength="60"
					       placeholder="예: 저는 회 못 먹어요">
				</div>

				<button class="btn btn-ember btn-lg" type="submit" id="ballot-btn" style="width:100%">
					투표하기
				</button>
				<p class="hint" id="ballot-hint" style="text-align:center;margin-top:10px"></p>
			</form>
		</div>

		<!-- 마감 안내 -->
		<div class="panel" id="closed-panel" <?= $closed ? '' : 'hidden' ?>>
			<h2>투표가 마감되었습니다</h2>
			<p style="color:var(--muted);margin:0">오른쪽 결과를 확인하세요.</p>
			<a class="btn btn-ghost" style="margin-top:16px" href="<?= base_url() ?>">새로 추천받기</a>
		</div>

		<!-- 집계 -->
		<div class="panel">
			<h2>지금까지 <span id="sum-voters" class="num"><?= (int) $state['voter_count'] ?></span>명 참여</h2>

			<ul class="tally" id="tally">
				<?php foreach ($options as $o): $p = $o['place']; ?>
				<li class="<?= $o['leading'] && $o['votes'] > 0 ? 'lead' : '' ?>" data-oid="<?= (int) $o['id'] ?>">
					<div class="tally-top">
						<span class="tally-name"><?= h($p['name'] ?? '') ?></span>
						<span class="tally-cnt num"><b><?= (int) $o['votes'] ?></b>표 · <?= (int) $o['percent'] ?>%</span>
					</div>
					<div class="tally-bar"><span class="tally-fill" style="width:<?= (int) $o['percent'] ?>%"></span></div>
				</li>
				<?php endforeach; ?>
			</ul>

			<h2 style="margin-top:24px">참여자</h2>
			<ul class="voters" id="voters">
				<?php if (empty($state['voters'])): ?>
					<li style="background:transparent;color:var(--muted-2);padding-left:0">아직 아무도 투표하지 않았습니다.</li>
				<?php else: ?>
					<?php foreach ($state['voters'] as $v): ?>
						<li><?= h($v['nickname']) ?><span class="ago"><?= h($v['ago']) ?></span></li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>

			<p style="margin:20px 0 0;font-size:13px;color:var(--muted-2)" id="poll-note">
				결과는 자동으로 갱신됩니다.
			</p>
		</div>
	</div>
</div>

<script>
window.DS_ROOM = {
	code: <?= json_encode($code) ?>,
	maxChoice: <?= (int) $room['max_choice'] ?>,
	allowChange: <?= (int) $room['allow_change'] ?>,
	status: <?= json_encode($room['status']) ?>,
	pollMs: <?= (int) $poll_ms ?>
};
</script>
<script defer src="<?= ds_asset('js/vote-room.js') ?>"></script>
