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
			<h1><?= h($origin['label']) ?> 근처, <?= (int) $result['total'] ?>곳</h1>
			<?php if ( ! empty($origin['sub'])): ?>
				<p class="origin-sub"><?= h($origin['sub']) ?></p>
			<?php endif; ?>
			<p class="crumbs">
				<?php if ($result['total_pages'] > 1): ?>
					<span class="num"><b><?= (int) $result['page'] ?></b> / <?= (int) $result['total_pages'] ?> 페이지</span>
				<?php endif; ?>
				<span><b><?= ((int) $c['headcount'] > 0) ? ((int) $c['headcount'] . '명') : '인원 무관' ?></b></span>
				<span><?= ((int) $c['budget'] > 0)
					? ('1인 <b>' . h(ds_won($c['budget'])) . '</b>')
					: '<b>예산 무관</b>' ?></span>
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
	<?php
	// 좌표를 언제 실어야 하는지가 까다롭다.
	// resolve_origin() 이 lat/lng 를 area 좌표로 덮어쓰기 때문에, 지역으로
	// 검색한 결과에서도 $c['lat'] 에는 그 역의 좌표가 들어있다. 그걸 그대로
	// hidden 으로 다시 실으면 좌표와 area_id 가 늘 함께 날아가고,
	// area_id 가 우선이라 "내 위치" 나 지도 조정이 조용히 무시된다.
	// 그래서 **기준점이 실제로 좌표였을 때만** 싣는다.
	$origin_is_point = ($origin['type'] === 'coords');
	$pt_lat = $origin_is_point ? $origin['lat'] : 0;
	$pt_lng = $origin_is_point ? $origin['lng'] : 0;
	?>
	<form class="sentence" method="get" action="<?= base_url('recommend') ?>" id="edit-form" hidden style="margin-bottom:26px">
		<input type="hidden" name="lat" id="e-lat" value="<?= $origin_is_point ? h($pt_lat) : '' ?>">
		<input type="hidden" name="lng" id="e-lng" value="<?= $origin_is_point ? h($pt_lng) : '' ?>">
		<?php
		// keyword 도 기준점이다. 이 폼에는 검색어 칸이 없어서 다시 싣지 않으면
		// 조건을 한 번 고치는 순간 기준점이 통째로 사라진다
		// (실측: /recommend?keyword=회식 -> '회식 근처 16곳' 에서 '다시 추천' ->
		//  '전체 근처 200곳'). 페이지 링크(ds_criteria_params)는 keyword 를
		// 유지하므로 폼도 같은 목록이어야 한다.
		// 지역을 골라 area_id 가 실리면 resolve_origin() 이 그쪽을 먼저 보므로
		// 기준점은 지역이 되고, keyword 는 업종 의도(keyword_codes)로만 남는다.
		?>
		<input type="hidden" name="keyword" value="<?= h($c['keyword']) ?>">

		<div class="sentence-line">
			<?= $this->load->view('home/_area_picker', array(
				'meta' => $meta, 'sel_area_id' => (int) $c['area_id'],
				'sel_coords' => $origin_is_point, 'uid' => 'e', 'suffix' => '근처에서'), TRUE) ?>
			<span class="nw"><span class="blank no-caret">
				<input type="number" class="w-num" name="headcount" min="0" max="300" value="<?= ((int) $c['headcount'] > 0) ? (int) $c['headcount'] : '' ?>" placeholder="무관"
				       aria-label="인원 (0 이면 인원 무관)" title="0 을 넣으면 인원을 따지지 않습니다">
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

		<?php if ($meta['map_key'] !== ''): ?>
			<p class="tune-map-row">
				<button type="button" class="chip" id="e-btn-map">🗺 지도에서 위치 조정</button>
			</p>
		<?php endif; ?>

		<?= $this->load->view('home/_map_picker', array(
			'meta' => $meta, 'uid' => 'e',
			'init_lat' => $pt_lat, 'init_lng' => $pt_lng), TRUE) ?>

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

	<?php if ((int) $result['total_all'] > 0): ?>
		<?php
		// 목록 내 검색. 조건을 hidden 으로 다시 실어야 GET 제출에서 살아남고,
		// page 는 1 로 되돌린다 — 5페이지에서 걸러내면 결과가 3페이지로
		// 줄어들 수 있고, 그때 5페이지를 요구하면 보고 싶던 것이 안 보인다.
		?>
		<form class="listfind" method="get" action="<?= base_url('recommend') ?>"
		      id="find-form" autocomplete="off" role="search">
			<?= ds_criteria_inputs($c, array('page' => 1, 'find' => NULL)) ?>

			<div class="listfind-box">
				<?php
				// aria-autocomplete/controls/expanded 는 role="combobox" 가 없으면
				// 스크린리더가 목록의 존재를 알리지 않는다. 키보드로 고른 항목은
				// aria-activedescendant 로 알려야 하며 recommend.js 가 갱신한다.
				?>
				<input type="search" name="find" id="find-input"
				       value="<?= h($result['find']) ?>" maxlength="40"
				       placeholder="이 목록에서 찾기 — 상호·주소·업종"
				       aria-label="결과 목록에서 찾기" role="combobox"
				       aria-autocomplete="list" aria-controls="find-ac" aria-expanded="false">
				<button class="btn btn-sm" type="submit">찾기</button>
				<?php if ($result['find'] !== ''): ?>
					<a class="btn btn-ghost btn-sm"
					   href="<?= base_url('recommend') . h(ds_criteria_query($c, array('page' => 1, 'find' => NULL))) ?>">해제</a>
				<?php endif; ?>
			</div>

			<ul class="ac" id="find-ac" role="listbox" hidden></ul>

			<?php if ($result['find'] !== ''): ?>
				<p class="listfind-note">
					<b>“<?= h($result['find']) ?>”</b> 로 걸러낸 결과 —
					전체 <?= (int) $result['total_all'] ?>곳 중 <b><?= (int) $result['total'] ?>곳</b>.
					순위 번호는 걸러내기 전 순위입니다.
				</p>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if (empty($rows)): ?>

		<div class="empty">
			<?php if ($result['find'] !== ''): ?>

				<!-- 조건이 아니라 목록 내 검색어 때문에 비었다. 원인을 헷갈리게
				     말하면 사용자가 엉뚱하게 조건을 넓히게 된다. -->
				<h2>“<?= h($result['find']) ?>” 와 맞는 곳이 없습니다</h2>
				<p>
					조건에 맞는 곳은 <b><?= (int) $result['total_all'] ?>곳</b> 있습니다 —
					찾는 말만 지우면 다시 보입니다.
				</p>
				<a class="btn btn-ember"
				   href="<?= base_url('recommend') . h(ds_criteria_query($c, array('page' => 1, 'find' => NULL))) ?>">찾기 해제</a>

			<?php elseif ($c['strict']): ?>

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
				<span class="rank-no num"><?= str_pad(isset($r['rank_no']) ? $r['rank_no'] : ($result['offset'] + $i + 1), 2, '0', STR_PAD_LEFT) ?></span>

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
						<span class="sep">·</span>
						<a href="<?= h(ds_naver_route_url($r['lat'], $r['lng'], $r['name'])) ?>"
						   target="_blank" rel="noopener">길찾기</a>
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

		<?php
		$pages = (int) $result['total_pages'];
		$cur   = (int) $result['page'];

		if ($pages > 1):
			// 현재 페이지 앞뒤 2개 + 처음/끝은 항상 보인다.
			// 페이지가 30개가 되어도 버튼은 최대 9개다.
			$nums = array(1, $pages);

			for ($n = $cur - 2; $n <= $cur + 2; $n++)
			{
				if ($n >= 1 && $n <= $pages) $nums[] = $n;
			}

			$nums = array_values(array_unique($nums));
			sort($nums);
		?>
		<nav class="pager" aria-label="결과 페이지">
			<?php if ($cur > 1): ?>
				<a class="pg" rel="prev" href="<?= base_url('recommend') . h(ds_criteria_query($c, array('page' => $cur - 1))) ?>">← 이전</a>
			<?php else: ?>
				<span class="pg off">← 이전</span>
			<?php endif; ?>

			<?php $prev = 0; foreach ($nums as $n): ?>
				<?php if ($prev && $n > $prev + 1): ?><span class="pg gap">…</span><?php endif; ?>

				<?php if ($n === $cur): ?>
					<span class="pg on num" aria-current="page"><?= $n ?></span>
				<?php else: ?>
					<a class="pg num" href="<?= base_url('recommend') . h(ds_criteria_query($c, array('page' => $n))) ?>"><?= $n ?></a>
				<?php endif; ?>

				<?php $prev = $n; endforeach; ?>

			<?php if ($cur < $pages): ?>
				<a class="pg" rel="next" href="<?= base_url('recommend') . h(ds_criteria_query($c, array('page' => $cur + 1))) ?>">다음 →</a>
			<?php else: ?>
				<span class="pg off">다음 →</span>
			<?php endif; ?>
		</nav>
		<?php endif; ?>

		<?php
		// "몇 위부터 몇 위까지" 는 **화면에 찍힌 순위 번호**여야 한다.
		// offset 은 걸러낸 목록 안의 자리라서 find 가 걸리면 거짓말이 된다
		// (실측: find=육류 11페이지 -> 행 번호는 179~200 인데 이 줄은
		//  '101~109위 표시' 라고 적었다). rank_no 는 걸러내기 전 순위이고
		// find 가 없으면 offset+1 과 정확히 같으므로 양쪽 다 맞는다.
		$first_no = isset($rows[0]['rank_no']) ? (int) $rows[0]['rank_no'] : ((int) $result['offset'] + 1);
		$last_row = $rows[count($rows) - 1];
		$last_no  = isset($last_row['rank_no']) ? (int) $last_row['rank_no'] : ((int) $result['offset'] + count($rows));
		?>
		<p class="result-foot">
			후보 <?= (int) $result['candidate_cnt'] ?>곳 중 <?= (int) $result['total'] ?>곳 순위 ·
			<?= $first_no ?>~<?= $last_no ?>위 표시 ·
			<?= (int) $result['elapsed_ms'] ?>ms
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

/* 담은 후보를 어느 검색에 묶어둘지 정하는 지문.
   쿼리스트링을 그대로 쓰면 안 된다 — 폼이 GET 으로 보낸 주소
   (lat=&lng=&categories[]=bbq)와 페이지 링크가 만든 주소
   (categories[0]=bbq&per_page=10)가 글자로는 다르기 때문에
   2페이지로 넘어가는 순간 "다른 검색" 으로 판정돼 선택이 사라진다.
   정규화된 조건에서 서버가 만들어 내려준다.
   page·per_page·find 는 제외한다 — 페이지를 넘기거나 목록에서 걸러보는 것은
   같은 검색 안에서의 일이라 담아둔 후보가 사라져선 안 된다. */
window.DS_TRAY_SIG = <?= json_encode(md5(ds_criteria_query($c, array('per_page' => 0, 'find' => NULL)))) ?>;

/* 자동완성 목록. 이 페이지의 10건이 아니라 **전체 순위**를 담는다 —
   목록에서 찾는 기능이 보이는 것만 훑으면 쓸모가 없다.
   전체를 걸러내는 것은 서버가 하고(find), 제안만 여기서 즉시 띄운다.

   JSON_HEX_TAG 가 필수다. 이 JSON 은 상호명을 **그대로** 담고 인라인
   스크립트 안에 놓이는데, json_encode 는 슬래시만 이스케이프하고 꺾쇠는
   날것으로 내보낸다. 상호명에 HTML 주석 시작 기호 다음 스크립트 시작
   태그가 들어오면 HTML 토크나이저가 script data double escaped 상태로
   들어가 이 블록의 진짜 닫는 태그를 종료로 보지 않는다 — 아래
   recommend.js 태그부터 footer·문서 끝까지가 스크립트 본문으로 먹혀
   화면이 반쪽만 남고 트레이·자동완성이 아예 로드되지 않는다
   (실측: 그런 상호명 한 건으로 재현). 상호명은 네이버 title 에서 오는데
   ds_strip_naver_tag() 가 strip_tags 뒤에 html_entity_decode 를 하므로
   엔티티로 인코딩된 꺾쇠가 날것으로 복원되어 실제로 도달한다.

   **이 블록에는 꺾쇠 태그 문자열을 쓰지 마라.** 주석 안이라도 HTML
   파서에는 그냥 스크립트 본문이라 닫는 태그가 여기서 먹힌다
   (실제로 이 주석을 처음 쓸 때 그렇게 깨뜨렸다). */
window.DS_FIND_INDEX = <?= json_encode($result['index'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script defer src="<?= ds_asset('js/recommend.js') ?>"></script>
