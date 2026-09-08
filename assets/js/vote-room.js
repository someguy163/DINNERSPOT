/* =========================================================
   DINNERSPOT - 투표방
   투표 제출 + 결과 폴링 (방을 보고 있는 동안만)
   ========================================================= */
(function () {
	'use strict';

	var DS = window.DS;
	var R = window.DS_ROOM;

	if (!R) { return; }

	var voterKey = DS.store.get('ds_voter_' + R.code) || '';
	var hostKey = DS.store.get('ds_host_' + R.code) || '';

	var form = document.getElementById('ballot-form');
	var nickEl = document.getElementById('b-nick');
	var commentEl = document.getElementById('b-comment');
	var btn = document.getElementById('ballot-btn');
	var hint = document.getElementById('ballot-hint');
	var opts = Array.prototype.slice.call(document.querySelectorAll('.opt-input'));

	var tallyEl = document.getElementById('tally');
	var votersEl = document.getElementById('voters');
	var sumEl = document.getElementById('sum-voters');
	var statusEl = document.getElementById('head-status');
	var ballotPanel = document.getElementById('ballot-panel');
	var closedPanel = document.getElementById('closed-panel');
	var closeBtn = document.getElementById('btn-close');

	/* ---------- 공유 ---------- */
	var copyBtn = document.getElementById('btn-copy');

	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var url = document.getElementById('share-url').value;

			DS.copy(url).then(function () {
				DS.toast('링크를 복사했습니다.');
			}).catch(function () {
				DS.toast('복사하지 못했습니다. 주소를 직접 선택해 주세요.', true);
			});
		});
	}

	/* ---------- 방장 마감 ---------- */
	if (hostKey && closeBtn && R.status === 'open') {
		closeBtn.hidden = false;

		closeBtn.addEventListener('click', function () {
			if (!confirm('지금 마감하면 더 이상 투표할 수 없습니다. 마감할까요?')) { return; }

			closeBtn.disabled = true;

			DS.api('api/vote/' + R.code + '/close', {
				method: 'POST',
				body: { host_key: hostKey }
			}).then(function (res) {
				R.status = 'closed';
				apply(res.data);
				DS.toast('투표를 마감했습니다.');
			}).catch(function (err) {
				closeBtn.disabled = false;
				DS.toast(err.message, true);
			});
		});
	}

	/* ---------- 저장된 이름 채우기 ---------- */
	if (nickEl) {
		var saved = DS.store.get('ds_nick');
		if (saved && !nickEl.value) { nickEl.value = saved; }
	}

	/* ---------- 선택 개수 제한 ---------- */
	if (R.maxChoice > 1) {
		opts.forEach(function (el) {
			el.addEventListener('change', function () {
				var checked = opts.filter(function (o) { return o.checked; });

				if (checked.length > R.maxChoice) {
					this.checked = false;
					DS.toast('최대 ' + R.maxChoice + '곳까지 고를 수 있습니다.', true);
				}
			});
		});

		if (hint) { hint.textContent = '최대 ' + R.maxChoice + '곳까지 고를 수 있습니다.'; }
	}

	/* ---------- 투표 제출 ---------- */
	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();

			var ids = opts.filter(function (o) { return o.checked; })
				.map(function (o) { return parseInt(o.value, 10); });

			if (!nickEl.value.trim()) {
				DS.toast('이름을 입력해 주세요.', true);
				nickEl.focus();
				return;
			}

			if (!ids.length) {
				DS.toast('한 곳 이상 골라주세요.', true);
				return;
			}

			btn.disabled = true;
			btn.textContent = '보내는 중…';

			DS.api('api/vote/' + R.code + '/cast', {
				method: 'POST',
				body: {
					voter_key: voterKey,
					nickname: nickEl.value.trim(),
					option_ids: ids,
					comment: commentEl ? commentEl.value.trim() : ''
				}
			}).then(function (res) {
				voterKey = res.data.voter_key;
				DS.store.set('ds_voter_' + R.code, voterKey);
				DS.store.set('ds_nick', nickEl.value.trim());

				apply(res.data.state);
				DS.toast(R.allowChange ? '투표했습니다. 언제든 바꿀 수 있습니다.' : '투표했습니다.');

				btn.disabled = false;
				btn.textContent = '투표 바꾸기';
			}).catch(function (err) {
				btn.disabled = false;
				btn.textContent = '투표하기';
				DS.toast(err.message, true);
			});
		});
	}

	/* ---------- 상태 반영 ---------- */
	function apply(state) {
		if (!state) { return; }

		// 집계
		state.options.forEach(function (o) {
			var li = tallyEl.querySelector('[data-oid="' + o.id + '"]');

			if (!li) { return; }

			li.classList.toggle('lead', !!o.leading && o.votes > 0);
			li.querySelector('.tally-cnt').innerHTML =
				'<b>' + o.votes + '</b>표 · ' + o.percent + '%';
			li.querySelector('.tally-fill').style.width = o.percent + '%';
		});

		// 참여자
		if (sumEl) { sumEl.textContent = state.voter_count; }

		if (votersEl) {
			if (!state.voters.length) {
				votersEl.innerHTML =
					'<li style="background:transparent;color:var(--muted-2);padding-left:0">아직 아무도 투표하지 않았습니다.</li>';
			} else {
				votersEl.innerHTML = state.voters.map(function (v) {
					var c = v.comment
						? ' <span class="ago">' + DS.escape(v.comment) + '</span>'
						: '';

					return '<li>' + DS.escape(v.nickname) +
						'<span class="ago">' + DS.escape(v.ago) + '</span>' + c + '</li>';
				}).join('');
			}
		}

		// 내가 고른 것 체크 유지
		if (state.me && state.me.picks) {
			opts.forEach(function (o) {
				o.checked = state.me.picks.indexOf(parseInt(o.value, 10)) !== -1;
			});

			if (nickEl && !document.activeElement.isSameNode(nickEl)) {
				nickEl.value = state.me.nickname;
			}

			if (commentEl && !document.activeElement.isSameNode(commentEl)) {
				commentEl.value = state.me.comment || '';
			}

			if (btn && !btn.disabled) { btn.textContent = '투표 바꾸기'; }
		}

		// 마감 상태
		if (state.room.status === 'closed' && R.status !== 'closed') {
			R.status = 'closed';
		}

		if (state.room.status === 'closed') {
			if (ballotPanel) { ballotPanel.hidden = true; }
			if (closedPanel) { closedPanel.hidden = false; }
			if (closeBtn) { closeBtn.hidden = true; }
			if (statusEl) { statusEl.innerHTML = '<b>마감됨</b>'; }
			stopPolling();
		}
	}

	/* ---------- 폴링: 탭이 보일 때만 ---------- */
	var timer = null;

	function tick() {
		var q = 'api/vote/' + R.code + '?';

		if (voterKey) { q += 'voter_key=' + encodeURIComponent(voterKey) + '&'; }
		if (hostKey) { q += 'host_key=' + encodeURIComponent(hostKey); }

		DS.api(q).then(function (res) {
			apply(res.data);
		}).catch(function () {
			// 일시적 실패는 조용히 무시하고 다음 주기에 재시도
		});
	}

	function startPolling() {
		if (timer || R.status === 'closed') { return; }

		timer = setInterval(function () {
			if (document.visibilityState === 'visible') { tick(); }
		}, R.pollMs || 4000);
	}

	function stopPolling() {
		clearInterval(timer);
		timer = null;

		var note = document.getElementById('poll-note');
		if (note) { note.textContent = '마감된 투표입니다.'; }
	}

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible' && R.status !== 'closed') { tick(); }
	});

	// 이미 투표했다면 초기 상태를 한 번 당겨온다
	if (voterKey) { tick(); }

	startPolling();
}());
