<?php
$addr = $place['road_address'] ?: $place['address'];
$has_geo = ( ! empty($place['lat']) && ! empty($place['lng']));
?>
<div class="wrap" style="max-width:820px">
	<section class="place-hero">
		<p style="margin:0 0 6px;color:var(--muted);font-size:14px">
			<a href="javascript:history.back()">← 결과로 돌아가기</a>
		</p>
		<h1><?= h($place['name']) ?></h1>
		<p class="crumbs">
			<span><?= h($cat ? $cat['emoji'] . ' ' . $cat['label'] : '기타') ?></span>
			<?php if ($place['category_raw']): ?>
				<span class="sep">·</span><span><?= h($place['category_raw']) ?></span>
			<?php endif; ?>
			<?php if (strpos((string) $place['memo'], '샘플') !== FALSE): ?>
				<span class="tag tag-sample">샘플 데이터</span>
			<?php endif; ?>
		</p>

		<div style="display:flex;gap:8px;margin-top:18px;flex-wrap:wrap">
			<a class="btn btn-ember" target="_blank" rel="noopener"
			   href="https://map.naver.com/p/search/<?= rawurlencode($place['name'] . ' ' . $addr) ?>">
				네이버 지도에서 열기
			</a>
			<?php if ($place['phone']): ?>
				<a class="btn btn-ghost" href="tel:<?= h($place['phone']) ?>"><?= h($place['phone']) ?></a>
			<?php endif; ?>
			<?php if ($place['homepage']): ?>
				<a class="btn btn-ghost" href="<?= h($place['homepage']) ?>" target="_blank" rel="noopener">홈페이지</a>
			<?php endif; ?>
		</div>
	</section>

	<?php if ($has_geo && $map_key !== ''): ?>

		<div id="map" data-lat="<?= h($place['lat']) ?>" data-lng="<?= h($place['lng']) ?>"
		     data-name="<?= h($place['name']) ?>" style="margin-bottom:22px"></div>
		<p class="notice" id="map-fallback" hidden style="margin-top:0">
			네이버 지도 인증에 실패해 지도를 표시하지 못했습니다.
			<a href="<?= base_url('guide') ?>">설정</a>에서 Key ID와
			클라우드 플랫폼 콘솔의 <b>Dynamic Map 활성화</b>·<b>Web 서비스 URL에 <code>http://localhost</code> 등록</b>을
			확인하세요.
		</p>

	<?php elseif ($has_geo): ?>

		<!-- 좌표는 있는데 지도 키가 없는 경우.
		     아무것도 안 그리면 "왜 지도가 없지?" 로 보이므로 자리를 남기고 이유를 밝힌다. -->
		<div class="map-empty">
			<p class="map-empty-title">지도 키가 설정되지 않았습니다</p>
			<p class="map-empty-sub">
				좌표는 있습니다 (<span class="num"><?= h(number_format((float) $place['lat'], 5)) ?>,
				<?= h(number_format((float) $place['lng'], 5)) ?></span>) —
				<a href="<?= base_url('guide') ?>">설정</a>에서 지도용 Key ID를 넣으면 여기에 지도가 그려집니다.
				지금은 위의 <b>네이버 지도에서 열기</b>로 확인하세요.
			</p>
		</div>

	<?php endif; ?>

	<div class="panel">
		<h2>기본 정보</h2>
		<ul class="spec">
			<li><span class="k">주소</span><span><?= h($addr ?: '정보 없음') ?></span></li>
			<li><span class="k">1인 예상</span><span><?= h(ds_won($place['avg_price'])) ?></span></li>
			<li><span class="k">수용 인원</span><span><?= ((int) $place['max_party'] > 0) ? ('최대 ' . (int) $place['max_party'] . '명') : '정보 없음' ?></span></li>
			<li><span class="k">평점</span><span>
				<?php if ((float) $place['rating'] > 0): ?>
					<?= number_format((float) $place['rating'], 1) ?> · 리뷰 <?= number_format((int) $place['review_count']) ?>
				<?php else: ?>
					정보 없음
				<?php endif; ?>
			</span></li>
			<li><span class="k">편의</span><span>
				<?php
				$am = array();
				if ($place['has_room'])    $am[] = '룸 · 단체석';
				if ($place['has_parking']) $am[] = '주차';
				if ($place['open_late'])   $am[] = '늦게까지 영업';
				echo h($am ? implode(' · ', $am) : '정보 없음');
				?>
			</span></li>
			<?php if ($place['tags']): ?>
				<li><span class="k">태그</span><span><?= h(str_replace(',', ' · ', $place['tags'])) ?></span></li>
			<?php endif; ?>
			<li><span class="k">출처</span><span>
				<?= $place['source'] === 'naver' ? '네이버 지역검색' : '직접 등록' ?>
				<?php if ($place['synced_at']): ?>
					· <?= h(date('Y-m-d', strtotime($place['synced_at']))) ?> 갱신
				<?php endif; ?>
			</span></li>
		</ul>

		<?php if ($place['memo']): ?>
			<p class="notice" style="margin-bottom:0"><?= h($place['memo']) ?></p>
		<?php endif; ?>
	</div>

	<div style="margin:22px 0 60px">
		<a class="btn btn-ghost" href="<?= base_url() ?>">다른 곳도 추천받기</a>
	</div>
</div>

<?php if ($has_geo && $map_key !== ''): ?>
<script>
// maps.js 가 인증에 실패하면 이 전역 함수를 호출한다.
// 그냥 두면 지도 자리에 "인증이 실패했습니다" 워터마크 타일이 깔려서
// 깨진 화면처럼 보이므로, 지도를 걷어내고 안내 문구로 바꾼다.
// 스크립트보다 먼저 정의되어 있어야 한다.
function navermap_authFailure() {
	var el = document.getElementById('map');
	var fb = document.getElementById('map-fallback');

	if (el) { el.hidden = true; }
	if (fb) { fb.hidden = false; }
}
</script>
<script src="https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=<?= rawurlencode($map_key) ?>"></script>
<script>
(function () {
	var el = document.getElementById('map');

	if (!el) { return; }

	// 스크립트 자체를 못 불러온 경우(네트워크·차단)도 같은 안내로 처리
	if (typeof naver === 'undefined' || !naver.maps) {
		navermap_authFailure();
		return;
	}

	var pos = new naver.maps.LatLng(parseFloat(el.dataset.lat), parseFloat(el.dataset.lng));
	var map = new naver.maps.Map(el, { center: pos, zoom: 16 });

	new naver.maps.Marker({ position: pos, map: map, title: el.dataset.name });
}());
</script>
<?php endif; ?>
