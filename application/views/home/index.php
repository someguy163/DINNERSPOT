<?php
$d = $meta['defaults'];

// 목적별 설명. '데이트' 는 음식 종류가 아니라 목적이라, 음식 칩에서 찾으면 없다.
// 선택지마다 설명을 붙이고(title) 문장 아래에 한 줄로 노출해 그 오해를 막는다.
$purpose_desc = array();

foreach ($meta['purposes'] as $p)
{
	$purpose_desc[$p['code']] = $p['desc'];
}
?>
<section class="hero">
	<div class="wrap">
		<h1>오늘 회식,<br>어디로 갈지 정해드립니다.</h1>
		<p>인원과 예산, 목적만 고르면 후보를 점수순으로 뽑아줍니다. 마음에 드는 곳을 담아 팀에 링크를 보내면 투표까지 끝납니다.</p>

		<form class="sentence" method="get" action="<?= base_url('recommend') ?>" id="search-form">
			<input type="hidden" name="lat" id="f-lat" value="">
			<input type="hidden" name="lng" id="f-lng" value="">

			<div class="sentence-line">
				<?= $this->load->view('home/_area_picker', array(
					'meta' => $meta, 'sel_area_id' => 0, 'uid' => 'f', 'suffix' => '근처에서'), TRUE) ?><br>

				<span class="nw"><span class="blank no-caret">
					<input type="number" class="w-num" name="headcount" id="f-head"
					       min="1" max="300" value="<?= (int) $d['headcount'] ?>" aria-label="인원">
				</span> 명이,</span> 1인당
				<span class="nw"><span class="blank">
					<select name="budget" aria-label="1인 예산">
						<?php foreach ($meta['budget_presets'] as $p): ?>
							<option value="<?= (int) $p['value'] ?>" <?= ((int) $p['value'] === (int) $d['budget']) ? 'selected' : '' ?>>
								<?= h($p['label']) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</span> 으로</span><br>

				<span class="nw"><span class="blank">
					<select name="purpose" aria-label="목적">
						<?php foreach ($meta['purposes'] as $p): ?>
							<option value="<?= h($p['code']) ?>" title="<?= h($p['desc']) ?>"><?= h($p['label']) ?></option>
						<?php endforeach; ?>
					</select>
				</span> 할</span> 만한 곳을 찾습니다.
			</div>

			<?php if (isset($purpose_desc['date'])): ?>
				<p style="margin:14px 0 0;color:var(--muted-2);font-size:14px;font-weight:500;line-height:1.65">
					<b>목적</b>은 음식 종류가 아닙니다 — 아래 음식 칩이 아니라 문장 안에서 고릅니다.
					회식이 아닌 <b>데이트</b>도 여기 있습니다.
					<?= h($purpose_desc['date']) ?>.
				</p>
			<?php endif; ?>

			<dl class="tune">
				<dt>음식</dt>
				<dd>
					<?php foreach ($meta['categories'] as $c): ?>
						<?php if ($c['code'] === 'etc') continue; ?>
						<label class="chip">
							<input type="checkbox" name="categories[]" value="<?= h($c['code']) ?>">
							<?= h($c['emoji']) ?> <?= h($c['label']) ?>
						</label>
					<?php endforeach; ?>
				</dd>

				<dt>조건</dt>
				<dd>
					<label class="chip"><input type="checkbox" name="need_room" value="1"> 룸 · 단체석</label>
					<label class="chip"><input type="checkbox" name="need_parking" value="1"> 주차</label>
					<label class="chip"><input type="checkbox" name="need_late" value="1"> 늦게까지</label>
					<label class="chip"><input type="checkbox" name="strict" value="1"> 조건 안 맞으면 빼기</label>
				</dd>

				<dt>거리</dt>
				<dd>
					<?php foreach ($meta['radius_options'] as $i => $r): ?>
						<label class="chip">
							<input type="radio" name="radius" value="<?= (int) $r ?>" <?= ((int) $r === (int) $d['radius']) ? 'checked' : '' ?>>
							<?= $r >= 1000 ? (($r / 1000) . 'km') : ($r . 'm') ?>
						</label>
					<?php endforeach; ?>
					<button type="button" class="chip" id="btn-geo">📍 내 위치 기준</button>
					<?php if ($meta['map_key'] !== ''): ?>
						<button type="button" class="chip" id="f-btn-map"
						        aria-expanded="false" aria-controls="f-mappick">🗺 지도에서 조정</button>
					<?php endif; ?>
				</dd>
			</dl>

			<?= $this->load->view('home/_map_picker',
				array('meta' => $meta, 'uid' => 'f', 'init_lat' => 0, 'init_lng' => 0), TRUE) ?>

			<div class="submit-row">
				<button type="submit" class="btn btn-ember btn-lg">추천 받기</button>
				<span style="color:var(--muted);font-size:14px" id="geo-status"></span>
			</div>
		</form>

		<?php if ( ! $meta['naver_enabled']): ?>
			<p class="notice">
				지금은 <b>샘플 장소</b>로만 추천합니다.
				네이버 검색 API 키를 넣으면 실제 상권 데이터가 함께 쌓입니다 —
				<a href="<?= base_url('guide') ?>">설정 방법 보기</a>
			</p>
		<?php endif; ?>
	</div>
</section>

<?php if ( ! empty($recent)): ?>
<section class="wrap" style="margin-top:34px">
	<div class="panel">
		<h2>최근 투표에 자주 올라간 곳</h2>
		<ul class="rank-list" style="border-top:1px solid var(--steel)">
			<?php foreach ($recent as $r): ?>
			<li class="rank" style="grid-template-columns:34px 1fr auto;padding:14px 0">
				<span class="rank-no" style="font-size:20px"><?= h($r['category_emoji']) ?></span>
				<div>
					<p class="rank-name" style="font-size:16px">
						<a href="<?= base_url('place/' . (int) $r['id']) ?>"><?= h($r['name']) ?></a>
					</p>
					<p class="rank-meta">
						<span><?= h($r['category_label']) ?></span>
						<span class="sep">·</span>
						<span><?= h($r['road_address'] ?: $r['address']) ?></span>
					</p>
				</div>
				<span class="tag">후보 <?= (int) $r['pick_count'] ?>회</span>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
<?php endif; ?>

<section class="wrap" style="margin-top:34px">
	<div class="split">
		<div class="panel">
			<h2>어떻게 고르나요</h2>
			<ul class="spec">
				<li><span class="k">거리</span><span>기준 지점에서 가까울수록. 반경을 벗어나면 급격히 감점합니다.</span></li>
				<li><span class="k">예산</span><span>1인 예상금액이 입력한 예산에 가까울수록. 초과에 더 민감합니다.</span></li>
				<li><span class="k">인원</span><span>수용 인원이 참석 인원보다 여유 있으면 만점.</span></li>
				<li><span class="k">평점</span><span>리뷰 수가 적으면 평균 쪽으로 당겨서 보정합니다.</span></li>
				<li><span class="k">조건</span><span>룸·주차·심야는 고른 것만 채점합니다.</span></li>
				<li><span class="k">목적</span><span>접대는 평점과 룸을, 가성비는 예산을 더 크게 봅니다.
					회식이 아닌 데이트는 인원 비중을 거의 빼고 평점을 크게 보며, 카페·디저트도 후보에 넣습니다.</span></li>
			</ul>
		</div>
		<div class="panel">
			<h2>투표방에 참여하기</h2>
			<p style="color:var(--muted);margin-top:0">받은 코드가 있다면 여기에 넣으세요.</p>
			<form method="get" action="<?= base_url('vote') ?>">
				<div class="field">
					<label for="join-code">투표 코드</label>
					<input type="text" id="join-code" name="code" maxlength="12"
					       placeholder="예: K7M2PQXD" autocomplete="off"
					       style="letter-spacing:.16em;font-weight:800;text-transform:uppercase">
				</div>
				<button class="btn btn-soju" type="submit">참여하기</button>
			</form>
		</div>
	</div>
</section>
