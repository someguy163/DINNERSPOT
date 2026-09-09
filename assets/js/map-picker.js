/* =========================================================
   DINNERSPOT - 지도에서 검색 기준점 조정

   핀을 끌거나 지도를 눌러 기준점을 옮긴다. 반경 원을 함께 그려
   "여기서 얼마나 넓게 찾는지" 가 보이게 한다.

   ★ 옮기면 area_id 를 0 으로 내려야 한다.
     서버의 resolve_origin() 은 area_id 를 좌표보다 **먼저** 보므로,
     역을 고른 채 좌표만 바꾸면 조정이 조용히 무시된다
     (실측: area_id=1 + 시청 좌표 -> 기준점이 강남역으로 나왔다).

   지도 키가 없거나 인증이 실패하면 이 파일은 아무것도 하지 않고
   안내 문구만 띄운다 — 역 선택과 내 위치 기준은 지도 없이 동작한다.
   ========================================================= */
(function () {
	'use strict';

	var DS = window.DS;
	var panels = Array.prototype.slice.call(document.querySelectorAll('.mappick'));

	if (!panels.length) { return; }

	/* maps.js 를 못 불러왔거나 인증에 실패한 경우.
	   navermap_authFailure() 가 먼저 불릴 수도 있어 양쪽에서 처리한다. */
	function failAll(msg) {
		panels.forEach(function (panel) {
			var uid = panel.dataset.uid;
			var map = document.getElementById(uid + '-map');
			var fb = document.getElementById(uid + '-map-fallback');
			var bar = panel.querySelector('.mappick-bar');

			if (map) { map.hidden = true; }
			if (bar) { bar.hidden = true; }

			if (fb) {
				fb.hidden = false;
				if (msg) { fb.textContent = msg; }
			}
		});
	}

	function authFailed() {
		failAll('지도 인증에 실패했습니다. 설정에서 지도 Key ID 와 Web 서비스 URL 등록을 확인하세요. '
			+ '역을 직접 고르거나 “내 위치 기준”을 쓰면 지도 없이도 검색됩니다.');
	}

	/* 파티셜이 maps.js 보다 먼저 심어둔 스텁과 연결한다.
	   이 파일은 defer 라서, 인증 실패 콜백이 여기 도착하기 전에
	   불릴 수 있다 — 그 경우 플래그로 남아 있다. */
	window.__dsMapFail = authFailed;

	if (window.__dsMapAuthFailed) {
		authFailed();
		return;
	}

	if (typeof naver === 'undefined' || !naver.maps) {
		failAll();
		return;
	}

	panels.forEach(setup);

	function setup(panel) {
		var uid = panel.dataset.uid;
		var form = panel.closest('form');

		if (!form) { return; }

		var mapEl = document.getElementById(uid + '-map');
		var labelEl = document.getElementById(uid + '-map-label');
		var toggle = document.getElementById(uid + '-btn-map');
		var btnHere = document.getElementById(uid + '-map-here');
		var btnReset = document.getElementById(uid + '-map-reset');
		var pointOpt = document.getElementById(uid + '-area-point');

		var latEl = form.querySelector('input[name="lat"]');
		var lngEl = form.querySelector('input[name="lng"]');
		var areaEl = form.querySelector('select[name="area_id"]');

		if (!mapEl || !latEl || !lngEl) { return; }

		var map = null;
		var marker = null;
		var circle = null;
		var labelTimer = null;

		/* 역으로 돌아갈 때 쓸 값. 지도를 움직이면 area_id 가 0 이 되어
		   무엇을 골랐었는지 잃어버리므로 따로 기억한다. */
		var homeArea = (areaEl && parseInt(areaEl.value, 10) > 0) ? areaEl.value : '';

		function areaCoords() {
			if (!areaEl) { return null; }

			var o = areaEl.selectedOptions[0];

			if (!o || !o.dataset || !o.dataset.lat) { return null; }

			var la = parseFloat(o.dataset.lat);
			var ln = parseFloat(o.dataset.lng);

			// 좌표가 없는 지역(0)은 지도의 시작점이 될 수 없다
			return (la && ln) ? [la, ln] : null;
		}

		/* 지금 어디를 중심으로 볼지: 넘겨받은 좌표 > 입력된 좌표 > 선택된 역 */
		function startPos() {
			var la = parseFloat(latEl.value);
			var ln = parseFloat(lngEl.value);

			if (la && ln) { return [la, ln]; }

			la = parseFloat(panel.dataset.lat);
			ln = parseFloat(panel.dataset.lng);

			if (la && ln) { return [la, ln]; }

			return areaCoords() || [37.5665, 126.9780];   // 최후: 서울시청
		}

		function radius() {
			var r = form.querySelector('input[name="radius"]:checked');

			return r ? parseInt(r.value, 10) : 800;
		}

		function setLabel(text) {
			if (labelEl) { labelEl.textContent = text; }
		}

		/* 좌표를 폼에 쓰고 기준점을 "지점" 으로 바꾼다 */
		function commit(lat, lng) {
			latEl.value = lat.toFixed(7);
			lngEl.value = lng.toFixed(7);

			if (areaEl && pointOpt) {
				// disabled 를 먼저 풀어야 한다 — 잠긴 항목을 고르면 값은 '0'
				// 으로 보이지만 폼이 area_id 를 아예 보내지 않는다.
				pointOpt.hidden = false;
				pointOpt.disabled = false;
				areaEl.value = '0';
			}

			setLabel('위치를 확인하는 중…');

			// 끄는 동안 매번 부르지 않도록 잠깐 모은다
			clearTimeout(labelTimer);
			labelTimer = setTimeout(function () {
				DS.api('api/whereami?lat=' + lat.toFixed(7) + '&lng=' + lng.toFixed(7))
					.then(function (res) { setLabel(res.data.label); })
					.catch(function () {
						setLabel('이 지점으로 찾습니다 (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ')');
					});
			}, 250);
		}

		function moveTo(lat, lng, recenter) {
			var pos = new naver.maps.LatLng(lat, lng);

			marker.setPosition(pos);
			circle.setCenter(pos);

			if (recenter) { map.setCenter(pos); }

			commit(lat, lng);
		}

		function build() {
			var p = startPos();
			var pos = new naver.maps.LatLng(p[0], p[1]);

			map = new naver.maps.Map(mapEl, {
				center: pos,
				zoom: 15,
				scaleControl: false,
				logoControl: true,
				mapDataControl: false,
				zoomControl: true,
				zoomControlOptions: { position: naver.maps.Position.TOP_RIGHT }
			});

			marker = new naver.maps.Marker({
				position: pos,
				map: map,
				draggable: true,
				title: '검색 기준점 — 끌어서 옮기세요'
			});

			circle = new naver.maps.Circle({
				map: map,
				center: pos,
				radius: radius(),
				strokeColor: '#FF5A1F',
				strokeOpacity: 0.85,
				strokeWeight: 2,
				fillColor: '#FF5A1F',
				fillOpacity: 0.08
			});

			naver.maps.Event.addListener(marker, 'dragend', function (e) {
				moveTo(e.coord.lat(), e.coord.lng(), false);
			});

			// 지도를 눌러도 옮겨진다 (핀을 정확히 잡기 어려운 손가락 조작 배려)
			naver.maps.Event.addListener(map, 'click', function (e) {
				moveTo(e.coord.lat(), e.coord.lng(), false);
			});

			// 거리 칩을 바꾸면 원도 따라간다
			Array.prototype.forEach.call(form.querySelectorAll('input[name="radius"]'), function (r) {
				r.addEventListener('change', function () {
					circle.setRadius(radius());
					fitCircle();
				});
			});

			// 역을 다시 고르면 그 역으로 돌아간다 (지점 지정 해제)
			if (areaEl) {
				areaEl.addEventListener('change', function () {
					if (parseInt(areaEl.value, 10) > 0) {
						homeArea = areaEl.value;
						syncReset();
						toArea();
					}
				});
			}

			syncReset();
			fitCircle();

			// 처음 열었을 때도 지금 기준점이 어디인지 말해준다
			if (parseFloat(latEl.value) && parseFloat(lngEl.value)) {
				commit(parseFloat(latEl.value), parseFloat(lngEl.value));
			} else {
				setLabel('핀을 끌거나 지도를 눌러 기준점을 옮기세요. 원은 검색 반경입니다.');
			}
		}

		/* 반경 원이 지도 안에 들어오게 축척을 맞춘다.
		   zoom 을 15 로 못박아 두면 원이 화면보다 커져 아예 보이지 않는다
		   (실측: 375px 에서 지도 295x260, 기본 800m 원의 지름이 422px —
		    화면 밖으로 나가 원이 한 조각도 안 보였다. 데스크톱에서도
		    1.5km 이상은 전부 화면을 덮어 "검색 반경" 안내가 무의미했다).
		   반경을 바꿀 때마다 다시 맞춘다. */
		function fitCircle() {
			if (!map || !circle) { return; }

			try {
				map.fitBounds(circle.getBounds(), { top: 12, right: 12, bottom: 12, left: 12 });
			} catch (e) {
				// fitBounds 를 못 쓰는 버전이면 지금 축척을 그대로 둔다.
				// 원이 안 보일 수는 있어도 기준점 지정 자체는 계속 된다.
			}
		}

		/* "역 위치로" 는 돌아갈 역이 있을 때만 누를 수 있다.
		   좌표로 열린 결과 화면은 area_id 가 0 이라 돌아갈 역이 없는데,
		   그때 좌표까지 비우면 기준점이 통째로 사라진다
		   (실측: /recommend?lat=37.5511&lng=126.9882 -> 조건 수정 -> 지도 ->
		    "역 위치로" -> "다시 추천" -> 기준점이 '전체 근처, 200곳' 이 됐다). */
		function syncReset() {
			if (!btnReset) { return; }

			btnReset.disabled = (homeArea === '');
			btnReset.title = (homeArea === '')
				? '돌아갈 역이 없습니다. 위 문장에서 지역을 먼저 고르세요.'
				: '';
		}

		/* 선택된 역 위치로 되돌린다 — 좌표를 비워 area_id 가 다시 기준이 되게 */
		function toArea() {
			// 돌아갈 역이 없으면 아무것도 지우지 않는다. 좌표를 비우는 순간
			// area_id 도 0 이어서 기준점 없는 전국 검색이 되어버린다.
			if (!homeArea) {
				setLabel('돌아갈 역이 없습니다. 위 문장에서 지역을 고르면 그 역으로 옮겨갑니다.');
				return;
			}

			if (areaEl) { areaEl.value = homeArea; }

			var p = areaCoords();

			latEl.value = '';
			lngEl.value = '';

			// areaEl.value 를 역으로 되돌린 **뒤에** 잠근다
			if (pointOpt) {
				pointOpt.hidden = true;
				pointOpt.disabled = true;
			}

			if (p && map) {
				var pos = new naver.maps.LatLng(p[0], p[1]);
				marker.setPosition(pos);
				circle.setCenter(pos);
				map.setCenter(pos);
				fitCircle();
			}

			var o = areaEl && areaEl.selectedOptions[0];
			setLabel((o ? o.textContent.trim() : '선택한 역') + ' 기준으로 되돌렸습니다.');
		}

		if (toggle) {
			toggle.addEventListener('click', function () {
				var open = panel.hidden;

				panel.hidden = !open;
				toggle.classList.toggle('on', open);
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

				if (!open) { return; }

				// 숨어 있던 요소는 크기가 0 이라 지도가 회색으로만 그려진다.
				// 보인 다음에 만들고, 이미 만들었으면 다시 그리게 한다.
				if (!map) {
					build();
				} else {
					naver.maps.Event.trigger(map, 'resize');
					map.setCenter(marker.getPosition());
				}

				panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			});
		}

		if (btnHere) {
			btnHere.addEventListener('click', function () {
				if (!navigator.geolocation) {
					DS.toast('이 브라우저는 위치 기능을 지원하지 않습니다.', true);
					return;
				}

				btnHere.disabled = true;
				setLabel('위치를 확인하는 중…');

				navigator.geolocation.getCurrentPosition(function (pos) {
					btnHere.disabled = false;
					moveTo(pos.coords.latitude, pos.coords.longitude, true);
				}, function () {
					btnHere.disabled = false;
					setLabel('위치를 가져오지 못했습니다. 핀을 직접 옮겨주세요.');
				}, { timeout: 8000, enableHighAccuracy: false });
			});
		}

		if (btnReset) {
			btnReset.addEventListener('click', toArea);
		}

		/* 폼 밖의 "📍 내 위치 기준" 버튼이 좌표를 채우면 지도도 따라간다 */
		document.addEventListener('ds:geo', function (e) {
			if (!e.detail || !map) { return; }

			moveTo(e.detail.lat, e.detail.lng, true);
		});
	}
}());
