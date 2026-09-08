<?php
/**
 * 전체 투표 현황.
 *
 * $summary  {rooms, open_rooms, closed_rooms, invite_rooms, voters, voted, ballots}
 * $rooms    admin_rooms() 결과. 각 원소는
 *           {code,title,host_nick,headcount,max_choice,mode,status,deadline_at,created_at,
 *            invited_count,voted_count,total_votes,leader:{name,votes}|null,option_count,turnout}
 * $status   'open' | 'closed' | ''
 * $q        검색어
 *
 * 지표는 카드 6개를 늘어놓지 않고 순위표와 같은 톤의 지표 띠로 묶는다.
 */
$now       = time();
$filtered  = ($q !== '' OR $status === 'open' OR $status === 'closed');
$shown     = count($rooms);
$sum_voted = (int) $summary['voted'];
$sum_all   = (int) $summary['voters'];
$sum_pct   = ($sum_all > 0) ? (int) round($sum_voted / $sum_all * 100) : NULL;
?>
<div class="wrap">

	<div class="adm-head">
		<div>
			<h1>전체 투표 현황</h1>
			<p>비밀번호를 아는 사람만 보는 화면입니다. 주소를 공유하지 마세요.</p>
		</div>
		<a class="btn btn-ghost btn-sm" href="<?= base_url('admin/logout') ?>">로그아웃</a>
	</div>

	<!-- 요약 -->
	<dl class="adm-sum">
		<div class="adm-sum-cell">
			<dt>전체 방</dt>
			<dd>
				<span class="adm-sum-big num"><?= (int) $summary['rooms'] ?></span>
				<span class="adm-sum-sub">
					<span>진행 중 <b class="num"><?= (int) $summary['open_rooms'] ?></b></span>
					<span>마감 <b class="num"><?= (int) $summary['closed_rooms'] ?></b></span>
					<span>명단모드 <b class="num"><?= (int) $summary['invite_rooms'] ?></b></span>
				</span>
			</dd>
		</div>
		<div class="adm-sum-cell">
			<dt>참여자</dt>
			<dd>
				<span class="adm-sum-big num"><?= $sum_all ?></span>
				<span class="adm-sum-sub">
					<span>투표완료 <b class="num"><?= $sum_voted ?></b></span>
					<?php if ($sum_pct !== NULL): ?>
						<span><b class="num"><?= $sum_pct ?>%</b></span>
					<?php endif; ?>
				</span>
			</dd>
		</div>
		<div class="adm-sum-cell">
			<dt>총 표</dt>
			<dd>
				<span class="adm-sum-big num"><?= (int) $summary['ballots'] ?></span>
				<span class="adm-sum-sub">
					<span>1인 여러 곳을 고를 수 있어 참여자보다 많습니다</span>
				</span>
			</dd>
		</div>
	</dl>

	<!-- 필터 -->
	<form class="adm-filter" method="get" action="<?= base_url('admin') ?>">
		<select name="status" aria-label="상태">
			<option value="" <?= $status === '' ? 'selected' : '' ?>>전체 상태</option>
			<option value="open" <?= $status === 'open' ? 'selected' : '' ?>>진행 중만</option>
			<option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>마감만</option>
		</select>
		<input type="search" name="q" value="<?= h($q) ?>"
		       placeholder="제목 · 방장 · 코드 검색" aria-label="검색어">
		<button class="btn btn-sm" type="submit">찾기</button>
		<?php if ($filtered): ?>
			<a class="btn btn-ghost btn-sm" href="<?= base_url('admin') ?>">초기화</a>
		<?php endif; ?>
		<span class="adm-count num"><?= $shown ?>개<?= $shown >= 100 ? ' (최근 100개까지)' : '' ?></span>
	</form>

	<?php if ($shown === 0): ?>

		<?php if ($q !== ''): ?>
			<div class="empty">
				<h2>"<?= h($q) ?>" 에 걸리는 방이 없습니다</h2>
				<p>
					제목·방장 이름·방 코드만 찾습니다. 코드는 대소문자를 가리지 않지만
					<b>중간 일부</b>로도 찾으니 철자를 줄여 다시 시도해 보세요.
				</p>
				<a class="btn btn-ember" href="<?= base_url('admin') ?>">조건 지우고 전체 보기</a>
			</div>
		<?php elseif ($status !== ''): ?>
			<div class="empty">
				<h2><?= $status === 'open' ? '진행 중인 방이 없습니다' : '마감된 방이 없습니다' ?></h2>
				<p>
					<?= $status === 'open'
						? '만들어진 방은 모두 마감되었습니다.'
						: '아직 마감한 방이 없습니다. 방장이 직접 마감하거나 마감시각이 지나야 마감으로 바뀝니다.' ?>
				</p>
				<a class="btn btn-ember" href="<?= base_url('admin') ?>">전체 보기</a>
			</div>
		<?php else: ?>
			<div class="empty">
				<h2>아직 만들어진 투표방이 없습니다</h2>
				<p>
					추천 결과에서 후보를 담아 투표방을 만들면 이 목록에 쌓입니다.
				</p>
				<a class="btn btn-ember" href="<?= base_url() ?>">추천 화면 열기</a>
			</div>
		<?php endif; ?>

	<?php else: ?>

		<div class="adm-scroll">
			<table class="adm-table">
				<thead>
					<tr>
						<th>코드</th>
						<th>제목</th>
						<th>방장</th>
						<th>방식</th>
						<th>상태</th>
						<th>참여</th>
						<th>1위</th>
						<th>후보</th>
						<th>마감</th>
						<th>생성</th>
						<th><span class="adm-sub">이동</span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rooms as $r): ?>
						<?php
						$code    = (string) $r['code'];
						$detail  = base_url('admin/room/' . rawurlencode($code));
						$open    = ($r['status'] === 'open');
						$invite  = ($r['mode'] === 'invite');
						$voted   = (int) $r['voted_count'];
						$turnout = $r['turnout'];

						// 참여율의 분모. invite 는 명단, open 은 방장이 적은 참석 인원.
						$base    = $invite ? (int) $r['invited_count'] : (int) $r['headcount'];
						$overdue = ($open && ! empty($r['deadline_at']) && strtotime($r['deadline_at']) < $now);
						?>
						<tr>
							<td class="adm-nowrap">
								<a class="adm-code" href="<?= $detail ?>"><?= h($code) ?></a>
							</td>

							<td>
								<a class="adm-title" href="<?= $detail ?>"><?= h($r['title']) ?></a>
							</td>

							<td class="adm-nowrap"><?= h($r['host_nick']) ?></td>

							<td class="adm-nowrap">
								<?php if ($invite): ?>
									<span class="tag adm-tag-invite">명단</span>
								<?php else: ?>
									<span class="tag">누구나</span>
								<?php endif; ?>
							</td>

							<td class="adm-nowrap">
								<span class="adm-st <?= $open ? 'adm-st-open' : 'adm-st-closed' ?>">
									<?= $open ? '진행 중' : '마감' ?>
								</span>
							</td>

							<td class="adm-turn">
								<?php if ($turnout === NULL): ?>
									<span class="adm-turn-top">
										<span class="num"><?= $voted ?>명</span>
										<span class="pct">-</span>
									</span>
									<span class="adm-sub">참석 인원 미입력</span>
								<?php else: ?>
									<span class="adm-turn-top">
										<span class="num"><?= $voted ?>/<?= $base ?></span>
										<span class="pct num"><?= (int) $turnout ?>%</span>
									</span>
									<span class="adm-bar">
										<span class="adm-bar-fill<?= $turnout >= 100 ? ' full' : '' ?>"
										      style="width:<?= min(100, max(0, (int) $turnout)) ?>%"></span>
									</span>
								<?php endif; ?>
							</td>

							<td>
								<?php if (empty($r['leader'])): ?>
									<span class="adm-sub">표 없음</span>
								<?php else: ?>
									<span class="adm-lead"><?= h($r['leader']['name'] !== '' ? $r['leader']['name'] : '이름 없음') ?></span>
									<span class="adm-sub num"><?= (int) $r['leader']['votes'] ?>표</span>
								<?php endif; ?>
							</td>

							<td class="adm-nowrap num"><?= (int) $r['option_count'] ?></td>

							<td class="adm-nowrap">
								<?php if (empty($r['deadline_at'])): ?>
									<span class="adm-sub">없음</span>
								<?php else: ?>
									<span class="num"><?= h(date('n/j H:i', strtotime($r['deadline_at']))) ?></span>
									<?php if ($overdue): ?>
										<span class="adm-over">지남</span>
									<?php endif; ?>
								<?php endif; ?>
							</td>

							<td class="adm-nowrap">
								<span class="num"><?= h(date('y.n.j', strtotime($r['created_at']))) ?></span>
								<span class="adm-sub"><?= h(ds_time_ago($r['created_at'])) ?></span>
							</td>

							<td class="adm-nowrap">
								<a class="btn btn-ghost btn-sm" href="<?= base_url('vote/r/' . rawurlencode($code)) ?>">투표방</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<p class="adm-note" style="margin:14px 0 60px">
			코드나 제목을 누르면 그 방의 상세 현황(누가 무엇을 골랐는지, 아직 안 한 사람)이 열립니다.
			<b>참석 인원 미입력</b>은 누구나 참여 방식에서 방장이 참석 인원을 적지 않은 방입니다 —
			분모가 없어 참여율을 낼 수 없습니다.
		</p>

	<?php endif; ?>
</div>
