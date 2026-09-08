/* =========================================================
   DINNERSPOT - 초대 링크 목록 (방장용)

   링크 하나가 한 사람의 투표 권한이므로 이 화면의 일은 딱 하나다:
   방장이 링크를 "각자에게" 나눠 보내기 쉽게 만드는 것.
   ========================================================= */
(function () {
	'use strict';

	var DS = window.DS;

	if (!DS) { return; }

	var INV    = window.DS_INVITES || {};
	var people = (INV.people || []);
	var list   = document.getElementById('invite-list');

	/* ---------- 복사 공통 ---------- */
	function copy(text, okMsg) {
		if (!text) {
			DS.toast('복사할 링크가 없습니다.', true);
			return;
		}

		DS.copy(text).then(function () {
			DS.toast(okMsg);
		}).catch(function () {
			DS.toast('복사하지 못했습니다. 링크를 직접 선택해 주세요.', true);
		});
	}

	/* "이름 링크" 를 줄바꿈으로 이어붙인다 — 그대로 붙여 쓸 수 있어야 한다 */
	function block(onlyPending) {
		var out = [];

		people.forEach(function (p) {
			if (onlyPending && p.voted) { return; }
			out.push(p.name + ' ' + p.url);
		});

		return out.join('\n');
	}

	function pendingCount() {
		var n = 0;

		people.forEach(function (p) { if (!p.voted) { n++; } });

		return n;
	}

	/* ---------- 사람별 복사 ---------- */
	if (list) {
		list.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.js-copy-one') : null;

			if (!btn) { return; }

			var name = btn.getAttribute('data-name') || '';

			copy(btn.getAttribute('data-url'), name + '님 링크를 복사했습니다. 개인 메시지로 보내세요.');
		});

		// 클립보드가 막힌 환경에서 직접 긁어 복사할 수 있게 focus 시 전체 선택
		list.addEventListener('focusin', function (e) {
			if (e.target && e.target.classList.contains('invite-url')) {
				e.target.select();
			}
		});
	}

	/* ---------- 전체 복사 ---------- */
	var allBtn = document.getElementById('btn-copy-all');

	if (allBtn) {
		allBtn.addEventListener('click', function () {
			copy(block(false), people.length + '명 전체를 복사했습니다. 단체방에 붙이지 마세요.');
		});
	}

	/* ---------- 미투표자만 복사 (독촉용) ---------- */
	var pendBtn = document.getElementById('btn-copy-pending');

	if (pendBtn) {
		pendBtn.addEventListener('click', function () {
			var n = pendingCount();

			if (n === 0) {
				DS.toast('전원 투표했습니다.');
				return;
			}

			copy(block(true), '미투표 ' + n + '명을 복사했습니다.');
		});
	}

	/* ---------- 방(집계) 링크 ---------- */
	var roomBtn = document.getElementById('btn-copy-room');
	var roomEl  = document.getElementById('room-url');

	if (roomBtn) {
		roomBtn.addEventListener('click', function () {
			copy(roomEl ? roomEl.value : INV.roomUrl, '방 링크를 복사했습니다.');
		});
	}

	if (roomEl) {
		roomEl.addEventListener('focus', function () { this.select(); });
	}
}());
