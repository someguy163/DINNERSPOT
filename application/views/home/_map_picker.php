<?php
/**
 * 지도에서 검색 기준점 조정
 *
 * 필요한 변수
 *   $meta      form_meta() 결과 (map_key, radius_options 사용)
 *   $uid       같은 페이지의 다른 폼과 구분할 접두사 ($area_picker 와 같은 값)
 *   $init_lat  처음 띄울 좌표 (없으면 0 — 그러면 선택된 역 좌표를 쓴다)
 *   $init_lng
 *
 * 지도 키가 없으면 아무것도 출력하지 않는다. 그러면 기존처럼 역 선택과
 * "내 위치 기준" 만으로 검색한다 — 이 기능은 정밀 조정용 덧붙임이다.
 *
 * 지도를 움직이면 area_id 를 0("지도에서 지정한 위치")으로 바꾼다.
 * resolve_origin() 이 area_id 를 좌표보다 먼저 보기 때문에, 역을 고른 채로
 * 좌표만 바꾸면 조정이 조용히 무시된다.
 */
$map_key  = isset($meta['map_key']) ? (string) $meta['map_key'] : '';
$uid      = isset($uid) ? $uid : 'a';
$init_lat = isset($init_lat) ? (float) $init_lat : 0;
$init_lng = isset($init_lng) ? (float) $init_lng : 0;

if ($map_key === '')
{
	return;
}
?>
<div class="mappick" id="<?= h($uid) ?>-mappick" hidden
     data-uid="<?= h($uid) ?>"
     data-lat="<?= h($init_lat) ?>"
     data-lng="<?= h($init_lng) ?>">

	<div class="mappick-map" id="<?= h($uid) ?>-map"></div>

	<p class="mappick-fallback" id="<?= h($uid) ?>-map-fallback" hidden>
		지도를 불러오지 못했습니다. 역을 직접 고르거나 <b>내 위치 기준</b>을 쓰세요 —
		두 방법 모두 지도 없이 동작합니다.
	</p>

	<div class="mappick-bar">
		<p class="mappick-note" id="<?= h($uid) ?>-map-label">
			핀을 끌거나 지도를 눌러 기준점을 옮기세요.
		</p>
		<span class="mappick-acts">
			<button type="button" class="btn btn-ghost btn-sm" id="<?= h($uid) ?>-map-here">📍 내 위치로</button>
			<button type="button" class="btn btn-ghost btn-sm" id="<?= h($uid) ?>-map-reset">역 위치로</button>
		</span>
	</div>
</div>

<script>
/* maps.js 가 인증에 실패하면 이 전역 함수를 호출한다. maps.js 보다
   **먼저** 정의되어 있어야 한다 — map-picker.js 는 defer 라서 늦게 뜬다.
   그냥 두면 지도 자리에 "인증 실패" 워터마크 타일이 깔려 깨진 화면이 된다. */
window.__dsMapAuthFailed = false;

function navermap_authFailure() {
	window.__dsMapAuthFailed = true;

	if (typeof window.__dsMapFail === 'function') { window.__dsMapFail(); }
}
</script>
<script src="https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=<?= rawurlencode($map_key) ?>"></script>
<script defer src="<?= ds_asset('js/map-picker.js') ?>"></script>
