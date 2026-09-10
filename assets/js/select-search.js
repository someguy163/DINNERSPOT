/**
 * 검색칸이 달린 드롭다운 (.js-searchable 이 붙은 <select> 에 씌운다)
 *
 * 왜 만드는가:
 *   지역 사전이 632곳이라 기본 <select> 로는 원하는 역을 찾을 수 없다.
 *   HTML 의 <select> 는 브라우저가 그리는 위젯이라 **안에 검색칸을 넣을 수
 *   없다.** 그래서 목록 부분만 직접 그린다.
 *
 * 무엇을 건드리지 않는가:
 *   원래 <select> 를 지우지 않는다. 화면에서만 감추고(.dsel-native)
 *   폼 안에 그대로 남겨 둔다. 고른 값은 그 select 에 쓰고 change 를 쏘므로
 *   - 폼 제출(name="area_id")
 *   - app.js 의 sync() / releasePoint()
 *   - map-picker.js 의 좌표 해제
 *   전부 손대지 않은 채로 동작한다.
 *
 *   JS 가 죽으면 이 파일이 실행되지 않아 원래 select 가 그대로 보인다.
 *   (역 <option> 은 서버가 전부 출력해 두므로 검색 없이도 고를 수 있다.)
 *
 * data 속성
 *   data-search-label   검색칸의 placeholder
 *   data-search-cross   "1" 이면 검색할 때 hidden(=시/도 필터)을 무시하고
 *                       전체에서 훑는다. 역 select 이 이걸 쓴다 — 신촌역이
 *                       어느 시/도인지 모르는 채로 찾는 것이 검색의 목적이다.
 *                       그때는 어느 시/도인지 함께 보여준다(운천역 = 경기·광주).
 */
(function () {
	'use strict';

	var DOWN = 'ArrowDown', UP = 'ArrowUp', ESC = 'Escape', ENTER = 'Enter';
	var seq = 0;

	function labelOf(o) {
		/* 서버가 낸 라벨을 그대로 쓴다. 좌표가 없는 지역은 서버가
		   ' (좌표 없음)' 을 붙여 두는데, 그 표시를 잃지 않으려면
		   textContent 를 다시 조립하지 말고 있는 것을 써야 한다. */
		return (o.textContent || '').trim();
	}

	function build(sel) {
		if (sel.dsel) { return; }

		var cross = sel.dataset.searchCross === '1';
		var id    = 'dsel-' + (++seq);

		var wrap = document.createElement('span');
		wrap.className = 'dsel';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'dsel-btn';
		btn.setAttribute('aria-haspopup', 'listbox');
		btn.setAttribute('aria-expanded', 'false');
		btn.id = id + '-btn';

		var ariaBase = sel.getAttribute('aria-label') || '';

		var panel = document.createElement('div');
		panel.className = 'dsel-panel';
		panel.hidden = true;

		var q = document.createElement('input');
		q.type = 'search';
		q.className = 'dsel-q';
		q.autocomplete = 'off';
		q.placeholder = sel.dataset.searchLabel || '검색';
		q.setAttribute('aria-label', q.placeholder);

		var list = document.createElement('ul');
		list.className = 'dsel-list';
		list.setAttribute('role', 'listbox');
		list.id = id + '-list';

		var empty = document.createElement('p');
		empty.className = 'dsel-empty';
		empty.hidden = true;
		empty.textContent = '일치하는 곳이 없습니다';

		panel.appendChild(q);
		panel.appendChild(list);
		panel.appendChild(empty);

		sel.parentNode.insertBefore(wrap, sel);
		wrap.appendChild(btn);
		wrap.appendChild(panel);
		wrap.appendChild(sel);
		sel.classList.add('dsel-native');

		/* 화면에서만 감춘 select 이 보조기술에는 그대로 노출되어 같은 선택이
		   두 번 읽혔다(실측: 접근성 트리에 combobox 와 button 이 나란히).
		   제출에는 영향이 없으므로 보조기술과 탭 순서에서 뺀다. */
		sel.setAttribute('aria-hidden', 'true');
		sel.setAttribute('tabindex', '-1');

		var items = [];      // { opt, li, text, sido }
		var active = -1;      // 키보드 이동 위치 (items 안의 index)

		function syncButton() {
			var o = sel.selectedOptions[0];
			var t = o ? labelOf(o) : '선택';

			btn.textContent = t;

			/* 보이는 글자는 값("강남역")인데 aria-label 을 "역 · 상권" 으로만
			   두면 스크린리더가 무엇이 골라져 있는지 읽지 못한다. 둘을 합친다. */
			btn.setAttribute('aria-label', ariaBase ? (ariaBase + ': ' + t) : t);
		}

		function visibleItems() {
			return items.filter(function (it) { return !it.li.hidden; });
		}

		function setActive(i) {
			var vis = visibleItems();

			if (!vis.length) { active = -1; return; }

			if (i < 0) { i = 0; }
			if (i > vis.length - 1) { i = vis.length - 1; }

			items.forEach(function (it) { it.li.classList.remove('is-active'); });
			vis[i].li.classList.add('is-active');
			vis[i].li.scrollIntoView({ block: 'nearest' });
			active = i;
			list.setAttribute('aria-activedescendant', vis[i].li.id);
		}

		function render(query) {
			query = (query || '').trim().toLowerCase();

			var shown = 0;

			items.forEach(function (it) {
				var show;

				if (query === '') {
					/* 검색어가 없으면 select 의 현재 상태를 그대로 비춘다.
					   역 select 은 app.js 의 sync() 가 시/도로 걸러 hidden 을
					   붙여 두므로, 그 필터가 화면에도 그대로 보인다. */
					show = !it.opt.hidden || !!it.opt.selected;
					it.li.textContent = it.text;
				}
				else {
					var hay = it.text.toLowerCase() + ' ' + it.sido.toLowerCase();
					var hit = hay.indexOf(query) !== -1;

					// cross 가 아니면 지금 보이는 것 안에서만 찾는다
					show = hit && (cross || !it.opt.hidden);
					it.li.textContent = (show && cross && it.sido)
						? (it.text + ' · ' + it.sido)
						: it.text;
				}

				it.li.hidden = !show;
				it.li.setAttribute('aria-selected', it.opt.selected ? 'true' : 'false');

				if (show) { shown++; }
			});

			empty.hidden = (shown > 0);
			setActive(0);
		}

		function pick(it) {
			/* 값은 원래 select 에 쓴다. change 를 쏘면 app.js 가 좌표 해제와
			   시/도 맞추기를 하고, 폼 제출도 이 select 의 값을 보낸다. */
			sel.value = it.opt.value;
			sel.dispatchEvent(new Event('change', { bubbles: true }));
			close();
			btn.focus();
		}

		function open() {
			if (!panel.hidden) { return; }

			panel.hidden = false;
			btn.setAttribute('aria-expanded', 'true');
			q.value = '';
			render('');
			place();

			/* 열린 직후의 측정은 목록 렌더·폰트 때문에 한 박자 이를 수 있다.
			   다음 프레임에 한 번 더 맞춘다. */
			if (window.requestAnimationFrame) { requestAnimationFrame(place); }

			// 선택된 항목이 보이면 그 위치에서 시작한다
			var vis = visibleItems();

			for (var i = 0; i < vis.length; i++) {
				if (vis[i].opt.selected) { setActive(i); break; }
			}

			q.focus();
		}

		/* 패널이 화면 오른쪽으로 넘치면 안으로 밀어 넣는다.
		   375px 에서 역 드롭다운이 오른쪽 끝에 1px 만 남기고 걸쳤다(실측).
		   문장 안에 있어 버튼 위치가 글자 길이에 따라 움직이므로, 폭을
		   CSS 로 줄이는 것만으로는 늘 안전하다고 말할 수 없다. */
		function place() {
			panel.style.left = '0px';

			var r = panel.getBoundingClientRect();
			var over = r.right - (window.innerWidth - 8);

			if (over > 0) {
				panel.style.left = (-Math.min(over, r.left - 8)) + 'px';
			}
		}

		function close() {
			if (panel.hidden) { return; }

			panel.hidden = true;
			btn.setAttribute('aria-expanded', 'false');
		}

		function rebuild() {
			list.textContent = '';
			items = [];

			Array.prototype.forEach.call(sel.options, function (o, i) {
				// disabled 이고 hidden 인 항목("직접 지정한 위치" 미사용 상태)은 담지 않는다
				if (o.hidden && o.disabled && o.dataset.any) { return; }

				var li = document.createElement('li');
				li.className = 'dsel-item';
				li.id = id + '-o' + i;
				li.setAttribute('role', 'option');
				li.textContent = labelOf(o);

				var it = {
					opt: o, li: li,
					text: labelOf(o),
					sido: o.dataset.sido || ''
				};

				li.addEventListener('mousedown', function (e) {
					e.preventDefault();   // 검색칸의 포커스를 잃지 않게
					pick(it);
				});

				list.appendChild(li);
				items.push(it);
			});

			syncButton();
		}

		btn.addEventListener('click', function () {
			panel.hidden ? open() : close();
		});

		q.addEventListener('input', function () { render(q.value); });

		q.addEventListener('keydown', function (e) {
			if (e.key === DOWN)  { e.preventDefault(); setActive(active + 1); return; }
			if (e.key === UP)    { e.preventDefault(); setActive(active - 1); return; }
			if (e.key === ESC)   { e.preventDefault(); close(); btn.focus(); return; }

			if (e.key === ENTER) {
				e.preventDefault();   // 폼이 제출되지 않게

				var vis = visibleItems();

				if (vis.length && active >= 0) { pick(vis[active]); }
			}
		});

		btn.addEventListener('keydown', function (e) {
			if (e.key === DOWN || e.key === ENTER) { e.preventDefault(); open(); }
		});

		document.addEventListener('click', function (e) {
			if (!wrap.contains(e.target)) { close(); }
		});

		/* select 이 밖에서 바뀔 수 있다 — app.js 의 sync() 가 시/도를 바꾸면
		   역 선택을 옮기고, map-picker.js 는 "직접 지정한 위치" 를 켠다. */
		sel.addEventListener('change', function () { syncButton(); });

		/* 그런데 그 코드는 `el.value = ...` 만 하고 change 를 쏘지 않는다.
		   그래서 시/도를 검색으로 고르면 시/도 버튼 라벨이 옛 값에 머물렀다
		   (실측: 화서역을 고르면 select 은 '경기' 인데 버튼은 '서울 (303)').
		   호출처가 app.js·map-picker.js 에 6곳이라 하나씩 이벤트를 추가하면
		   앞으로 또 빠뜨린다. 이 요소의 value setter 만 감싸서 스스로 알게 한다. */
		var vd = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(sel), 'value');

		if (vd && vd.get && vd.set) {
			Object.defineProperty(sel, 'value', {
				configurable: true,
				get: function () { return vd.get.call(this); },
				set: function (v) { vd.set.call(this, v); syncButton(); }
			});
		}

		/* "직접 지정한 위치" 항목은 hidden/disabled 가 오가므로 목록을 다시
		   짜야 한다. 속성 변화를 지켜본다. */
		if (window.MutationObserver) {
			var mo = new MutationObserver(function () { rebuild(); if (!panel.hidden) { render(q.value); } });

			Array.prototype.forEach.call(sel.options, function (o) {
				if (o.dataset.any) { mo.observe(o, { attributes: true, attributeFilter: ['hidden', 'disabled'] }); }
			});
		}

		rebuild();
		sel.dsel = { open: open, close: close, rebuild: rebuild };
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('select.js-searchable'), build);
	}

	(document.readyState === 'loading')
		? document.addEventListener('DOMContentLoaded', init)
		: init();
}());
