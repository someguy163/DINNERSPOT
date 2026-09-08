/* =========================================================
   DINNERSPOT - 관리자 방 현황

   이 화면은 읽기 전용이다. 여기서 하는 일은 링크를 가져가는 것뿐이라
   복사 말고는 아무것도 하지 않는다 (폴링도 없다 — 새로고침이면 충분하다).
   ========================================================= */
(function () {
	'use strict';

	var DS = window.DS;

	if (!DS) { return; }

	function copy(text, okMsg) {
		if (!text) {
			DS.toast('복사할 링크가 없습니다.', true);
			return;
		}

		DS.copy(text).then(function () {
			DS.toast(okMsg || '복사했습니다.');
		}).catch(function () {
			DS.toast('복사하지 못했습니다. 링크를 직접 선택해 주세요.', true);
		});
	}

	/* ---------- data-copy 를 가진 버튼 전부 ---------- */
	Array.prototype.forEach.call(document.querySelectorAll('.js-copy'), function (btn) {
		btn.addEventListener('click', function () {
			copy(btn.getAttribute('data-copy'), btn.getAttribute('data-msg'));
		});
	});

	/* ---------- 클립보드가 막힌 환경: 칸을 누르면 전체 선택 ---------- */
	document.addEventListener('focusin', function (e) {
		var el = e.target;

		if (el && el.tagName === 'INPUT' && el.readOnly) {
			el.select();
		}
	});

	/* ---------- 미투표자 링크만 모아서 복사 (독촉용) ---------- */
	var pendBtn = document.getElementById('adm-copy-pending');
	var list = document.getElementById('adm-invites');

	if (pendBtn && list) {
		pendBtn.addEventListener('click', function () {
			var rows = list.querySelectorAll('.invite:not(.done)');
			var lines = [];

			Array.prototype.forEach.call(rows, function (li) {
				var input = li.querySelector('.invite-url');

				if (input && input.value) {
					lines.push((li.getAttribute('data-name') || '') + ' ' + input.value);
				}
			});

			if (!lines.length) {
				DS.toast('미투표자가 없습니다.');
				return;
			}

			copy(lines.join('\n'), '미투표 ' + lines.length + '명을 복사했습니다. 단체방에 붙이지 마세요.');
		});
	}
}());
