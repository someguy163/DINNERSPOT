<?php
/**
 * 방장용 초대 링크 목록.
 *
 * 이 화면이 명단 모드의 실사용 지점이다 — 방장은 여기서 사람별 링크를
 * 하나씩 복사해 개인 메시지로 보낸다. 목록 전체가 곧 모든 사람의
 * 투표 권한이므로 단체방에 붙이면 안 된다는 경고를 위에 크게 둔다.
 *
 * $room     ['code','title','mode']
 * $invites  [{nickname, invite_token, voted_at}]
 * $room_url 투표방(집계) 주소
 */
$rows    = array();
$voted   = 0;
$pending = 0;

foreach ($invites as $iv)
{
	$done = ! empty($iv['voted_at']);
	$done ? $voted++ : $pending++;

	$rows[] = array(
		'name'  => $iv['nickname'],
		'url'   => base_url('vote/i/' . $iv['invite_token']),
		'voted' => $done,
		'ago'   => $done ? ds_time_ago($iv['voted_at']) : '',
	);
}

$total = count($rows);
?>
<section class="vote-head">
	<div class="wrap">
		<h1><?= h($room['title']) ?> · 초대 링크</h1>
		<p class="crumbs">
			<span><b class="num"><?= $total ?>명</b> 중 <b class="num"><?= $voted ?>명</b> 참여</span>
			<?php if ($pending > 0): ?>
				<span>아직 <b class="num"><?= $pending ?>명</b> 남음</span>
			<?php else: ?>
				<span><b>전원 완료</b></span>
			<?php endif; ?>
			<span>1인 1링크 방식</span>
		</p>

		<div class="share">
			<span class="code-badge"><?= h($room['code']) ?></span>
			<input type="text" id="room-url" value="<?= h($room_url) ?>" readonly aria-label="투표방 링크">
			<button class="btn btn-sm" type="button" id="btn-copy-room">방 링크 복사</button>
			<a class="btn btn-sm btn-ember" href="<?= h($room_url) ?>">집계 보기</a>
		</div>
	</div>
</section>

<div class="wrap" style="margin-top:24px;max-width:860px">

	<p class="notice notice-danger" style="margin-top:0">
		<b>개인 링크 = 그 사람의 투표 권한입니다.</b>
		목록 전체를 단체 채팅방에 붙이면 서로 남의 링크로 투표할 수 있습니다.
		아래 <b>[복사]</b> 로 하나씩 가져가서 <b>각자에게 개인 메시지로</b> 보내주세요.
		단체방에는 위의 <b>방 링크</b>(집계 보기)만 공유해도 됩니다.
	</p>

	<?php if ($total === 0): ?>

		<div class="empty">
			<h2>초대할 사람이 없습니다</h2>
			<p>
				명단 모드로 만든 방인데 명단이 비어 있습니다.<br>
				이름이 모두 공백이었거나 같은 이름으로 걸러졌을 수 있습니다.
				한 줄에 한 명씩 다시 넣어 만들어 주세요.
			</p>
			<a class="btn btn-ember" href="<?= base_url() ?>">후보 고르고 다시 만들기</a>
		</div>

	<?php else: ?>

		<div class="panel">
			<h2>사람별 링크 <span class="num" style="color:var(--muted);font-weight:700"><?= $total ?>명</span></h2>

			<div class="copy-row">
				<button class="btn btn-sm btn-ember" type="button" id="btn-copy-all">
					전체 복사 <span class="num">(<?= $total ?>명)</span>
				</button>
				<button class="btn btn-sm btn-soju" type="button" id="btn-copy-pending"
				        <?= $pending === 0 ? 'disabled' : '' ?>>
					미투표자만 복사 <span class="num">(<?= $pending ?>명)</span>
				</button>
			</div>

			<p class="invite-note">
				복사한 텍스트는 <b>"이름 링크"</b> 가 한 줄씩 들어갑니다.
				메모장이나 개인 대화창에 붙여 이름을 보고 각자에게 나눠 보내세요.
				<?php if ($pending > 0): ?>
					독촉할 때는 <b>미투표자만 복사</b>가 편합니다.
				<?php endif; ?>
			</p>

			<ul class="invite-list" id="invite-list">
				<?php foreach ($rows as $i => $r): ?>
				<li class="invite<?= $r['voted'] ? ' done' : '' ?>">
					<span class="invite-who">
						<span class="invite-no num"><?= $i + 1 ?></span>
						<span class="invite-name"><?= h($r['name']) ?></span>
						<?php if ($r['voted']): ?>
							<span class="invite-state done">투표 완료<?= $r['ago'] ? ' · ' . h($r['ago']) : '' ?></span>
						<?php else: ?>
							<span class="invite-state pending">미투표</span>
						<?php endif; ?>
					</span>
					<input class="invite-url" type="text" readonly value="<?= h($r['url']) ?>"
					       aria-label="<?= h($r['name']) ?> 개인 링크">
					<button class="btn btn-sm invite-copy js-copy-one" type="button"
					        data-url="<?= h($r['url']) ?>" data-name="<?= h($r['name']) ?>">복사</button>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="panel">
			<h2>다음 순서</h2>
			<ol class="invite-steps">
				<li>사람별 <b>[복사]</b> 로 링크를 가져가 <b>개인 메시지</b>로 보냅니다.</li>
				<li>참여 현황은 이 화면을 <b>새로고침</b>하면 갱신됩니다. 미투표자에게는 링크를 다시 보내세요.</li>
				<li>표를 확인하고 마감하는 건 <a href="<?= h($room_url) ?>">투표방 화면</a>에서 합니다.</li>
			</ol>
			<p class="invite-note" style="margin-bottom:0">
				명단 모드에서는 링크를 받은 사람만 투표할 수 있고, 이름은 이미 정해져 있어
				누가 아직 안 했는지 그대로 보입니다.
			</p>
		</div>

	<?php endif; ?>
</div>

<script>window.DS_INVITES = <?= json_encode(array(
	'code'    => $room['code'],
	'title'   => $room['title'],
	'roomUrl' => $room_url,
	'people'  => $rows,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script defer src="<?= ds_asset('js/vote-invites.js') ?>"></script>
