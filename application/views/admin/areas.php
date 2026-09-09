<?php
/**
 * 지역 사전(t_areas) 현황 — 읽기 전용.
 *
 * $stats       area_stats() : total/active/inactive/pending/stations/districts/
 *                             src_naver/src_url/src_none/sido[{sido,total,pending}]
 * $pending     areas_pending_geo() : 좌표 미확인 행 목록
 * $groups      areas_grouped() : [{sido, count, areas[]}]
 * $naver_ready 네이버 지역검색 키가 있는지
 * $geo_result  좌표 보정 실행 결과 (flashdata)
 *
 * 추가/수정 폼이 없는 이유는 컨트롤러 주석에 있다 — 정본은 sql/02_seed.sql 이고
 * 화면에서 DB만 고치면 시드 재실행 때 조용히 덮어써진다.
 */
$total   = (int) $stats['total'];
$pending_n = (int) $stats['pending'];
?>
<div class="wrap">

	<div class="adm-head">
		<div>
			<h1>지역 사전</h1>
			<p>추천 화면의 지역 선택에 쓰이는 역·상권 목록입니다. 좌표는 거리 점수의 기준입니다.</p>
		</div>
		<a class="btn btn-ghost btn-sm" href="<?= base_url('admin') ?>">투표 현황</a>
	</div>

	<?php if ($geo_result): ?>
		<p class="notice notice-soju"><?= h($geo_result) ?></p>
	<?php endif; ?>

	<!-- 요약 -->
	<dl class="adm-sum">
		<div class="adm-sum-cell">
			<dt>등록된 지역</dt>
			<dd>
				<span class="adm-sum-big num"><?= $total ?></span>
				<span class="adm-sum-sub">
					<span>역 <b class="num"><?= (int) $stats['stations'] ?></b></span>
					<span>상권 <b class="num"><?= (int) $stats['districts'] ?></b></span>
					<?php if ((int) $stats['inactive'] > 0): ?>
						<span>숨김 <b class="num"><?= (int) $stats['inactive'] ?></b></span>
					<?php endif; ?>
				</span>
			</dd>
		</div>
		<div class="adm-sum-cell">
			<dt>좌표 미확인</dt>
			<dd>
				<span class="adm-sum-big num"><?= $pending_n ?></span>
				<span class="adm-sum-sub">
					<?php if ($pending_n === 0): ?>
						<span>전부 확인됨</span>
					<?php else: ?>
						<span>거리 점수가 중립(55)으로 처리됩니다</span>
					<?php endif; ?>
				</span>
			</dd>
		</div>
		<div class="adm-sum-cell">
			<dt>좌표 출처</dt>
			<dd>
				<span class="adm-sum-big num"><?= (int) $stats['src_url'] ?></span>
				<span class="adm-sum-sub">
					<span>출처 URL 기재</span>
					<span>네이버 보정 <b class="num"><?= (int) $stats['src_naver'] ?></b></span>
					<span>없음 <b class="num"><?= (int) $stats['src_none'] ?></b></span>
				</span>
			</dd>
		</div>
	</dl>

	<!-- 좌표 보정 -->
	<div class="panel">
		<h2>좌표 보정</h2>

		<?php if ($pending_n === 0): ?>
			<p class="adm-note" style="margin-bottom:0">
				좌표가 비어 있는 지역이 없습니다. 새 지역을 <code>lat=0, lng=0</code> 으로 넣으면
				여기에 나타나고 이 버튼으로 채울 수 있습니다.
			</p>
		<?php else: ?>
			<p class="adm-note">
				<b class="num"><?= $pending_n ?></b>곳의 좌표가 비어 있습니다.
				네이버 지역검색으로 실제 좌표를 채웁니다 — 추측값을 넣지 않으므로
				검색이 못 찾는 이름은 그대로 남습니다.
			</p>

			<?php if ($naver_ready): ?>
				<form method="post" action="<?= base_url('admin/areas/geocode') ?>">
					<button class="btn btn-soju btn-sm" type="submit">네이버로 좌표 보정 (30곳씩)</button>
				</form>
			<?php else: ?>
				<p class="notice" style="margin-bottom:0">
					네이버 지역검색 키가 없어 보정을 실행할 수 없습니다.
					<a href="<?= base_url('guide') ?>">설정 화면</a>에서 키를 넣으세요.
				</p>
			<?php endif; ?>

			<div class="adm-scroll" style="margin-top:14px">
				<table class="adm-table adm-table-tight">
					<thead>
						<tr>
							<th>이름</th>
							<th>시/도</th>
							<th>시/군/구</th>
							<th>종류</th>
							<th>노선</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($pending as $p): ?>
							<tr>
								<td class="adm-nowrap"><b><?= h($p['name']) ?></b></td>
								<td class="adm-nowrap"><?= h($p['sido']) ?></td>
								<td class="adm-nowrap"><?= h($p['sigungu']) ?></td>
								<td class="adm-nowrap">
									<span class="tag"><?= $p['kind'] === 'station' ? '역' : '상권' ?></span>
								</td>
								<td class="adm-sub"><?= h($p['line_info']) ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if (count($pending) >= 200): ?>
				<p class="adm-note" style="margin:10px 0 0">최근 200곳까지만 표시합니다.</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<!-- 지역을 늘리거나 고치는 방법 -->
	<div class="panel">
		<h2>지역을 추가하거나 고치려면</h2>
		<p class="adm-note">
			이 화면에는 추가·수정 폼이 없습니다. 기준 데이터의 정본은
			<code>sql/02_seed.sql</code> 이고, 화면에서 DB만 고치면 시드를 다시 넣는 순간
			<code>ON DUPLICATE KEY UPDATE</code> 가 그 값을 덮어써 조용히 사라집니다.
			그래서 고치는 곳을 파일 한 곳으로 묶어 두었습니다.
		</p>
		<ol class="adm-note" style="margin:0;padding-left:20px;line-height:1.9">
			<li><code>sql/02_seed.sql</code> 의 <b>“직접 추가하는 지역”</b> 블록에 한 줄 추가</li>
			<li>좌표를 모르면 <code>0, 0, 0, ''</code> 로 두기 (추측값 금지)</li>
			<li><code>mysql -uroot --default-character-set=utf8mb4 dinnerspot &lt; sql/02_seed.sql</code></li>
			<li>이 화면으로 돌아와 <b>좌표 보정</b> 실행</li>
		</ol>
		<p class="adm-note" style="margin:12px 0 0">
			공공데이터 CSV 로 한꺼번에 늘리려면 <code>tools/areas_from_csv.php</code> 가
			붙여넣을 SQL 을 만들어 줍니다. 자세한 절차는 <code>README.md</code> 의
			“지역 정보 갱신” 절에 있습니다.
		</p>
	</div>

	<!-- 시/도별 -->
	<div class="panel">
		<h2>시/도별</h2>
		<div class="adm-scroll">
			<table class="adm-table adm-table-tight">
				<thead>
					<tr>
						<th>시/도</th>
						<th>지역 수</th>
						<th>좌표 미확인</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($stats['sido'] as $s): ?>
						<tr>
							<td class="adm-nowrap"><b><?= h($s['sido']) ?></b></td>
							<td class="adm-nowrap num"><?= (int) $s['total'] ?></td>
							<td class="adm-nowrap">
								<?php if ((int) $s['pending'] === 0): ?>
									<span class="adm-sub">없음</span>
								<?php else: ?>
									<span class="adm-st adm-st-pend num"><?= (int) $s['pending'] ?>곳</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- 전체 목록 -->
	<div class="panel">
		<h2>전체 목록</h2>
		<p class="adm-note">시/도를 눌러 펼칩니다.</p>

		<?php foreach ($groups as $g): ?>
			<details>
				<summary style="cursor:pointer;padding:9px 0;font-weight:800;letter-spacing:-.02em;border-bottom:1px solid var(--steel)">
					<?= h($g['sido'] !== '' ? $g['sido'] : '기타') ?>
					<span class="adm-sub num"><?= (int) $g['count'] ?>곳</span>
				</summary>
				<div class="adm-scroll" style="margin:12px 0 18px">
					<table class="adm-table adm-table-tight">
						<thead>
							<tr>
								<th>이름</th>
								<th>시/군/구</th>
								<th>종류</th>
								<th>노선</th>
								<th>좌표</th>
								<th>출처</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($g['areas'] as $a): ?>
								<?php $has_geo = ((float) $a['lat'] !== 0.0 || (float) $a['lng'] !== 0.0); ?>
								<tr>
									<td class="adm-nowrap"><b><?= h($a['name']) ?></b></td>
									<td class="adm-nowrap"><?= h($a['sigungu']) ?></td>
									<td class="adm-nowrap">
										<span class="tag"><?= $a['kind'] === 'station' ? '역' : '상권' ?></span>
									</td>
									<td class="adm-sub"><?= h($a['line_info']) ?></td>
									<td class="adm-nowrap num">
										<?php if ($has_geo): ?>
											<?= h(number_format((float) $a['lat'], 5)) ?>,
											<?= h(number_format((float) $a['lng'], 5)) ?>
										<?php else: ?>
											<span class="adm-st adm-st-pend">미확인</span>
										<?php endif; ?>
									</td>
									<td class="adm-sub">
										<?php if ($a['geo_source'] === ''): ?>
											&mdash;
										<?php elseif (strpos($a['geo_source'], 'http') === 0): ?>
											<a href="<?= h($a['geo_source']) ?>" target="_blank" rel="noopener noreferrer">출처</a>
										<?php else: ?>
											<?= h($a['geo_source']) ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</details>
		<?php endforeach; ?>
	</div>

</div>
