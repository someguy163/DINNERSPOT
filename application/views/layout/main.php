<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<meta name="theme-color" content="#1A1512">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>🍖</text></svg>">
<link rel="stylesheet" href="<?= ds_asset('css/app.css') ?>">
<script>window.DS_BASE = <?= json_encode(base_url()) ?>;</script>
<!-- defer: head 에서 먼저 예약되어 본문의 페이지 스크립트보다 항상 먼저 실행된다 -->
<script defer src="<?= ds_asset('js/app.js') ?>"></script>
</head>
<body class="<?= h($body_class) ?>">

<header class="signboard">
	<div class="wrap">
		<a class="brand" href="<?= base_url() ?>">
			DINNER<b>SPOT</b>
			<span>회식장소 정하기</span>
		</a>
		<nav>
			<a href="<?= base_url() ?>" class="<?= $nav === 'home' ? 'on' : '' ?>">추천받기</a>
			<a href="<?= base_url('vote') ?>" class="<?= $nav === 'vote' ? 'on' : '' ?>">투표 참여</a>
			<a href="<?= base_url('guide') ?>" class="<?= $nav === 'guide' ? 'on' : '' ?>">설정</a>
		</nav>
	</div>
</header>

<main>
<?= $content ?>
</main>

<footer class="foot">
	<div class="wrap">
		DINNERSPOT · CodeIgniter 3 + MySQL · 장소 정보는
		<a href="https://developers.naver.com/docs/serviceapi/search/local/local.md" target="_blank" rel="noopener">네이버 지역검색 API</a>
		를 사용합니다.
	</div>
</footer>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<?php if ( ! empty($scripts)): ?>
	<?php foreach ((array) $scripts as $s): ?>
<script defer src="<?= ds_asset($s) ?>"></script>
	<?php endforeach; ?>
<?php endif; ?>
<?= isset($inline_js) ? $inline_js : '' ?>
</body>
</html>
