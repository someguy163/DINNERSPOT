/* =========================================================
   DINNERSPOT - 공통 스크립트
   ========================================================= */
(function () {
	'use strict';

	var DS = window.DS = window.DS || {};

	DS.base = window.DS_BASE || '/';

	/* ---------- 토스트 ---------- */
	var toastEl = null;
	var toastTimer = null;

	DS.toast = function (msg, isError) {
		toastEl = toastEl || document.getElementById('toast');

		if (!toastEl) { return; }

		toastEl.textContent = msg;
		toastEl.classList.toggle('err', !!isError);
		toastEl.classList.add('on');

		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			toastEl.classList.remove('on');
		}, 2600);
	};

	/* ---------- fetch 래퍼 ---------- */
	DS.api = function (path, options) {
		options = options || {};

		var url = DS.base + path.replace(/^\//, '');
		var init = {
			method: options.method || 'GET',
			headers: { 'Accept': 'application/json' },
			credentials: 'same-origin'
		};

		if (options.body) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(options.body);
		}

		return fetch(url, init).then(function (res) {
			return res.json().catch(function () {
				throw new Error('서버 응답을 읽지 못했습니다.');
			}).then(function (json) {
				if (!res.ok || json.ok === false) {
					throw new Error(json.message || ('요청 실패 (' + res.status + ')'));
				}
				return json;
			});
		});
	};

	/* ---------- 로컬 저장소 (사파리 프라이빗 등에서 throw) ---------- */
	DS.store = {
		get: function (k) {
			try { return localStorage.getItem(k); } catch (e) { return null; }
		},
		set: function (k, v) {
			try { localStorage.setItem(k, v); } catch (e) { /* 저장 못해도 진행 */ }
		},
		del: function (k) {
			try { localStorage.removeItem(k); } catch (e) { }
		}
	};

	/* ---------- 클립보드 ---------- */
	DS.copy = function (text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();

			try {
				document.execCommand('copy') ? resolve() : reject(new Error('복사 실패'));
			} catch (e) {
				reject(e);
			} finally {
				document.body.removeChild(ta);
			}
		});
	};

	DS.escape = function (s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
		});
	};

	/* ---------- 내 위치 기준 검색 ---------- */
	var geoBtn = document.getElementById('btn-geo');

	if (geoBtn) {
		geoBtn.addEventListener('click', function () {
			var status = document.getElementById('geo-status');
			var latEl = document.getElementById('f-lat');
			var lngEl = document.getElementById('f-lng');

			if (!navigator.geolocation) {
				DS.toast('이 브라우저는 위치 기능을 지원하지 않습니다.', true);
				return;
			}

			geoBtn.disabled = true;
			if (status) { status.textContent = '위치를 확인하는 중…'; }

			navigator.geolocation.getCurrentPosition(function (pos) {
				latEl.value = pos.coords.latitude.toFixed(7);
				lngEl.value = pos.coords.longitude.toFixed(7);
				geoBtn.classList.add('on');
				geoBtn.style.background = 'var(--char)';
				geoBtn.style.borderColor = 'var(--char)';
				geoBtn.style.color = '#fff';
				geoBtn.disabled = false;

				if (status) { status.textContent = '현재 위치를 기준으로 찾습니다.'; }
			}, function () {
				geoBtn.disabled = false;
				if (status) { status.textContent = ''; }
				DS.toast('위치를 가져오지 못했습니다. 지역을 직접 골라주세요.', true);
			}, { timeout: 8000, enableHighAccuracy: false });
		});
	}

	/* ---------- 지역 2단 선택 (시/도 → 역·상권) ----------
	   역 option 은 서버가 전부 출력하고, 여기서 hidden 으로 걸러낸다.
	   JS 가 없으면 전체 목록에서 고를 수 있으므로 기능은 유지된다. */
	Array.prototype.forEach.call(document.querySelectorAll('.js-sido'), function (sidoEl) {
		var areaEl = document.getElementById(sidoEl.dataset.target);

		if (!areaEl) { return; }

		function sync(keepSelection) {
			var sido = sidoEl.value;
			var opts = Array.prototype.slice.call(areaEl.options);
			var visible = [];

			opts.forEach(function (o) {
				var match = (o.dataset.sido === sido);
				o.hidden = !match;
				o.disabled = !match;
				if (match) { visible.push(o); }
			});

			// 현재 선택이 걸러졌으면 그 시/도의 첫 항목으로 옮긴다
			var cur = areaEl.selectedOptions[0];

			if (!keepSelection || !cur || cur.hidden) {
				if (visible.length) { areaEl.value = visible[0].value; }
			}
		}

		sidoEl.addEventListener('change', function () { sync(false); });
		sync(true);
	});

	/* ---------- 코드 입력 대문자 강제 ---------- */
	var codeInput = document.getElementById('join-code');

	if (codeInput) {
		codeInput.addEventListener('input', function () {
			this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
		});
	}
}());
