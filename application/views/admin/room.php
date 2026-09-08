<?php
/**
 * 방 하나의 전체 현황 (관리자).
 *
 * $state    Vote_model::state() 결과 — room / options / voters / invited_count /
 *           voter_count / pending / total_votes / is_tie
 * $code     방 코드
 * $invites  명단 모드일 때 [{nickname, invite_token, voted_at}], 아니면 []
 * $room_url 투표방 주소
 * $created  방 생성 시각
 *
 * 관리자가 이 화면에서 가장 먼저 확인하려는 것은 "아직 안 한 사람" 이다.
 * 그래서 미투표자 블록을 집계보다 위, 화면 맨 앞에 둔다.
 */
$room    = $state['room'];
$options = $state['options'];
$voters  = $state['voters'];
$pending = (array) $state['pending'];

$closed      = ($room['status'] === 'closed');
$invite_mode = ($room['mode'] === 'invite');

$invited = (int) $state['invited_count'];
$voted   = (int) $state['voter_count'];

// 참여율 분모: 명단 모드는 명단, 누구나 모드는 방장이 적은 참석 인원
$base    = $invite_mode ? $invited : (int) $room['headcount'];
$turnout = ($base > 0) ? (int) round($voted / $base * 100) : NULL;

// picks 는 option_id 배열이므로 이름으로 바꿔 보여준다
$opt_name = array();

foreach ($options as $o)
{
	$name = isset($o['place']['name']) ? (string) $o['place']['name'] : '';
	$opt_name[(int) $o['id']] = ($name !== '') ? $name : '이름 없음';
}

$pending_invites = 0;

foreach ($invites as $iv)
{
	if (empty($iv['voted_at']))
	{
		$pending_invites++;
	}
}
?>
<section class="vote-head">
	<div class="wrap">
		<p style="margin:0 0 4px;font-size:12.5px;font-weight:700;color:#A79C92">관리자 · 방 현황</p>
		<h1><?= h($room['title']) ?></h1>
		<p class="crumbs">
			<span><b><?= h($room['host_nick']) ?></b>님이 만든 투표</span>
			<span><b><?= $invite_mode ? '명단 모드' : '누구나 참여' ?></b></span>
			<span><b><?= $closed ? '마감됨' : '진행 중' ?></b></span>
			<?php if ($base > 0): ?>
				<span>참여 <b class="num"><?= $voted ?>/<?= $base ?></b><?= $turnout !== NULL ? ' · ' . $turnout . '%' : '' ?></span>
			<?php else: ?>
				<span>투표 <b class="num"><?= $voted ?>명</b></span>
			<?php endif; ?>
			<span>총 <b class="num"><?= (int) $state['total_votes'] ?>표</b></span>
		</p>

		<div class="share">
			<span class="code-badge"><?= h($code) ?></span>
			<input type="text" id="room-url" value="<?= h($room_url) ?>" readonly aria-label="투표방 링크">
			<button class="btn btn-sm js-copy" type="button"
			        data-copy="<?= h($room_url) ?>" data-msg="방 링크를 복사했습니다.">링크 복사</button>
			<a class="btn btn-sm btn-ember" href="<?= h($room_url) ?>">투표방 열기</a>
			<a class="btn btn-sm btn-ghost adm-on-dark" href="<?= base_url('admin') ?>">목록으로</a>
		</div>
	</div>
</section>

<div class="wrap" style="margin-top:24px">

	<!-- 아직 안 한 사람 -->
	<?php if ( ! $invite_mode): ?>
		<div class="adm-pend adm-pend-open">
			<h2>미투표자를 알 수 없는 방입니다</h2>
			<p style="margin-top:0">
				<b>누구나 참여</b> 방식이라 투표한 사람만 기록됩니다.
				<?php if ($base > 0): ?>
					방장이 적은 참석 인원 <b class="num"><?= $base ?>명</b> 중
					<b class="num"><?= $voted ?>명</b>이 투표해
					<b class="num"><?= max(0, $base - $voted) ?>명</b>이 남았지만, 그 사람이 누구인지는 남지 않습니다.
				<?php else: ?>
					참석 인원도 적히지 않아 남은 인원조차 셀 수 없습니다.
				<?php endif; ?>
				이름을 미리 알고 싶으면 방을 만들 때 <b>명단 모드</b>를 쓰세요.
			</p>
		</div>
	<?php elseif ($pending): ?>
		<div class="adm-pend">
			<h2>아직 투표하지 않은 사람 <span class="num"><?= count($pending) ?>명</span></h2>
			<ul class="voters">
				<?php foreach ($pending as $name): ?>
					<li><?= h($name) ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				명단 <b class="num"><?= $invited ?>명</b> 중
				<b class="num"><?= $voted ?>명</b> 투표했습니다.
				<?php if ($closed): ?>
					이미 마감된 방이라 이 사람들은 더 이상 투표할 수 없습니다.
				<?php else: ?>
					아래 <b>개인 링크</b>를 다시 보내 독촉할 수 있습니다.
				<?php endif; ?>
			</p>
		</div>
	<?php elseif ($invited === 0): ?>
		<div class="adm-pend adm-pend-open">
			<h2>명단이 비어 있습니다</h2>
			<p style="margin-top:0">
				명단 모드로 만들어졌는데 등록된 사람이 없습니다.
				이름이 모두 공백이었거나 같은 이름으로 걸러졌을 수 있습니다 —
				이 방은 <b>아무도 투표할 수 없습니다</b>. 방을 다시 만들어야 합니다.
			</p>
		</div>
	<?php else: ?>
		<div class="adm-pend adm-pend-done">
			<h2>전원 투표 완료</h2>
			<p style="margin-top:0">
				명단에 오른 <b class="num"><?= $invited ?>명</b> 모두 투표했습니다.
				<?= $closed ? '' : '마감하지 않아도 결과는 확정된 상태입니다.' ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="split">
		<div>
			<!-- 후보별 득표 -->
			<div class="panel">
				<h2>후보별 득표 <span class="num" style="color:var(--muted);font-weight:700"><?= count($options) ?>곳</span></h2>

				<?php if (empty($options)): ?>
					<p style="margin:0;color:var(--muted)">후보가 없는 방입니다.</p>
				<?php elseif ((int) $state['total_votes'] === 0): ?>
					<p class="adm-note">아직 표가 없어 막대가 모두 비어 있습니다.</p>
				<?php endif; ?>

				<ul class="tally">
					<?php foreach ($options as $o): $p = $o['place']; ?>
					<li class="<?= $o['leading'] && $o['votes'] > 0 ? 'lead' : '' ?>">
						<div class="tally-top">
							<span class="tally-name"><?= h($opt_name[(int) $o['id']]) ?></span>
							<span class="tally-cnt num"><b><?= (int) $o['votes'] ?></b>표 · <?= (int) $o['percent'] ?>%</span>
						</div>
						<div class="tally-bar"><span class="tally-fill" style="width:<?= (int) $o['percent'] ?>%"></span></div>
						<?php
						$bits = array();
						if ( ! empty($p['avg_price']))    $bits[] = '1인 ' . ds_won($p['avg_price']);
						if ( ! empty($p['max_party']))    $bits[] = '최대 ' . (int) $p['max_party'] . '명';
						if ( ! empty($p['road_address'])) $bits[] = $p['road_address'];
						elseif ( ! empty($p['address']))  $bits[] = $p['address'];
						?>
						<?php if ($bits): ?>
							<p class="adm-note" style="margin:6px 0 0"><?= h(implode(' · ', $bits)) ?></p>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>

				<?php if ($state['is_tie']): ?>
					<p class="notice notice-soju" style="margin-bottom:0">동점입니다. 방장이 정하거나 마감을 미뤄야 합니다.</p>
				<?php endif; ?>
			</div>

			<!-- 누가 무엇을 골랐는지 -->
			<div class="panel">
				<h2>누가 무엇을 골랐나</h2>

				<?php if (empty($voters)): ?>
					<p style="margin:0;color:var(--muted)">
						<?= $invite_mode
							? '명단은 있는데 참여자 행이 없습니다. 방을 만들 때 명단이 비어 있었을 수 있습니다.'
							: '아직 아무도 투표하지 않았습니다.' ?>
					</p>
				<?php else: ?>
					<div class="adm-scroll">
						<table class="adm-table adm-table-tight">
							<thead>
								<tr>
									<th>참여자</th>
									<th>고른 곳</th>
									<th>한마디</th>
									<th>시각</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($voters as $v): ?>
								<tr>
									<td class="adm-nowrap">
										<b><?= h($v['nickname']) ?></b>
										<?php if (empty($v['voted'])): ?>
											<span class="adm-st adm-st-pend">미투표</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if (empty($v['picks'])): ?>
											<span class="adm-sub">-</span>
										<?php else: ?>
											<ul class="reasons">
												<?php foreach ($v['picks'] as $oid): ?>
													<li><?= h(isset($opt_name[(int) $oid]) ? $opt_name[(int) $oid] : '삭제된 후보') ?></li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</td>
									<td>
										<?php if ((string) $v['comment'] !== ''): ?>
											<?= h($v['comment']) ?>
										<?php else: ?>
											<span class="adm-sub">-</span>
										<?php endif; ?>
									</td>
									<td class="adm-nowrap">
										<?php if ( ! empty($v['voted_at'])): ?>
											<span class="num"><?= h(date('n/j H:i', strtotime($v['voted_at']))) ?></span>
											<span class="adm-sub"><?= h($v['ago']) ?></span>
										<?php else: ?>
											<span class="adm-sub">-</span>
										<?php endif; ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div>
			<!-- 방 정보 -->
			<div class="panel">
				<h2>방 정보</h2>
				<ul class="spec">
					<li><span class="k">코드</span><span class="num" style="font-weight:800;letter-spacing:.1em"><?= h($code) ?></span></li>
					<li><span class="k">방장</span><span><?= h($room['host_nick']) ?></span></li>
					<li><span class="k">상태</span>
						<span>
							<span class="adm-st <?= $closed ? 'adm-st-closed' : 'adm-st-open' ?>"><?= $closed ? '마감' : '진행 중' ?></span>
						</span>
					</li>
					<li><span class="k">방식</span>
						<span>
							<?php if ($invite_mode): ?>
								<span class="tag adm-tag-invite">명단</span> 링크를 받은 사람만
							<?php else: ?>
								<span class="tag">누구나</span> 코드를 아는 사람 모두
							<?php endif; ?>
						</span>
					</li>
					<li><span class="k">선택 수</span><span>1인 <span class="num"><?= (int) $room['max_choice'] ?></span>곳</span></li>
					<li><span class="k">참석 인원</span>
						<span>
							<?php if ((int) $room['headcount'] > 0): ?>
								<span class="num"><?= (int) $room['headcount'] ?></span>명
							<?php else: ?>
								<span class="adm-sub">미입력</span>
							<?php endif; ?>
						</span>
					</li>
					<li><span class="k">재투표</span><span><?= empty($room['allow_change']) ? '한 번만' : '마감 전까지 변경 가능' ?></span></li>
					<li><span class="k">마감시각</span>
						<span>
							<?php if (empty($room['deadline_at'])): ?>
								<span class="adm-sub">없음 (방장이 직접 마감)</span>
							<?php else: ?>
								<span class="num"><?= h(date('Y.n.j(D) H:i', strtotime($room['deadline_at']))) ?></span>
								<?php if ( ! $closed && strtotime($room['deadline_at']) < time()): ?>
									<span class="adm-over">지남</span>
								<?php endif; ?>
							<?php endif; ?>
						</span>
					</li>
					<li><span class="k">생성일</span>
						<span>
							<span class="num"><?= h(date('Y.n.j H:i', strtotime($created))) ?></span>
							<span class="adm-sub"><?= h(ds_time_ago($created)) ?></span>
						</span>
					</li>
				</ul>
			</div>

			<!-- 참여율 -->
			<div class="panel">
				<h2>참여</h2>
				<?php if ($turnout === NULL): ?>
					<p style="margin:0;font-size:15px">
						지금까지 <b class="num" style="font-size:26px"><?= $voted ?></b>명 투표
					</p>
					<p class="adm-note" style="margin:8px 0 0">
						<?= $invite_mode
							? '명단이 비어 있어 참여율(분모)을 낼 수 없습니다.'
							: '참석 인원이 적혀 있지 않아 참여율(분모)을 낼 수 없습니다.' ?>
					</p>
				<?php else: ?>
					<div class="adm-turn-top" style="font-size:15px">
						<span><b class="num" style="font-size:26px"><?= $voted ?></b> / <span class="num"><?= $base ?></span>명</span>
						<span class="pct num" style="font-size:17px"><?= $turnout ?>%</span>
					</div>
					<span class="adm-bar" style="height:10px">
						<span class="adm-bar-fill<?= $turnout >= 100 ? ' full' : '' ?>"
						      style="width:<?= min(100, max(0, $turnout)) ?>%"></span>
					</span>
					<p class="adm-note" style="margin:10px 0 0">
						분모는 <?= $invite_mode ? '명단에 오른 인원' : '방장이 적은 참석 인원' ?>입니다.
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- 개인 링크 (명단 모드) -->
	<?php if ($invite_mode): ?>
		<div class="panel" style="margin-top:16px">
			<h2>개인 링크 <span class="num" style="color:var(--muted);font-weight:700"><?= count($invites) ?>명</span></h2>

			<?php if (empty($invites)): ?>
				<p style="margin:0;color:var(--muted)">
					명단 모드인데 발급된 링크가 없습니다. 방을 만들 때 명단이 모두 공백이었을 수 있습니다.
				</p>
			<?php else: ?>
				<p class="notice notice-danger" style="margin-top:0">
					<b>개인 링크 = 그 사람의 투표 권한입니다.</b>
					목록을 단체방에 붙이면 서로 남의 링크로 투표할 수 있습니다. 각자에게 개인 메시지로 보내세요.
				</p>

				<?php if ($pending_invites > 0): ?>
					<div class="copy-row">
						<button class="btn btn-sm btn-soju" type="button" id="adm-copy-pending">
							미투표자만 복사 <span class="num">(<?= $pending_invites ?>명)</span>
						</button>
					</div>
				<?php endif; ?>

				<ul class="invite-list" id="adm-invites">
					<?php foreach ($invites as $i => $iv): $url = base_url('vote/i/' . rawurlencode($iv['invite_token'])); ?>
					<li class="invite<?= ! empty($iv['voted_at']) ? ' done' : '' ?>" data-name="<?= h($iv['nickname']) ?>">
						<span class="invite-who">
							<span class="invite-no num"><?= $i + 1 ?></span>
							<span class="invite-name"><?= h($iv['nickname']) ?></span>
							<?php if ( ! empty($iv['voted_at'])): ?>
								<span class="invite-state done">투표 완료 · <?= h(ds_time_ago($iv['voted_at'])) ?></span>
							<?php else: ?>
								<span class="invite-state pending">미투표</span>
							<?php endif; ?>
						</span>
						<input class="invite-url" type="text" readonly value="<?= h($url) ?>"
						       aria-label="<?= h($iv['nickname']) ?> 개인 링크">
						<button class="btn btn-sm invite-copy js-copy" type="button"
						        data-copy="<?= h($url) ?>"
						        data-msg="<?= h($iv['nickname']) ?>님 링크를 복사했습니다.">복사</button>
					</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div style="display:flex;gap:8px;flex-wrap:wrap;margin:22px 0 60px">
		<a class="btn btn-ghost" href="<?= base_url('admin') ?>">← 전체 현황</a>
		<a class="btn btn-ember" href="<?= h($room_url) ?>">투표방 열기</a>
		<?php if ($invite_mode): ?>
			<a class="btn btn-ghost" href="<?= base_url('vote/r/' . rawurlencode($code) . '/invites') ?>">방장용 초대 링크 화면</a>
		<?php endif; ?>
	</div>
</div>

<script defer src="<?= ds_asset('js/admin.js') ?>"></script>
