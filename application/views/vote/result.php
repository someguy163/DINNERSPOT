<?php
$room    = $state['room'];
$options = $state['options'];
$winner  = NULL;

foreach ($options as $o)
{
	if ($o['leading'] && $o['votes'] > 0)
	{
		$winner = $o;
		break;
	}
}
?>
<section class="vote-head">
	<div class="wrap">
		<h1><?= h($room['title']) ?> · 결과</h1>
		<p class="crumbs">
			<span><b><?= (int) $state['voter_count'] ?>명</b> 참여</span>
			<span>총 <b><?= (int) $state['total_votes'] ?>표</b></span>
			<span><b><?= $room['status'] === 'closed' ? '마감됨' : '진행 중' ?></b></span>
		</p>
	</div>
</section>

<div class="wrap" style="margin-top:26px;max-width:760px">

	<?php if ($winner === NULL): ?>
		<div class="empty">
			<h2>아직 표가 없습니다</h2>
			<p>팀원들에게 링크를 보내주세요.</p>
			<a class="btn btn-ember" href="<?= h($room_url) ?>">투표방으로</a>
		</div>
	<?php else: ?>

		<div class="panel" style="border-color:var(--ember);border-width:2px">
			<p style="margin:0 0 4px;font-size:13px;font-weight:700;color:var(--ember)">
				<?= $state['is_tie'] ? '공동 1위' : '1위' ?>
			</p>
			<h2 style="font-size:28px;margin:0 0 6px"><?= h($winner['place']['name'] ?? '') ?></h2>
			<p style="margin:0;color:var(--muted)">
				<?= (int) $winner['votes'] ?>표 · <?= (int) $winner['percent'] ?>%
				<?php if ( ! empty($winner['place']['road_address'])): ?>
					· <?= h($winner['place']['road_address']) ?>
				<?php endif; ?>
			</p>
			<?php if ($state['is_tie']): ?>
				<p class="notice notice-soju" style="margin-bottom:0">동점입니다. 방장이 정하거나 조금 더 기다려 보세요.</p>
			<?php endif; ?>
		</div>

		<div class="panel">
			<h2>전체 집계</h2>
			<ul class="tally">
				<?php foreach ($options as $o): ?>
				<li class="<?= $o['leading'] && $o['votes'] > 0 ? 'lead' : '' ?>">
					<div class="tally-top">
						<span class="tally-name"><?= h($o['place']['name'] ?? '') ?></span>
						<span class="tally-cnt num"><b><?= (int) $o['votes'] ?></b>표 · <?= (int) $o['percent'] ?>%</span>
					</div>
					<div class="tally-bar"><span class="tally-fill" style="width:<?= (int) $o['percent'] ?>%"></span></div>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<?php if ( ! empty($state['voters'])): ?>
		<div class="panel">
			<h2>참여자</h2>
			<ul class="voters">
				<?php foreach ($state['voters'] as $v): ?>
					<li>
						<?= h($v['nickname']) ?>
						<?php if ($v['comment']): ?><span class="ago"><?= h($v['comment']) ?></span><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<div style="display:flex;gap:8px;margin:22px 0 60px">
			<a class="btn btn-ghost" href="<?= h($room_url) ?>">투표방으로</a>
			<a class="btn btn-ember" href="<?= base_url() ?>">새로 추천받기</a>
		</div>

	<?php endif; ?>
</div>
