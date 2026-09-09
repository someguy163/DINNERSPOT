/* =========================================================
   DINNERSPOT - 추천 결과 화면
   점수 게이지 애니메이션 + 후보 담기 트레이

   트레이 선택은 localStorage 에 남긴다. 결과가 여러 페이지로
   나뉘면 1페이지에서 담고 2페이지로 넘어가는 순간 메모리 상태가
   사라져 선택이 초기화되기 때문이다. 검색 조건이 바뀌면
   이전 선택은 버린다 (다른 조건의 후보를 물려받으면 안 된다).
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

	var STORE_KEY = 'ds_tray';

	/* 검색 조건 지문.
	   서버가 정규화된 조건으로 만들어 내려준 값을 쓴다 (page·per_page 제외).
	   폼이 만든 주소와 페이지 링크가 만든 주소는 같은 검색인데도 글자가
	   달라서, 쿼리스트링을 직접 쓰면 페이지를 넘길 때 선택이 사라진다.
	   서버 값이 없을 때만 주소에서 만든다. */
	function signature() {
		if (window.DS_TRAY_SIG) { return window.DS_TRAY_SIG; }

		var p = new URLSearchParams(location.search);

		p.delete('page');
		p.delete('per_page');

		var pairs = [];

		p.forEach(function (v, k) { pairs.push(k + '=' + v); });
		pairs.sort();

		return pairs.join('&');
	}

	var sig = signature();

	/* picked: [{id: 12, name: '장인닭갈비'}]
	   이름을 함께 들고 있어야 다른 페이지에서 담은 곳도 트레이에 이름이 뜬다.
	   그 곳의 체크박스는 지금 화면에 없기 때문이다. */
	var picked = [];

	function load() {
		var raw = DS.store.get(STORE_KEY);

		if (!raw) { return; }

		var saved;

		try { saved = JSON.parse(raw); } catch (e) { saved = null; }

		if (!saved || !Array.isArray(saved.items)) { return; }

		// 조건이 바뀌었으면 이전 선택은 버린다
		if (saved.sig !== sig) {
			DS.store.del(STORE_KEY);
			return;
		}

		picked = saved.items.filter(function (it) {
			return it && typeof it.id === 'number';
		}).slice(0, MAX);
	}

	function save() {
		if (!picked.length) {
			DS.store.del(STORE_KEY);
			return;
		}

		DS.store.set(STORE_KEY, JSON.stringify({ sig: sig, items: picked }));
	}

	function indexOf(id) {
		for (var i = 0; i < picked.length; i++) {
			if (picked[i].id === id) { return i; }
		}

		return -1;
	}

	function ids() {
		return picked.map(function (it) { return it.id; });
	}

	function render() {
		if (!picked.length) {
			tray.classList.remove('up');
			document.body.style.paddingBottom = '';
			return;
		}

		var names = picked.map(function (it) { return it.name; }).filter(Boolean);
		var head = '<b>후보 ' + picked.length + '곳</b> · ' + DS.escape(names.join(', '));

		trayList.innerHTML = picked.length < MIN
			? head + ' — ' + MIN + '곳 이상 담아야 투표를 만들 수 있습니다'
			: head;

		trayGo.href = DS.base + 'vote/new?ids=' + ids().join(',');
		trayGo.setAttribute('aria-disabled', picked.length < MIN ? 'true' : 'false');
		trayGo.style.opacity = picked.length < MIN ? '.45' : '1';
		trayGo.style.pointerEvents = picked.length < MIN ? 'none' : '';

		tray.classList.add('up');
		document.body.style.paddingBottom = tray.offsetHeight + 'px';
	}

	/* 저장된 선택을 이 페이지의 체크박스에 되돌린다 */
	function restore() {
		inputs.forEach(function (input) {
			input.checked = (indexOf(parseInt(input.value, 10)) !== -1);
		});
	}

	inputs.forEach(function (input) {
		input.addEventListener('change', function () {
			var id = parseInt(this.value, 10);
			var li = this.closest('.rank');
			var at = indexOf(id);

			if (this.checked) {
				if (at === -1) {
					if (picked.length >= MAX) {
						this.checked = false;
						DS.toast('후보는 최대 ' + MAX + '곳까지 담을 수 있습니다.', true);
						return;
					}

					picked.push({ id: id, name: li ? li.dataset.name : '' });
				}
			} else if (at !== -1) {
				picked.splice(at, 1);
			}

			save();
			render();
		});
	});

	if (trayClear) {
		trayClear.addEventListener('click', function () {
			picked = [];
			inputs.forEach(function (i) { i.checked = false; });
			save();
			render();
		});
	}

	load();
	restore();
	render();
}());

/* =========================================================
   목록 내 찾기 — 자동완성

   제안은 전체 순위(window.DS_FIND_INDEX)에서 즉시 만들고,
   실제 걸러내기는 폼을 제출해 서버가 한다. 그래야 페이지 수와
   순위 번호가 맞는다 — 화면에 있는 10건만 걸러내면 21위 이하는
   찾을 수 없고, 페이지 수도 거짓이 된다.

   트레이 블록과 분리한 이유: 걸러낸 결과가 0건이면 순위표가 없어
   그쪽 IIFE 가 조기 반환하는데, 그때야말로 검색창이 살아 있어야 한다.
   ========================================================= */
(function () {
	'use strict';

	var form = document.getElementById('find-form');
	var input = document.getElementById('find-input');
	var box = document.getElementById('find-ac');
	var index = window.DS_FIND_INDEX;

	if (!form || !input || !box || !Array.isArray(index) || !index.length) { return; }

	var MAX_SHOW = 8;
	var items = [];   // 지금 보이는 제안
	var active = -1;  // 키보드로 고른 항목

	/* 서버(Spot_service::filter_by_find)와 같은 규칙:
	   소문자 + 공백 제거. '강남 역' 과 '강남역' 을 같게 본다. */
	function norm(s) {
		return String(s == null ? '' : s).toLowerCase().replace(/\s+/g, '');
	}

	function terms(q) {
		return q.trim().split(/\s+/).map(norm).filter(function (t) { return t !== ''; });
	}

	function matches(q) {
		var ts = terms(q);

		if (!ts.length) { return []; }

		return index.filter(function (it) {
			var hay = norm(it.name + ' ' + it.cat + ' ' + it.addr);

			return ts.every(function (t) { return hay.indexOf(t) !== -1; });
		});
	}

	/* 이름에서 찾는 말을 강조한다. 사용자 문자열이므로 innerHTML 을 쓰지
	   않고 텍스트 노드로 조립한다. */
	function nameNode(name, term) {
		var wrap = document.createElement('span');
		wrap.className = 'ac-name';

		var at = term ? name.toLowerCase().indexOf(term.toLowerCase()) : -1;

		if (at === -1) {
			wrap.textContent = name;
			return wrap;
		}

		wrap.appendChild(document.createTextNode(name.slice(0, at)));

		var mark = document.createElement('mark');
		mark.textContent = name.slice(at, at + term.length);
		wrap.appendChild(mark);

		wrap.appendChild(document.createTextNode(name.slice(at + term.length)));

		return wrap;
	}

	function close() {
		box.hidden = true;
		box.innerHTML = '';
		items = [];
		active = -1;
		input.setAttribute('aria-expanded', 'false');
		input.removeAttribute('aria-activedescendant');
	}

	function paint() {
		var q = input.value;
		var hits = matches(q);

		if (!hits.length) {
			close();
			return;
		}

		var first = q.trim().split(/\s+/)[0] || '';

		box.innerHTML = '';
		items = hits.slice(0, MAX_SHOW);

		items.forEach(function (it, i) {
			var li = document.createElement('li');
			li.className = 'ac-item';
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', 'false');
			/* id 가 있어야 input 의 aria-activedescendant 가 이 항목을 가리킬 수 있다.
			   없으면 화살표로 고른 항목이 시각적으로만 강조되고 낭독되지 않는다. */
			li.id = 'ac-opt-' + i;
			li.dataset.i = i;

			var no = document.createElement('span');
			no.className = 'ac-rank num';
			no.textContent = it.rank + '위';
			li.appendChild(no);

			var mid = document.createElement('span');
			mid.className = 'ac-mid';
			mid.appendChild(nameNode(it.name, first));

			var sub = document.createElement('span');
			sub.className = 'ac-sub';
			sub.textContent = it.cat + (it.addr ? ' · ' + it.addr : '');
			mid.appendChild(sub);

			li.appendChild(mid);

			var sc = document.createElement('span');
			sc.className = 'ac-score num';
			sc.textContent = it.score;
			li.appendChild(sc);

			box.appendChild(li);
		});

		if (hits.length > items.length) {
			var more = document.createElement('li');
			more.className = 'ac-more';
			more.textContent = '그 외 ' + (hits.length - items.length) + '곳 — 찾기를 누르면 전부 봅니다';
			box.appendChild(more);
		}

		box.hidden = false;
		input.setAttribute('aria-expanded', 'true');
		active = -1;
	}

	function highlight() {
		Array.prototype.forEach.call(box.querySelectorAll('.ac-item'), function (li, i) {
			var on = (i === active);
			li.classList.toggle('on', on);
			li.setAttribute('aria-selected', on ? 'true' : 'false');
		});

		if (active >= 0 && items[active]) {
			input.setAttribute('aria-activedescendant', 'ac-opt-' + active);
		} else {
			input.removeAttribute('aria-activedescendant');
		}
	}

	function choose(i) {
		if (!items[i]) { return; }

		input.value = items[i].name;
		close();
		form.submit();
	}

	input.addEventListener('input', paint);

	input.addEventListener('focus', function () {
		if (input.value.trim() !== '') { paint(); }
	});

	input.addEventListener('keydown', function (e) {
		if (box.hidden) {
			if (e.key === 'ArrowDown' && input.value.trim() !== '') { paint(); }
			return;
		}

		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			active += (e.key === 'ArrowDown' ? 1 : -1);

			if (active < -1) { active = items.length - 1; }
			if (active >= items.length) { active = -1; }

			highlight();
			return;
		}

		if (e.key === 'Enter') {
			// 아무것도 고르지 않았으면 폼 기본 동작(전체 걸러내기)에 맡긴다
			if (active >= 0) {
				e.preventDefault();
				choose(active);
			}

			return;
		}

		if (e.key === 'Escape') {
			close();
		}
	});

	box.addEventListener('mousedown', function (e) {
		// mousedown 으로 잡는다 — click 은 blur 가 먼저 닫아버린다
		var li = e.target.closest ? e.target.closest('.ac-item') : null;

		if (!li) { return; }

		e.preventDefault();
		choose(parseInt(li.dataset.i, 10));
	});

	box.addEventListener('mousemove', function (e) {
		var li = e.target.closest ? e.target.closest('.ac-item') : null;

		if (!li) { return; }

		active = parseInt(li.dataset.i, 10);
		highlight();
	});

	document.addEventListener('click', function (e) {
		if (!form.contains(e.target)) { close(); }
	});
}());
