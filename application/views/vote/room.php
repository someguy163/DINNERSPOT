<?php
$room    = $state['room'];
$options = $state['options'];
$closed  = ($room['status'] === 'closed');

/**
 * 두 경로가 이 뷰를 공유한다.
 *   /vote/r/{code}  — 기존 open 모드. $invite 가 없다.
 *   /vote/i/{token} — 1인 1링크 초대 모드. $invite = ['token','nickname'].
 *
 * $invite 가 없을 때의 동작은 예전과 완전히 같아야 한다.
 */
$is_invite = isset($invite);

$invited_count = isset($state['invited_count']) ? (int) $state['invited_count'] : (int) $state['voter_count'];
$voter_count   = (int) $state['voter_count'];
$pending       = isset($state['pending']) ? (array) $state['pending'] : array();

// 초대 모드는 서버가 이미 "나"를 알고 있다 — JS 없이도 내가 고른 것이 체크돼 있어야 한다.
$has_voted = ($is_invite && ! empty($state['me']) && ! empty($state['me']['voted']));
$my_picks  = $has_voted ? array_map('intval', (array) $state['me']['picks']) : array();
$my_note   = $has_voted ? (string) $state['me']['comment'] : '';
$locked    = ($has_voted && empty($room['allow_change']));
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

		<?php if ( ! $is_invite): ?>
			<!-- 공유 영역은 방장 전용이다. 초대받은 사람에게는 링크·코드·마감이 의미가 없다. -->
			<div class="share">
				<span class="code-badge"><?= h($code) ?></span>
				<input type="text" id="share-url" value="<?= h($room_url) ?>" readonly aria-label="공유 링크">
				<button class="btn btn-sm" type="button" id="btn-copy">링크 복사</button>
				<button class="btn btn-sm btn-ember" type="button" id="btn-close" hidden>투표 마감</button>
			</div>
		<?php endif; ?>
	</div>
</section>

<div class="wrap" style="margin-top:26px">
	<div class="split">

		<!-- 투표 -->
		<div class="panel" id="ballot-panel" <?= $closed ? 'hidden' : '' ?>>
			<h2>어디가 좋으세요?</h2>

			<form id="ballot-form">
				<?php if ($is_invite): ?>
					<!-- 초대 모드: 토큰이 사람을 지목하므로 이름을 입력받지 않는다. -->
					<div class="whoami">
						<span><b><?= h($invite['nickname']) ?></b>님으로 투표합니다</span>
						<span class="whoami-note">
							방장이 정한 명단이라 이름은 바꿀 수 없습니다.
							<?php if ($locked): ?>
								이미 투표하셨고, 이 방은 재투표가 허용되지 않습니다.
							<?php elseif ($has_voted): ?>
								이미 투표하셨습니다. 마감 전까지 바꿀 수 있습니다.
							<?php endif; ?>
						</span>
					</div>
				<?php else: ?>
					<div class="field">
						<label for="b-nick">이름</label>
						<input type="text" id="b-nick" name="nickname" maxlength="20"
						       placeholder="팀원이 알아볼 이름" autocomplete="off" required>
					</div>
				<?php endif; ?>

				<ul class="ballot">
					<?php foreach ($options as $o): $p = $o['place']; ?>
					<li>
						<label>
							<input type="<?= $room['max_choice'] > 1 ? 'checkbox' : 'radio' ?>"
							       name="opt" class="opt-input" value="<?= (int) $o['id'] ?>"
							       <?= in_array((int) $o['id'], $my_picks, TRUE) ? 'checked' : '' ?>>
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
					       value="<?= h($my_note) ?>" placeholder="예: 저는 회 못 먹어요">
				</div>

				<button class="btn btn-ember btn-lg" type="submit" id="ballot-btn" style="width:100%"
				        <?= $locked ? 'disabled' : '' ?>>
					<?= $has_voted ? '투표 바꾸기' : '투표하기' ?>
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
			<?php if ($is_invite): ?>
				<h2>
					<span id="sum-invited" class="num"><?= $invited_count ?></span>명 중
					<span id="sum-voters" class="num"><?= $voter_count ?></span>명 참여
				</h2>

				<!-- 총무가 가장 먼저 보고 싶은 정보: 아직 안 한 사람 -->
				<p class="pending-note" id="pending-note" <?= $pending ? '' : 'hidden' ?>>
					<span class="pending-label">아직 안 한 사람</span>
					<span id="pending-names"><?= h(implode(', ', $pending)) ?></span>
				</p>
				<p class="pending-note pending-done" id="pending-done" <?= $pending ? 'hidden' : '' ?>>
					명단에 있는 <span class="num"><?= $invited_count ?></span>명 모두 투표했습니다.
				</p>
			<?php else: ?>
				<h2>지금까지 <span id="sum-voters" class="num"><?= $voter_count ?></span>명 참여</h2>
			<?php endif; ?>

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
						<li class="<?= empty($v['voted']) ? 'novote' : '' ?>">
							<?= h($v['nickname']) ?><span class="ago"><?= h($v['ago']) ?></span>
						</li>
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
	pollMs: <?= (int) $poll_ms ?>,
	invite: <?= json_encode($is_invite ? array('token' => $invite['token'], 'nickname' => $invite['nickname']) : NULL) ?>
};
</script>
<script defer src="<?= ds_asset('js/vote-room.js') ?>"></script>
