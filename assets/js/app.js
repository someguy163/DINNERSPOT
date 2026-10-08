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

	/* ---------- 기준점 해제 ----------
	   역이 골라졌으면 좌표를 비운다. 서버(resolve_origin)는 area_id 를 좌표보다
	   먼저 보므로, 역이 골라진 채 좌표가 남아 있으면 그 좌표는 조용히 무시된다.
	   (실측: "내 위치 기준" 을 켠 뒤 시/도 select 을 바꾸면 area_id 가 76
	    (정발산역)으로 되돌아가는데 lat/lng 는 그대로 남아 있었다 —
	    버튼은 '해제' 상태, 안내문은 현재 위치를 가리키는데 결과는 정발산역.)

	   pointResetters 는 버튼·안내문까지 되돌리는 콜백 모음이다. 아래 2단 선택
	   블록에서도 불러야 해서 "내 위치 기준" 블록 밖에 둔다. */
	var pointResetters = [];

	function releasePoint(areaEl, msg) {
		var form = areaEl.form;

		if (form) {
			var la = form.querySelector('input[name="lat"]');
			var ln = form.querySelector('input[name="lng"]');

			if (la) { la.value = ''; }
			if (ln) { ln.value = ''; }
		}

		var any = areaEl.querySelector('option[data-any]');

		if (any) {
			any.hidden = true;
			any.disabled = true;
		}

		pointResetters.forEach(function (fn) { fn(msg); });
	}

	/* ---------- 내 위치 기준 검색 ----------
	   좌표만 넣고 끝내면 사용자는 자기가 어디로 검색하는지 알 수 없다.
	   api/whereami 로 "여기가 어디인지"(가장 가까운 등록 지역)를 받아
	   버튼 옆에 보여준다. 위치 이름을 못 받아도 검색 자체는 되므로,
	   실패하면 좌표만 알리고 진행한다.

	   한 번 켜면 끌 수 없던 문제도 여기서 고친다 — 다시 누르면 해제된다. */
	var geoBtn = document.getElementById('btn-geo');

	if (geoBtn) {
		var geoOn = false;

		function geoReset(msg) {
			var status = document.getElementById('geo-status');
			var latEl = document.getElementById('f-lat');
			var lngEl = document.getElementById('f-lng');
			var areaEl = document.getElementById('f-area');
			var pointOpt = document.getElementById('f-area-point');

			if (latEl) { latEl.value = ''; }
			if (lngEl) { lngEl.value = ''; }

			// 좌표를 비웠으니 기준점을 역으로 되돌린다
			if (areaEl && pointOpt && parseInt(areaEl.value, 10) === 0) {
				var back = Array.prototype.filter.call(areaEl.options, function (o) {
					return !o.dataset.any && !o.hidden;
				})[0];

				if (back) { areaEl.value = back.value; }

				// 되돌린 다음에 잠근다. 순서를 바꾸면 disabled 인 항목이
				// 선택된 채로 남아 폼이 area_id 를 아예 보내지 않는다.
				pointOpt.hidden = true;
				pointOpt.disabled = true;
			}

			geoOn = false;
			geoBtn.classList.remove('on');
			geoBtn.disabled = false;
			geoBtn.textContent = '📍 내 위치 기준';

			if (status) { status.textContent = msg || ''; }
		}

		// 지역 select 이 역으로 되돌아갈 때도 버튼/안내문을 같이 되돌린다
		pointResetters.push(function (msg) {
			if (geoOn) { geoReset(msg); }
		});

		geoBtn.addEventListener('click', function () {
			var status = document.getElementById('geo-status');
			var latEl = document.getElementById('f-lat');
			var lngEl = document.getElementById('f-lng');

			// 켜져 있으면 해제
			if (geoOn) {
				geoReset('위치 기준을 해제했습니다. 지역을 골라주세요.');
				return;
			}

			if (!navigator.geolocation) {
				DS.toast('이 브라우저는 위치 기능을 지원하지 않습니다.', true);
				return;
			}

			geoBtn.disabled = true;
			if (status) { status.textContent = '위치를 확인하는 중…'; }

			navigator.geolocation.getCurrentPosition(function (pos) {
				var lat = pos.coords.latitude;
				var lng = pos.coords.longitude;
				var acc = pos.coords.accuracy;

				latEl.value = lat.toFixed(7);
				lngEl.value = lng.toFixed(7);

				/* ★ 여기가 핵심이다. 서버의 resolve_origin() 은 area_id 를
				   좌표보다 먼저 보므로, 역이 골라진 채로 좌표만 넣으면
				   좌표가 조용히 무시된다 — 실측하면 area_id=1 + 시청 좌표로
				   요청했을 때 기준점이 강남역으로 나왔다. 즉 이 버튼이
				   아무 일도 하지 않는 상태였다. */
				var areaEl = document.getElementById('f-area');
				var pointOpt = document.getElementById('f-area-point');

				if (areaEl && pointOpt) {
					// disabled 를 먼저 풀어야 선택이 붙는다. disabled 인 채로
					// 고르면 값은 '0' 으로 보이지만 폼이 area_id 를 보내지 않는다.
					pointOpt.hidden = false;
					pointOpt.disabled = false;
					areaEl.value = '0';
				}

				// 지도 패널이 열려 있으면 핀도 따라 움직인다
				document.dispatchEvent(new CustomEvent('ds:geo', {
					detail: { lat: lat, lng: lng }
				}));

				geoOn = true;
				geoBtn.classList.add('on');
				geoBtn.disabled = false;
				geoBtn.textContent = '📍 내 위치 기준 · 해제';

				if (status) { status.textContent = '위치를 확인했습니다. 어디인지 조회 중…'; }

				// 오차가 크면 그렇다고 말한다. 와이파이/IP 로 잡히면
				// 수 km 가 나오는데 그걸 숨기면 엉뚱한 결과의 원인을 알 수 없다.
				var accNote = (acc && acc > 300)
					? ' · 위치 정확도 ±' + Math.round(acc) + 'm (오차가 큽니다)'
					: '';

				DS.api('api/whereami?lat=' + lat.toFixed(7) + '&lng=' + lng.toFixed(7))
					.then(function (res) {
						if (status) { status.textContent = res.data.label + accNote; }
					})
					.catch(function () {
						// 위치 이름을 못 받아도 좌표 검색은 그대로 된다
						if (status) {
							status.textContent = '현재 위치를 기준으로 찾습니다 ('
								+ lat.toFixed(5) + ', ' + lng.toFixed(5) + ')' + accNote;
						}
					});
			}, function (err) {
				var msg = (err && err.code === 1)
					? '위치 권한이 거부되었습니다. 지역을 직접 골라주세요.'
					: '위치를 가져오지 못했습니다. 지역을 직접 골라주세요.';

				geoReset('');
				DS.toast(msg, true);
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
				// "직접 지정한 위치" 는 어느 시/도에도 속하지 않는다.
				// 숨기면 좌표로 검색하는 상태를 화면에 표시할 수 없다.
				// visible 에는 넣지 않는다 — 시/도를 바꿀 때 자동으로
				// 고르는 대상이 되어선 안 되기 때문이다.
				if (o.dataset.any) { return; }

				var match = (o.dataset.sido === sido);
				o.hidden = !match;
				o.disabled = !match;
				if (match) { visible.push(o); }
			});

			// 현재 선택이 걸러졌으면 그 시/도의 첫 항목으로 옮긴다
			var cur = areaEl.selectedOptions[0];
			var wasPoint = !!(cur && cur.dataset.any);

			if (!keepSelection || !cur || cur.hidden) {
				if (visible.length) {
					areaEl.value = visible[0].value;

					// 좌표로 찍어둔 기준점을 역으로 뺏어왔으면 좌표도 같이 비운다.
					// 남겨두면 서버가 area_id 를 먼저 보므로 좌표가 무시되는데
					// 화면은 여전히 "내 위치 기준" 이라고 말한다.
					if (wasPoint) {
						releasePoint(areaEl, '지역을 골랐으므로 위치 기준을 해제했습니다.');
					}
				}
			}
		}

		sidoEl.addEventListener('change', function () { sync(false); });

		// 역을 직접 고르는 경우도 같다. 지도 패널(map-picker.js)이 열렸을 때만
		// 처리하고 있어서, 지도를 열지 않고 역을 고르면 좌표가 그대로 남았다.
		areaEl.addEventListener('change', function () {
			if (parseInt(areaEl.value, 10) > 0) {
				releasePoint(areaEl, '지역을 골랐으므로 위치 기준을 해제했습니다.');
			}

			/* 검색은 시/도를 넘나든다. 다른 시/도의 역을 고른 뒤 검색어를
			   지우면 sync() 가 시/도 필터를 다시 걸어 그 역을 숨기고 선택을
			   첫 항목으로 옮겨 버린다. 고른 역의 시/도로 맞춰 두면 그 일이
			   일어나지 않는다. */
			var cur = areaEl.selectedOptions[0];

			if (cur && cur.dataset.sido && sidoEl.value !== cur.dataset.sido) {
				sidoEl.value = cur.dataset.sido;

				/* 필터를 반드시 다시 걸어야 한다.
				 *
				 * sync() 는 시/도에 맞지 않는 option 에 disabled 를 붙인다.
				 * 검색(select-search.js)으로 **다른 시/도**의 역을 고르면 그
				 * option 은 아직 disabled 인 채로 선택되고, 브라우저는 선택된
				 * option 이 disabled 인 select 을 **아예 제출하지 않는다**.
				 * 그래서 area_id 가 빠지고 기준점이 '전체' 로 떨어졌다
				 * (실측: 병점역을 검색해 고르면 FormData 에 area_id 가 없음).
				 *
				 * keepSelection=true 로 부르면 방금 고른 역은 새 시/도에
				 * 맞으므로 그대로 남고 disabled 만 풀린다. */
				sync(true);
			}
		});

		sync(true);
	});

	/* ---------- 뒤로 가기 (a.js-back) ----------
	   href 에는 실제 폴백 주소가 들어 있다. 같은 출처에서 넘어온 경우에만
	   history.back() 으로 가로챈다 — 공유 링크나 새 탭으로 바로 열린 페이지에서
	   back() 을 부르면 about:blank 로 넘어가 빈 화면에 갇힌다. */
	Array.prototype.forEach.call(document.querySelectorAll('a.js-back'), function (a) {
		a.addEventListener('click', function (e) {
			var ref = document.referrer || '';

			if (ref.indexOf(location.origin + '/') === 0 && ref !== location.href) {
				e.preventDefault();
				history.back();
			}
			// 아니면 href 를 그대로 따라간다
		});
	});

	/* ---------- 코드 입력 대문자 강제 ---------- */
	var codeInput = document.getElementById('join-code');

	if (codeInput) {
		codeInput.addEventListener('input', function () {
			this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
		});
	}

	/* ---------- 페이지 이동 로딩바 ----------
	   지역 첫 조회는 네이버 호출 8회로 8초쯤 걸린다. 그동안 화면이 그대로라
	   눌렸는지 알 수 없어 사람들이 다시 누른다. 위에 띠를 띄워 진행 중임을 알린다.

	   submit 과 링크 클릭만 본다. 새 탭·다운로드·바깥 주소는 이 페이지가
	   그대로 남으므로 띄우면 영영 안 사라진다. */
	(function () {
		var root = document.documentElement;

		function start() { root.classList.add('is-loading'); }

		document.addEventListener('submit', start, true);

		document.addEventListener('click', function (e) {
			var a = e.target.closest ? e.target.closest('a') : null;

			if (!a || !a.href) { return; }
			if (a.target === '_blank' || a.hasAttribute('download')) { return; }
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
			if (a.origin !== location.origin) { return; }
			if (a.getAttribute('href').charAt(0) === '#') { return; }

			start();
		}, true);

		// 뒤로 가기로 돌아오면 캐시된 화면이라 띠가 남아 있다
		window.addEventListener('pageshow', function () { root.classList.remove('is-loading'); });
	}());
}());
