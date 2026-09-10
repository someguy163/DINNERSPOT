<?php
/**
 * 지역 2단 선택 (시/도 → 역·상권)
 *
 * 필요한 변수
 *   $meta          form_meta() 결과 (area_groups 사용)
 *   $sel_area_id   현재 선택된 t_areas.id (없으면 0)
 *   $sel_coords    TRUE 면 "지도에서 지정한 위치" 를 선택한 상태로 렌더한다.
 *                  sel_area_id=0 은 "아직 안 골랐다"(홈 기본값)와
 *                  "지도로 지점을 직접 찍었다" 두 가지 뜻이 되므로 구분이 필요하다.
 *   $uid           같은 페이지에 두 번 렌더될 때 구분할 접두사
 *   $suffix        빈칸 뒤에 붙는 조사("근처에서"). 역 select 과 한 덩어리(.nw)로 묶는다
 *
 * 역 <option> 은 전부 출력하고 data-sido 로 표시 여부만 JS 가 조절한다.
 * JS 가 죽어도 전체 목록에서 고를 수 있다.
 *
 * 두 select 에 붙은 .js-searchable 은 select-search.js 가 읽는다. 그 스크립트가
 * 검색칸이 달린 드롭다운을 씌우고, 고른 값은 이 <select> 에 그대로 써서
 * change 를 쏜다 — 그래서 app.js 의 sync()/releasePoint 와 폼 제출은 손대지
 * 않는다. JS 가 죽으면 씌우지 않으므로 원래 select 가 그대로 남는다.
 *
 * data-search-cross="1" (역 select) 은 "검색할 때는 시/도 필터를 무시하고
 * 전체에서 훑으라" 는 뜻이다 — 신촌역이 어느 시/도인지 모르는 채로 찾는 것이
 * 검색의 존재 이유다. 이름이 겹치는 경우가 실제로 있어(운천역 = 경기·광주)
 * 검색 결과에는 시/도를 함께 보여준다.
 *
 * 조사는 뷰가 아니라 이 partial 이 묶는다. 두 select 를 통째로 .nw 로 감싸면
 * 역 select 이 가장 긴 역 이름만큼(=200px 남짓) 벌어져 375px 에서 페이지가 가로로 밀린다.
 * 시/도와 역 사이는 끊어지게 두고, "역 select + 조사" 만 nowrap 으로 묶는다.
 */
$groups      = isset($meta['area_groups']) ? $meta['area_groups'] : array();
$sel_area_id = isset($sel_area_id) ? (int) $sel_area_id : 0;
$sel_coords  = ! empty($sel_coords);
$uid         = isset($uid) ? $uid : 'a';
$suffix      = isset($suffix) ? (string) $suffix : '';

// 선택된 역이 속한 시/도를 찾아 초기값으로 쓴다
$sel_sido = '';

foreach ($groups as $g)
{
	foreach ($g['areas'] as $a)
	{
		if ((int) $a['id'] === $sel_area_id)
		{
			$sel_sido = $g['sido'];
			break 2;
		}
	}
}

if ($sel_sido === '' && ! empty($groups))
{
	$sel_sido = $groups[0]['sido'];
}
?>
<span class="blank">
	<select class="js-sido js-searchable" id="<?= h($uid) ?>-sido" data-target="<?= h($uid) ?>-area"
	        data-search-label="시/도 검색" aria-label="시/도">
		<?php foreach ($groups as $g): ?>
			<option value="<?= h($g['sido']) ?>" <?= ($g['sido'] === $sel_sido) ? 'selected' : '' ?>>
				<?= h($g['sido']) ?> (<?= (int) $g['count'] ?>)
			</option>
		<?php endforeach; ?>
	</select>
</span>
<span class="nw"><span class="blank">
	<select name="area_id" id="<?= h($uid) ?>-area" class="js-area js-searchable"
	        data-search-label="역 이름으로 검색" data-search-cross="1" aria-label="역 · 상권">
		<?php
		// "내 위치" 나 지도로 지점을 직접 찍으면 더 이상 "역" 을 검색하는 게
		// 아니라 한 지점을 검색하는 것이므로 area_id 를 0 으로 비워야 한다.
		// resolve_origin() 이 area_id 를 좌표보다 **먼저** 보기 때문에,
		// 역을 고른 채로 좌표만 넣으면 좌표가 조용히 무시된다
		// (실측: area_id=1 + lat/lng=시청 -> origin 이 강남역으로 나온다).
		// 그 상태를 사용자에게 보여줄 자리가 필요해서 이 항목을 둔다.
		// data-any 가 붙은 항목은 시/도 필터가 숨기지 않는다.
		//
		// 안 쓰는 동안은 hidden 만으로 모자라 **disabled** 까지 걸어야 한다.
		// 브라우저는 selected 가 없는 select 에서 "비활성이 아닌 첫 option" 을
		// 기본값으로 잡고 hidden 은 그 판정에 영향을 주지 않는다. 이 항목이
		// 목록 맨 앞이라, 홈 폼의 기본 기준점이 첫 역(강남역)에서 value="0"
		// 으로 바뀌어 있었다 — JS 가 살아 있을 때는 app.js 의 sync() 가
		// 되돌려 주지만, JS 가 꺼지거나 실패하면 기본 검색이 '전체' 가 된다
		// (실측: DOMParser 로 홈 HTML 을 파싱하면 f-area.value === '0',
		//  실제 페이지에서는 '1'). disabled 인 option 이 선택되면 폼은 그
		//  select 를 아예 보내지 않으므로(area_id 없음 = 0) 이 상태로 제출돼도
		//  좌표가 이기는 쪽으로 안전하게 실패한다.
		// 좌표를 채우는 app.js / map-picker.js 는 hidden 과 함께 disabled 도 푼다.
		?>
		<option value="0" data-any="1" id="<?= h($uid) ?>-area-point"
		        <?= ($sel_area_id === 0 && $sel_coords) ? 'selected' : 'hidden disabled' ?>>
			직접 지정한 위치 (내 위치 · 지도)
		</option>
		<?php foreach ($groups as $g): ?>
			<?php foreach ($g['areas'] as $a): ?>
				<?php
				// geo_verified 는 출처 추적용 플래그다. 좌표가 들어있으면 거리 계산은
				// 정상이므로 사용자에게 굳이 알리지 않는다.
				// 좌표가 아예 없는(0) 경우만 거리 점수가 빠지므로 그때만 표시한다.
				$no_geo = (empty($a['lat']) OR empty($a['lng']));
				?>
				<option value="<?= (int) $a['id'] ?>"
				        data-sido="<?= h($g['sido']) ?>"
				        data-name="<?= h($a['name']) ?>"
				        data-lat="<?= h($a['lat']) ?>"
				        data-lng="<?= h($a['lng']) ?>"
				        <?= ((int) $a['id'] === $sel_area_id) ? 'selected' : '' ?>
				        <?= ($g['sido'] !== $sel_sido) ? 'hidden' : '' ?>>
					<?= h($a['name']) ?><?= $no_geo ? ' (좌표 없음)' : '' ?>
				</option>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</select>
</span><?= ($suffix !== '') ? ' ' . h($suffix) : '' ?></span>
