<?php
/**
 * 지역 2단 선택 (시/도 → 역·상권)
 *
 * 필요한 변수
 *   $meta          form_meta() 결과 (area_groups 사용)
 *   $sel_area_id   현재 선택된 t_areas.id (없으면 0)
 *   $uid           같은 페이지에 두 번 렌더될 때 구분할 접두사
 *
 * 역 <option> 은 전부 출력하고 data-sido 로 표시 여부만 JS 가 조절한다.
 * JS 가 죽어도 전체 목록에서 고를 수 있다.
 */
$groups      = isset($meta['area_groups']) ? $meta['area_groups'] : array();
$sel_area_id = isset($sel_area_id) ? (int) $sel_area_id : 0;
$uid         = isset($uid) ? $uid : 'a';

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
	<select class="js-sido" data-target="<?= h($uid) ?>-area" aria-label="시/도">
		<?php foreach ($groups as $g): ?>
			<option value="<?= h($g['sido']) ?>" <?= ($g['sido'] === $sel_sido) ? 'selected' : '' ?>>
				<?= h($g['sido']) ?> (<?= (int) $g['count'] ?>)
			</option>
		<?php endforeach; ?>
	</select>
</span>
<span class="blank">
	<select name="area_id" id="<?= h($uid) ?>-area" class="js-area" aria-label="역 · 상권">
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
				        <?= ((int) $a['id'] === $sel_area_id) ? 'selected' : '' ?>
				        <?= ($g['sido'] !== $sel_sido) ? 'hidden' : '' ?>>
					<?= h($a['name']) ?><?= $no_geo ? ' (좌표 없음)' : '' ?>
				</option>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</select>
</span>
