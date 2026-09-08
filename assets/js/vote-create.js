/* =========================================================
   DINNERSPOT - 투표방 생성

   참여 방식이 두 가지다.
     open   — 링크 하나를 단체방에 뿌린다. 참여자가 이름을 적는다.
     invite — 명단대로 1인 1링크. 만든 다음 할 일은 "방 들어가기"가 아니라
              "링크 뿌리기"라서 초대 링크 목록으로 보낸다.
   ========================================================= */
(function () {
	'use strict';

	var DS   = window.DS;
	var form = document.getElementById('create-form');

	if (!form) { return; }

	var btn       = document.getElementById('create-btn');
	var rosterFld = document.getElementById('v-roster-field');
	var rosterEl  = document.getElementById('v-roster');
	var headFld   = document.getElementById('v-head-field');
	var countRow  = document.getElementById('v-count-row');
	var modeHint  = document.getElementById('v-mode-hint');
	var rosterCnt = document.getElementById('v-roster-count');
	var modeEls   = form.querySelectorAll('input[name="join_mode"]');

	var HINT = {
		open: '링크 하나를 단체방에 뿌립니다. 참여자가 이름을 직접 적습니다.',
		invite: '명단에 올린 사람마다 개인 링크가 생깁니다. 누가 아직 안 했는지 이름으로 보입니다.'
	};

	function mode() {
		var on = form.querySelector('input[name="join_mode"]:checked');

		return (on && on.value === 'invite') ? 'invite' : 'open';
	}

	function isInvite() { return mode() === 'invite'; }

	/* 서버(Vote_model::clean_roster)와 같은 규칙으로 세어 보여준다 —
	   줄바꿈/콤마/탭 구분, 공백 제거, 같은 이름은 하나만. */
	function names() {
		if (!rosterEl) { return []; }

		var seen = {};
		var out  = [];

		rosterEl.value.split(/[\r\n,\t]+/).forEach(function (raw) {
			var nick = raw.trim();

			if (!nick || out.length >= 100) { return; }

			var key = nick.toLowerCase();

			if (seen[key]) { return; }

			seen[key] = true;
			out.push(nick);
		});

		return out;
	}

	function label() {
		return isInvite() ? '초대 링크 만들기' : '투표방 만들기';
	}

	function countNames() {
		if (!rosterCnt) { return; }

		var n = names().length;

		rosterCnt.textContent = n
			? (n + '명 · 이 인원이 참석 인원이 됩니다.')
			: '아직 아무도 없습니다.';
	}

	function sync() {
		var invite = isInvite();

		if (rosterFld) { rosterFld.hidden = !invite; }
		if (headFld)   { headFld.hidden = invite; }          // 명단 수가 곧 인원이다
		if (countRow)  { countRow.classList.toggle('solo', invite); }
		if (modeHint)  { modeHint.textContent = invite ? HINT.invite : HINT.open; }
		if (btn && !btn.disabled) { btn.textContent = label(); }

		countNames();
	}

	Array.prototype.forEach.call(modeEls, function (el) {
		el.addEventListener('change', sync);
	});

	if (rosterEl) { rosterEl.addEventListener('input', countNames); }

	sync();

	/* ---------- 생성 ---------- */
	form.addEventListener('submit', function (e) {
		e.preventDefault();

		var fd     = new FormData(form);
		var nick   = (fd.get('host_nick') || '').trim();
		var invite = isInvite();

		if (!nick) {
			DS.toast('이름을 입력해 주세요.', true);
			document.getElementById('v-nick').focus();
			return;
		}

		if (invite && names().length === 0) {
			DS.toast('명단을 한 명 이상 입력해 주세요.', true);
			if (rosterEl) { rosterEl.focus(); }
			return;
		}

		var body = {
			place_ids: fd.get('place_ids'),
			title: fd.get('title'),
			host_nick: nick,
			headcount: invite ? 0 : parseInt(fd.get('headcount') || '0', 10),
			max_choice: parseInt(fd.get('max_choice') || '1', 10),
			allow_change: fd.get('allow_change') ? 1 : 0,
			deadline_at: fd.get('deadline_at') || null
		};

		// 명단은 원문 그대로 보낸다 — 줄바꿈/콤마 정리는 서버가 한다
		if (invite) {
			body.roster = rosterEl ? rosterEl.value : '';
		}

		btn.disabled = true;
		btn.textContent = '만드는 중…';

		DS.api('api/vote/create', {
			method: 'POST',
			body: body
		}).then(function (res) {
			var d = res.data;

			// 방장 권한 토큰과 내 이름을 브라우저에 보관
			DS.store.set('ds_host_' + d.code, d.host_key);
			DS.store.set('ds_nick', nick);

			// 명단 모드의 다음 단계는 방 입장이 아니라 링크 뿌리기다
			location.href = (d.mode === 'invite' && d.invites_url)
				? d.invites_url
				: d.room_url;
		}).catch(function (err) {
			btn.disabled = false;
			btn.textContent = label();
			DS.toast(err.message, true);
		});
	});
}());
