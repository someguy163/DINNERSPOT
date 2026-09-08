<?php
$c       = $result['criteria'];
$origin  = $result['origin'];
$rows    = $result['results'];
$d       = $meta['defaults'];
$purpose = '';

foreach ($meta['purposes'] as $p)
{
	if ($p['code'] === $c['purpose'])
	{
		$purpose = $p['label'];
	}
}

$cat_labels = array();

foreach ($meta['categories'] as $cat)
{
	if (in_array($cat['code'], $c['categories'], TRUE))
	{
		$cat_labels[] = $cat['label'];
	}
}
?>
<div class="wrap">

	<div class="result-head">
		<div>
			<h1><?= h($origin['label']) ?> 근처, <?= count($rows) ?>곳</h1>
			<p class="crumbs">
				<span><b><?= (int) $c['headcount'] ?>명</b></span>
				<span>1인 <b><?= h(ds_won($c['budget'])) ?></b></span>
				<span>반경 <b><?= $c['radius'] >= 1000 ? (($c['radius'] / 1000) . 'km') : ($c['radius'] . 'm') ?></b></span>
				<span><b><?= h($purpose) ?></b></span>
				<?php if ($cat_labels): ?><span><b><?= h(implode(', ', $cat_labels)) ?></b></span><?php endif; ?>
			</p>
		</div>
		<div style="display:flex;gap:8px">
			<a class="btn btn-ghost btn-sm" href="<?= base_url() ?>">처음부터</a>
			<button class="btn btn-sm" type="button" id="btn-edit">조건 수정</button>
		</div>
	</div>

	<!-- 조건 수정 패널 -->
	<form class="sentence" method="get" action="<?= base_url('recommend') ?>" id="edit-form" hidden style="margin-bottom:26px">
		<input type="hidden" name="lat" value="<?= h($c['lat'] ?: '') ?>">
		<input type="hidden" name="lng" value="<?= h($c['lng'] ?: '') ?>">

		<div class="sentence-line">
			<span class="nw"><?= $this->load->view('home/_area_picker',
				array('meta' => $meta, 'sel_area_id' => (int) $c['area_id'], 'uid' => 'e'), TRUE) ?> 근처에서</span>
			<span class="nw"><span class="blank no-caret">
				<input type="number" class="w-num" name="headcount" min="1" max="300" value="<?= (int) $c['headcount'] ?>" aria-label="인원">
			</span> 명이,</span> 1인당
			<span class="nw"><span class="blank">
				<select name="budget" aria-label="예산">
					<?php foreach ($meta['budget_presets'] as $p): ?>
						<option value="<?= (int) $p['value'] ?>" <?= ((int) $p['value'] === (int) $c['budget']) ? 'selected' : '' ?>><?= h($p['label']) ?></option>
					<?php endforeach; ?>
				</select>
			</span> 으로</span>
			<span class="nw"><span class="blank">
				<select name="purpose" aria-label="목적">
					<?php foreach ($meta['purposes'] as $p): ?>
						<option value="<?= h($p['code']) ?>" <?= ($p['code'] === $c['purpose']) ? 'selected' : '' ?>><?= h($p['label']) ?></option>
					<?php endforeach; ?>
				</select>
			</span> 할</span> 만한 곳
		</div>

		<dl class="tune">
			<dt>음식</dt>
			<dd>
				<?php foreach ($meta['categories'] as $cat): ?>
					<?php if ($cat['code'] === 'etc') continue; ?>
					<label class="chip">
						<input type="checkbox" name="categories[]" value="<?= h($cat['code']) ?>"
						       <?= in_array($cat['code'], $c['categories'], TRUE) ? 'checked' : '' ?>>
						<?= h($cat['emoji']) ?> <?= h($cat['label']) ?>
					</label>
				<?php endforeach; ?>
			</dd>
			<dt>조건</dt>
			<dd>
				<label class="chip"><input type="checkbox" name="need_room" value="1" <?= $c['need_room'] ? 'checked' : '' ?>> 룸 · 단체석</label>
				<label class="chip"><input type="checkbox" name="need_parking" value="1" <?= $c['need_parking'] ? 'checked' : '' ?>> 주차</label>
				<label class="chip"><input type="checkbox" name="need_late" value="1" <?= $c['need_late'] ? 'checked' : '' ?>> 늦게까지</label>
				<label class="chip"><input type="checkbox" name="strict" value="1" <?= $c['strict'] ? 'checked' : '' ?>> 조건 안 맞으면 빼기</label>
			</dd>
			<dt>거리</dt>
			<dd>
				<?php foreach ($meta['radius_options'] as $r): ?>
					<label class="chip">
						<input type="radio" name="radius" value="<?= (int) $r ?>" <?= ((int) $r === (int) $c['radius']) ? 'checked' : '' ?>>
						<?= $r >= 1000 ? (($r / 1000) . 'km') : ($r . 'm') ?>
					</label>
				<?php endforeach; ?>
			</dd>
		</dl>

		<div class="submit-row">
			<button class="btn btn-ember" type="submit">다시 추천</button>
		</div>
	</form>

	<?php if ($origin['type'] === 'keyword'): ?>
		<p class="notice">기준 좌표를 찾지 못해 이름·주소로만 검색했습니다. 거리 점수는 반영되지 않습니다.</p>
	<?php endif; ?>

	<?php if ( ! empty($result['fell_back'])): ?>
		<p class="notice">
			이 지역에서 네이버로 수집된 장소가 없어 <b>직접 등록한 장소</b>로 추천했습니다.
			<?php if ( ! $result['naver_enabled']): ?>
				— <a href="<?= base_url('guide') ?>">네이버 키를 넣으면</a> 실제 상권으로 바뀝니다.
			<?php endif; ?>
		</p>
	<?php elseif ($result['source_mode'] === 'naver'): ?>
		<p class="notice notice-soju">
			<b>네이버 지역검색 기준</b>으로 추천했습니다. 네이버는 가격·평점을 제공하지 않아
			1인 예산과 수용 인원은 업종 평균 추정값이고, 인기도는 <b>네이버 검색 순위</b>로 대체합니다.
		</p>
	<?php elseif ($result['source_mode'] === 'db' && $result['naver_enabled']): ?>
		<p class="notice">직접 등록한 장소만으로 추천했습니다 (<code>source_mode = db</code>).</p>
	<?php endif; ?>

	<?php if ( ! empty($result['naver']['error'])): ?>
		<p class="notice">네이버 검색을 불러오지 못했습니다 (<?= h($result['naver']['error']) ?>). 저장된 데이터로 추천합니다.</p>
	<?php endif; ?>

	<?php if (empty($rows)): ?>

		<div class="empty">
			<?php if ($c['strict']): ?>

				<h2>조건을 다 만족하는 곳이 없습니다</h2>
				<p>‘조건 안 맞으면 빼기’를 끄면 아쉬운 곳까지 점수순으로 보여줍니다.</p>
				<button class="btn btn-ember" type="button" id="btn-edit-2">조건 수정</button>

			<?php elseif ( ! empty($cat_labels)): ?>

				<h2><?= h($origin['label']) ?> 근처에 <?= h(implode(', ', $cat_labels)) ?>가 없습니다</h2>
				<p>음식 종류 선택을 줄이거나 거리를 넓혀보세요.</p>
				<button class="btn btn-ember" type="button" id="btn-edit-2">조건 수정</button>

			<?php elseif ( ! $result['naver_enabled']): ?>

				<!-- 진짜 원인은 조건이 아니라 이 지역 데이터가 없다는 것이다.
				     샘플은 43곳뿐이라 122개 지역 중 상당수가 비어 있다. -->
				<h2><?= h($origin['label']) ?> 데이터가 아직 없습니다</h2>
				<p>
					지금은 <b>샘플 장소 <?= (int) $result['candidate_cnt'] ?: (int) array_sum($meta['source_counts']) ?>곳</b>만 들어있어
					전국을 다 덮지 못합니다. 네이버 검색 API 키를 넣으면
					이 지역 상권이 실제로 채워집니다.
				</p>
				<div style="display:flex;gap:8px;justify-content:center">
					<a class="btn btn-ember" href="<?= base_url('guide') ?>">키 넣는 방법 보기</a>
					<button class="btn btn-ghost" type="button" id="btn-edit-2">다른 지역 고르기</button>
				</div>

			<?php else: ?>

				<h2><?= h($origin['label']) ?> 근처에서 찾지 못했습니다</h2>
				<p>네이버에서 이 지역 장소를 가져오지 못했습니다. 거리를 넓히거나 잠시 후 다시 시도해보세요.</p>
				<button class="btn btn-ember" type="button" id="btn-edit-2">조건 수정</button>

			<?php endif; ?>
		</div>

	<?php else: ?>

		<ol class="rank-list" id="rank-list">
			<?php foreach ($rows as $i => $r): ?>
			<li class="rank" data-id="<?= (int) $r['id'] ?>" data-name="<?= h($r['name']) ?>">
				<span class="rank-no num"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></span>

				<div>
					<h2 class="rank-name">
						<a href="<?= base_url('place/' . (int) $r['id']) ?>"><?= h($r['name']) ?></a>
					</h2>

					<p class="rank-meta">
						<span><?= h($r['category_emoji']) ?> <?= h($r['category_label']) ?></span>
						<?php if ($r['distance_label']): ?>
							<span class="sep">·</span><span><?= h($r['distance_label']) ?></span>
						<?php endif; ?>
						<span class="sep">·</span><span>1인 <?= h($r['price_label']) ?></span>
						<span class="sep">·</span><span><?= h($r['party_label']) ?></span>
						<?php foreach ($r['badges'] as $b): ?>
							<span class="tag"><?= h($b) ?></span>
						<?php endforeach; ?>
						<?php if ($r['is_sample']): ?>
							<span class="tag tag-sample">샘플</span>
						<?php endif; ?>
					</p>

					<?php if ( ! empty($r['reasons'])): ?>
					<ul class="reasons">
						<?php foreach ($r['reasons'] as $why): ?>
							<li><?= h($why) ?></li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>

					<p class="rank-meta" style="margin-top:8px">
						<span><?= h($r['road_address'] ?: $r['address']) ?></span>
						<?php if ($r['phone']): ?>
							<span class="sep">·</span><a href="tel:<?= h($r['phone']) ?>"><?= h($r['phone']) ?></a>
						<?php endif; ?>
						<span class="sep">·</span>
						<a href="<?= h($r['map_url']) ?>" target="_blank" rel="noopener">지도에서 보기</a>
					</p>
				</div>

				<div class="gauge">
					<div class="gauge-top">
						<span class="gauge-score num"><?= (int) round($r['score']) ?></span>
						<span class="gauge-grade <?= h($r['grade_class']) ?>"><?= h($r['grade_label']) ?></span>
					</div>
					<div class="gauge-bar">
						<span class="gauge-fill" data-score="<?= (int) round($r['score']) ?>"></span>
					</div>
					<div class="gauge-pick">
						<label class="pick">
							<input type="checkbox" class="pick-input" value="<?= (int) $r['id'] ?>">
							후보에 담기
						</label>
					</div>
				</div>
			</li>
			<?php endforeach; ?>
		</ol>

		<p style="color:var(--muted-2);font-size:13px;margin:16px 0 40px">
			후보 <?= (int) $result['candidate_cnt'] ?>곳 중 <?= count($rows) ?>곳 · <?= (int) $result['elapsed_ms'] ?>ms
			<?php if ($result['naver']['used']): ?>
				· 네이버 <?= (int) $result['naver']['calls'] ?>회 호출, 신규 <?= (int) $result['naver']['new'] ?>곳 수집
			<?php endif; ?>
		</p>

	<?php endif; ?>
</div>

<!-- 담은 후보 -->
<div class="tray" id="tray">
	<div class="wrap">
		<span class="tray-list" id="tray-list"></span>
		<span style="display:flex;gap:8px">
			<button class="btn btn-ghost btn-sm" type="button" id="tray-clear"
			        style="color:#CFC6BD;border-color:rgba(255,255,255,.24)">비우기</button>
			<a class="btn btn-ember" id="tray-go" href="#">투표 만들기</a>
		</span>
	</div>
</div>

<script>
window.DS_VOTE_MAX = <?= (int) $meta['vote']['max'] ?>;
window.DS_VOTE_MIN = <?= (int) $meta['vote']['min'] ?>;
</script>
<script defer src="<?= ds_asset('js/recommend.js') ?>"></script>
