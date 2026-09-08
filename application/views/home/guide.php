<div class="wrap" style="max-width:760px">
	<section class="place-hero">
		<h1>설정</h1>
		<p style="color:var(--muted);margin:0">네이버 API를 연결하면 실제 상권 데이터로 추천합니다.</p>
	</section>

	<?php if ($geo_result): ?>
		<p class="notice notice-soju"><?= h($geo_result) ?></p>
	<?php endif; ?>

	<?php if ($diag_map): ?>
		<div class="notice <?= $diag_map['ok'] ? 'notice-soju' : '' ?>"
		     style="<?= $diag_map['ok'] ? '' : 'border-left-color:var(--danger);background:rgba(194,50,31,.06)' ?>">
			<b>지도 <?= $diag_map['ok'] ? '연결 성공' : '연결 실패' ?></b> — <?= h($diag_map['message']) ?>
			<?php if ( ! empty($diag_map['hint'])): ?>
				<br><span style="color:var(--muted)"><?= h($diag_map['hint']) ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ($diag): ?>
		<div class="notice <?= $diag['ok'] ? 'notice-soju' : '' ?>"
		     style="<?= $diag['ok'] ? '' : 'border-left-color:var(--danger);background:rgba(194,50,31,.06)' ?>">
			<b>지역검색 <?= $diag['ok'] ? '연결 성공' : '연결 실패' ?></b> — <?= h($diag['message']) ?>
			<?php if ( ! empty($diag['hint'])): ?>
				<br><span style="color:var(--muted)"><?= h($diag['hint']) ?></span>
			<?php endif; ?>
			<?php if ( ! empty($diag['sample'])): ?>
				<br><span style="color:var(--muted)">받아온 예시: <?= h(implode(', ', $diag['sample'])) ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="panel">
		<h2>현재 상태</h2>
		<ul class="spec">
			<li>
				<span class="k">지역검색</span>
				<span>
					<?php if ($naver_enabled): ?>
						<b style="color:var(--soju)">키 있음</b> — 추천할 때 실제 장소를 함께 수집합니다.
					<?php else: ?>
						<b style="color:var(--muted)">미설정</b> — 샘플 장소로만 추천합니다.
					<?php endif; ?>
					<form method="post" action="<?= base_url('guide/diagnose') ?>" style="margin-top:10px">
						<button class="btn btn-sm" type="submit">연결 테스트</button>
						<span style="color:var(--muted-2);font-size:12.5px;margin-left:6px">
							실제로 한 번 호출해서 인증되는지 확인합니다
						</span>
					</form>
				</span>
			</li>
			<li>
				<span class="k">추천 기준</span>
				<span>
					<?php
					$mode_label = array(
						'naver' => '네이버로 수집한 장소만',
						'db'    => '직접 등록한 장소만',
						'both'  => '네이버 + 직접 등록 전부',
					);
					?>
					<b><?= h(isset($mode_label[$meta['source_mode']]) ? $mode_label[$meta['source_mode']] : $meta['source_mode']) ?></b>
					<?php if ($meta['source_mode'] === 'db' && ! $naver_enabled): ?>
						<br><span style="color:var(--muted-2);font-size:13px">
							설정은 <code>naver</code> 지만 키가 없어 자동으로 내려왔습니다.
						</span>
					<?php endif; ?>
					<br><span style="color:var(--muted-2);font-size:13px">
						보유: 네이버 <?= (int) $meta['source_counts']['naver'] ?>곳 ·
						직접등록 <?= (int) $meta['source_counts']['manual'] ?>곳
						· <code>source_mode</code> 로 변경
					</span>
				</span>
			</li>
			<li>
				<span class="k">지역 좌표</span>
				<span>
					<?php $pending = (int) $meta['pending_geo']; ?>
					<?php if ($pending === 0): ?>
						<b style="color:var(--soju)">전부 확인됨</b>
					<?php else: ?>
						<b><?= $pending ?>곳 미확인</b> — 좌표가 없으면 거리 점수가 반영되지 않습니다.
						<?php if ($naver_enabled): ?>
							<form method="post" action="<?= base_url('guide/geocode') ?>" style="margin-top:10px">
								<button class="btn btn-soju btn-sm" type="submit">네이버로 좌표 보정 (30곳씩)</button>
							</form>
						<?php else: ?>
							<br><span style="color:var(--muted-2);font-size:13px">
								네이버 키를 넣으면 여기서 실제 좌표로 채울 수 있습니다.
							</span>
						<?php endif; ?>
					<?php endif; ?>
				</span>
			</li>
			<li>
				<span class="k">지도</span>
				<span>
					<?php if ($map_key !== ''): ?>
						<b style="color:var(--soju)">연결됨</b> — 상세 화면에 지도를 표시합니다.
					<?php else: ?>
						<b style="color:var(--muted)">미설정</b> — 지도 대신 네이버 지도 링크만 제공합니다.
					<?php endif; ?>
				</span>
			</li>
		</ul>
	</div>

	<div class="panel">
		<h2>키 넣는 법</h2>

		<p class="notice notice-soju" style="margin-top:0">
			<b>지역검색 키는 두 곳 중 어디서 받아도 됩니다</b> —
			클라우드 플랫폼의 <b>NAVER API HUB</b>, 또는 <b>네이버 개발자센터</b>.
			엔드포인트와 인증 헤더가 다르지만 <code>naver_api_mode = 'auto'</code>가 알아서 맞춥니다.
			<br><b>지도만 별개입니다</b> — Maps는 클라우드 플랫폼에서 따로 Application을 등록해야 합니다.
		</p>

		<p style="color:var(--muted);margin-top:0">
			키는 반드시 <code>application/config/dinnerspot_local.php</code> 에 넣으세요.
			이 파일은 git에 올라가지 않습니다.
			<b><code>dinnerspot.php</code> 에 넣으면 무시됩니다</b> —
			local 파일이 나중에 읽혀 덮어쓰기 때문입니다.
		</p>
<pre style="background:var(--steel-2);border-radius:var(--r);padding:16px 18px;overflow-x:auto;font-size:13.5px;line-height:1.7"><code>&lt;?php
$config['naver_client_id']     = '발급받은 Client ID';
$config['naver_client_secret'] = '발급받은 Client Secret';
$config['naver_map_key_id']    = '지도용 Key ID';   // 선택
</code></pre>

		<ul class="spec" style="margin-top:20px">
			<li>
				<span class="k">1 · 지역검색</span>
				<span>
					둘 중 편한 곳에서 받으세요. 어느 쪽이든
					<code>naver_client_id</code> / <code>naver_client_secret</code>에 넣습니다.
					<br><br>
					<b>ⓐ 클라우드 플랫폼 · NAVER API HUB</b> —
					<a href="https://console.ncloud.com" target="_blank" rel="noopener">콘솔</a>에서
					Application을 만들고 <b>NAVER 검색 &gt; 지역</b>을 등록한 뒤 [인증 정보]의 값을 사용.
					<span style="color:var(--muted-2);font-size:13px">
						(<code>naverapihub.apigw.ntruss.com/search/v1/local</code> ·
						<a href="https://api.ncloud-docs.com/docs/naver-api-hub-search-local" target="_blank" rel="noopener">문서</a>)
					</span>
					<br>
					<b>ⓑ 네이버 개발자센터</b> —
					<a href="https://developers.naver.com/apps/#/register" target="_blank" rel="noopener">앱 등록</a> 후
					사용 API에 <b>검색</b> 추가.
					<span style="color:var(--muted-2);font-size:13px">
						(<code>openapi.naver.com/v1/search/local.json</code> ·
						<a href="https://developers.naver.com/docs/serviceapi/search/local/local.md" target="_blank" rel="noopener">문서</a>)
					</span>
				</span>
			</li>
			<li>
				<span class="k">2 · 지도</span>
				<span>
					지도는 <b>Maps 라는 별도 Application</b>을 등록해야 합니다.
					검색용 Application(API HUB)의 Client ID로는 지도가 인증되지 않습니다 —
					같은 콘솔이고 <b>[인증 정보]</b>라는 이름도 같지만 Application마다 값이 다릅니다.
					<br><br>
					<a href="https://console.ncloud.com/maps/application" target="_blank" rel="noopener">콘솔 · Maps &gt; Application</a>
					<span style="color:var(--muted-2);font-size:13px">
						(메뉴 &gt; All Services &gt; Application Services &gt; Maps &gt; Application)
					</span>
					<ol style="margin:8px 0 0;padding-left:20px;font-size:14px;line-height:1.9">
						<li><b>[Application 등록]</b> 클릭</li>
						<li>API 선택에서 <b>Dynamic Map</b> 체크 <span style="color:var(--muted-2);font-size:13px">— JS 지도는 이게 필수</span></li>
						<li>Web 서비스 URL에 <code>http://localhost</code> 입력 <span style="color:var(--muted-2);font-size:13px">— Dynamic Map은 URL을 최소 1개 요구</span></li>
						<li>등록 후 <b>[인증 정보]</b> 버튼 → <b>Client ID</b>를 <code>naver_map_key_id</code>에</li>
					</ol>
					<span style="color:var(--muted-2);font-size:13px">
						스크립트 파라미터는 <code>ncpKeyId</code> (구 <code>ncpClientId</code>에서 변경) ·
						지도 키가 없어도 추천·투표는 전부 정상 동작합니다
					</span>
				</span>
			</li>
			<li>
				<span class="k">3 · 확인</span>
				<span>
					파일을 저장하고 위의 <b>연결 테스트</b>를 눌러보세요.
					실제로 한 번 호출해서 인증 여부와 실패 원인을 알려줍니다.
				</span>
			</li>
		</ul>
	</div>

	<div class="panel">
		<h2>알아둘 점</h2>
		<ul class="spec">
			<li>
				<span class="k">결과 수</span>
				<span>지역검색 API는 한 번에 5건만 돌려줍니다. 그래서 "지역 + 성격"으로 질의를 여러 번 나눠 던지고 결과를 DB에 쌓습니다. 같은 지역을 다시 검색하면 쌓인 데이터를 씁니다.</span>
			</li>
			<li>
				<span class="k">추정값</span>
				<span>네이버는 가격·좌석·주차 정보를 주지 않습니다. 수집한 장소의 1인 예산과 수용 인원은 <b>업종 기준 추정값</b>이고, 실제 값으로 덮어쓰려면 <code>t_places</code> 테이블을 직접 수정하면 됩니다.</span>
			</li>
			<li>
				<span class="k">호출량</span>
				<span>추천 한 번에 최대 <?= (int) $this->config->item('naver_max_queries', 'dinnerspot') ?>회 호출합니다. 같은 지역은 <?= (int) $this->config->item('naver_cache_hours', 'dinnerspot') ?>시간 동안 재수집하지 않습니다. <code>dinnerspot.php</code>에서 조절할 수 있습니다.</span>
			</li>
			<li>
				<span class="k">점수 가중치</span>
				<span><code>$config['score_weights']</code>에서 거리·예산·인원·평점·조건·종류의 비중을 바꿀 수 있습니다.</span>
			</li>
		</ul>
	</div>

	<div style="margin:22px 0 60px">
		<a class="btn btn-ember" href="<?= base_url() ?>">추천 받으러 가기</a>
	</div>
</div>
