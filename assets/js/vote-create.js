/* =========================================================
   DINNERSPOT - 투표방 생성
   ========================================================= */
(function () {
	'use strict';

	var DS = window.DS;
	var form = document.getElementById('create-form');

	if (!form) { return; }

	var btn = document.getElementById('create-btn');

	form.addEventListener('submit', function (e) {
		e.preventDefault();

		var fd = new FormData(form);
		var nick = (fd.get('host_nick') || '').trim();

		if (!nick) {
			DS.toast('이름을 입력해 주세요.', true);
			document.getElementById('v-nick').focus();
			return;
		}

		btn.disabled = true;
		btn.textContent = '만드는 중…';

		DS.api('api/vote/create', {
			method: 'POST',
			body: {
				place_ids: fd.get('place_ids'),
				title: fd.get('title'),
				host_nick: nick,
				headcount: parseInt(fd.get('headcount') || '0', 10),
				max_choice: parseInt(fd.get('max_choice') || '1', 10),
				allow_change: fd.get('allow_change') ? 1 : 0,
				deadline_at: fd.get('deadline_at') || null
			}
		}).then(function (res) {
			var d = res.data;

			// 방장 권한 토큰과 내 이름을 브라우저에 보관
			DS.store.set('ds_host_' + d.code, d.host_key);
			DS.store.set('ds_nick', nick);

			location.href = d.room_url;
		}).catch(function (err) {
			btn.disabled = false;
			btn.textContent = '투표방 만들기';
			DS.toast(err.message, true);
		});
	});
}());
