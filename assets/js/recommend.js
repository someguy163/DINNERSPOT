/* =========================================================
   DINNERSPOT - 추천 결과 화면
   점수 게이지 애니메이션 + 후보 담기 트레이
   ========================================================= */
(function () {
	'use strict';

	var DS = window.DS;
	var MAX = window.DS_VOTE_MAX || 6;
	var MIN = window.DS_VOTE_MIN || 2;

	/* ---------- 조건 수정 토글 ---------- */
	var editForm = document.getElementById('edit-form');

	function toggleEdit() {
		if (!editForm) { return; }

		editForm.hidden = !editForm.hidden;

		if (!editForm.hidden) {
			editForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	['btn-edit', 'btn-edit-2'].forEach(function (id) {
		var el = document.getElementById(id);
		if (el) { el.addEventListener('click', toggleEdit); }
	});

	/* ---------- 게이지: 한 번만 차오른다 ---------- */
	var fills = document.querySelectorAll('.gauge-fill');

	if (fills.length) {
		requestAnimationFrame(function () {
			requestAnimationFrame(function () {
				fills.forEach(function (el) {
					el.style.width = (el.dataset.score || 0) + '%';
				});
			});
		});
	}

	/* ---------- 후보 트레이 ---------- */
	var tray = document.getElementById('tray');
	var trayList = document.getElementById('tray-list');
	var trayGo = document.getElementById('tray-go');
	var trayClear = document.getElementById('tray-clear');
	var inputs = Array.prototype.slice.call(document.querySelectorAll('.pick-input'));

	if (!tray || !inputs.length) { return; }

	var picked = [];

	function nameOf(id) {
		var li = document.querySelector('.rank[data-id="' + id + '"]');

		return li ? li.dataset.name : '';
	}

	function render() {
		if (!picked.length) {
			tray.classList.remove('up');
			document.body.style.paddingBottom = '';
			return;
		}

		var names = picked.map(nameOf).filter(Boolean);
		var head = '<b>후보 ' + picked.length + '곳</b> · ' + DS.escape(names.join(', '));

		trayList.innerHTML = picked.length < MIN
			? head + ' — ' + MIN + '곳 이상 담아야 투표를 만들 수 있습니다'
			: head;

		trayGo.href = DS.base + 'vote/new?ids=' + picked.join(',');
		trayGo.setAttribute('aria-disabled', picked.length < MIN ? 'true' : 'false');
		trayGo.style.opacity = picked.length < MIN ? '.45' : '1';
		trayGo.style.pointerEvents = picked.length < MIN ? 'none' : '';

		tray.classList.add('up');
		document.body.style.paddingBottom = tray.offsetHeight + 'px';
	}

	inputs.forEach(function (input) {
		input.addEventListener('change', function () {
			var id = parseInt(this.value, 10);

			if (this.checked) {
				if (picked.length >= MAX) {
					this.checked = false;
					DS.toast('후보는 최대 ' + MAX + '곳까지 담을 수 있습니다.', true);
					return;
				}
				picked.push(id);
			} else {
				picked = picked.filter(function (x) { return x !== id; });
			}

			render();
		});
	});

	if (trayClear) {
		trayClear.addEventListener('click', function () {
			picked = [];
			inputs.forEach(function (i) { i.checked = false; });
			render();
		});
	}
}());
