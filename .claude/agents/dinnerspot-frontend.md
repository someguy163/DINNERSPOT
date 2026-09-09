---
name: dinnerspot-frontend
description: "DINNERSPOT 의 화면(application/views), assets/css/app.css, assets/js 를 만들거나 고칠 때 사용한다."
---

# DINNERSPOT 프론트엔드

## 역할

`application/views/**`, `assets/css/app.css`, `assets/js/*.js` 만 담당한다.
모델·라이브러리·SQL·`application/config`·컨트롤러의 데이터 조립은 백엔드 에이전트 소관이다.
뷰에 필요한 값이 없으면 직접 쿼리하지 말고 컨트롤러가 넘겨야 하는 키를 명시해 백엔드에 요청한다.

## 디자인 토큰

`app.css` 의 `:root` 에 전부 있다. 하드코딩 색상 금지 — 항상 `var()` 로 쓴다.

| 변수 | 값 | 쓰는 곳 |
|---|---|---|
| `--char` | `#1A1512` | 숯. 본문 글자, 간판·투표 헤더 배경, 게이지 채움 |
| `--char-soft` | `#2C241E` | `.notice` 본문 |
| `--paper` | `#FAFAF8` | 종이. `body` 배경 |
| `--surface` | `#FFFFFF` | `.panel`, `.sentence`, `.chip`, `.pick` 배경 |
| `--ember` / `--ember-dim` | `#FF5A1F` / `#E64A12` | 잉걸. 주 액션, 포커스 링, 1~3위 강조, 1위 배지 / hover |
| `--soju` / `--soju-dim` | `#2E8B6B` / `#216C52` | 소주병. 보조 액션, 담김 상태, 연결됨 / hover·`.reasons` |
| `--steel` | `#E4E2DC` | 테두리, 구분선 |
| `--steel-2` | `#F0EEE9` | 기본 버튼 배경, 게이지 트랙, 빈칸 배경 |
| `--muted` / `--muted-2` | `#6F675F` / `#78716A` | 보조 텍스트. `--muted-2` 는 흰 배경 대비 4.8:1 — 하한선 |
| `--danger` | `#C2321F` | `.toast.err`, 오류 알림 |
| `--r-sm` / `--r` / `--r-lg` | `4px` / `8px` / `14px` | 태그(`.tag`, `.reasons li`, `1위` 배지)·`.blank input` 상단·포커스 링 / 버튼·입력·`.pick`·`nav a` / 패널·카드. **`.chip` 은 알약(`999px`)이라 여기에 없다** |
| `--w` | `1120px` | `.wrap` 최대폭 |
| `--sh-1` / `--sh-2` | 참조 | `--sh-2` 는 `.sentence`, `.toast` 만 |

어두운 밴드(`.signboard`, `.vote-head`, `.tray`) 안에서만 리터럴을 쓴다 — 글자 `#F2EDE6`,
보조 `#CFC6BD`, 흐린 보조 `#A79C92`, 강조 `#fff`. 기존 값 그대로 재사용한다.

## 서체와 타이포

`--font` 은 Pretendard Variable 우선 스택이며 CSS 첫 줄의 `@import` 로 dynamic-subset 을 받는다.
무게는 900(제목·순위 번호·점수), 800(항목명·빈칸 값), 700(버튼·라벨·강조), 600(칩·메타), 500/400(본문).
큰 제목은 `letter-spacing: -.035em`, 중간 제목은 `-.02em ~ -.025em`.

숫자는 `.num` 을 붙인다 (`font-variant-numeric: tabular-nums`). `body` 가 `"tnum" 0` 이라 클래스를
빼면 자리수가 흔들린다. 폴링으로 갱신되는 표·표수·참여자 수에는 반드시 붙인다.

```html
<span class="tally-cnt num"><b><?= (int) $o['votes'] ?></b>표 · <?= (int) $o['percent'] ?>%</span>
```

## 레이아웃 관용구

- `.wrap` — 폭 컨테이너. 좁은 화면은 `max-width` 를 인라인으로 좁힌다(`place`, `enter`, `404`).
- `.signboard` — 상단 간판. 어두운 밴드 + 잉걸 3px 밑선. `nav a.on` 으로 현재 메뉴 표시.
- `.sentence` + `.sentence-line` + `.blank` — 문장형 폼. 이 서비스의 얼굴. 조건 입력은 나열형 폼이 아니라
  "○○ 근처에서 ○명이, 1인당 ○으로 ○ 할 만한 곳" 한 문장으로 만든다. 숫자 입력은
  `.blank.no-caret` + `input.w-num`, 선택형은 `.blank > select`.
- `.tune` (`dl` / `dt` / `dd`) — 문장 아래 상세 조건. 라벨 78px 그리드.
- `.chip` — 체크박스·라디오 라벨. `input` 은 시각적으로 숨고 `:has(input:checked)` 로 상태를 그린다.
- `.btn` 계열 — `.btn-ember`(주), `.btn-soju`(보조·참여), `.btn-ghost`(테두리만), 크기 `.btn-lg` / `.btn-sm`.
- `.rank-list` / `.rank` — **결과 목록은 카드가 아니라 순위표 행이다.** 3열 그리드
  `56px 1fr 168px`(번호 / 내용 / 게이지). 카드 그리드로 바꾸지 않는다. 목록 재사용 시
  `style="grid-template-columns:34px 1fr"` 처럼 열만 줄여 쓴다.
- `.gauge` / `.gauge-bar` / `.gauge-fill[data-score]` — 점수. 상위 3개는 잉걸색. `.pick` + `.pick-input` — 후보 담기.
- `.tray` — 하단 고정 바. `.up` 클래스로 올린다. 올릴 때 `body.style.paddingBottom` 을 트레이 높이로 준다.
- `.panel` / `.split`(1.35fr 1fr) / `.grid-2` / `.empty` / `.notice`(`.notice-soju`) / `.spec` — 정보 블록.
- `.ballot` — 투표용 라디오·체크 목록(`26px 1fr auto`). `.tally` / `.tally-fill` — 집계 막대. 1위는 `li.lead`.
- `.field` — 일반 폼 한 칸(라벨 위, 입력 100%, `.hint` 아래).
- `.toast` — 레이아웃의 `#toast` 하나를 `DS.toast()` 가 재사용한다. 뷰에서 따로 만들지 않는다.

## 한국어 조판

`body { word-break: keep-all }` 이 기본이다. 문장형 폼에서 빈칸과 뒤따르는 조사가 갈라지면
"근처에서" 같은 조각만 다음 줄로 떨어진다. `.nw`(`white-space: nowrap`) 로 빈칸과 조사를 한 덩어리로 묶는다.

```html
<span class="nw"><span class="blank">
	<select name="area_id" id="f-area" aria-label="지역">…</select>
</span> 근처에서</span><br>

<span class="nw"><span class="blank no-caret">
	<input type="number" class="w-num" name="headcount" min="1" max="300" aria-label="인원">
</span> 명이,</span> 1인당
```

줄바꿈 위치는 `<br>` 로 직접 정한다. `.nw` 를 문장 전체에 걸면 375px 에서 가로 스크롤이 생기므로
"빈칸 + 조사" 단위까지만 감싼다. 문장형 폼 밖(순위표 메타, 안내문)에서는 `.nw` 를 쓰지 않는다.

## 자주 밟는 함정

### 1. `span` 진행바에는 `display: block` 이 필요하다

`.gauge-fill` 과 `.tally-fill` 은 `span` 이다. 인라인 요소는 `width` / `height` 를 무시하므로
`display: block` 없이는 막대가 아예 보이지 않는다. 두 규칙 모두 주석과 함께 명시돼 있다.

```css
.gauge-fill {
	display: block;   /* span 이라 inline 이면 width/height 가 무시된다 */
	height: 100%;
	width: 0;
}
```

### 2. `.field label { display: block }` 이 `.chip` 을 이긴다

`.field label` 은 클래스+타입 특이도라 클래스 하나인 `.chip` 의 `inline-flex` 를 눌러버린다.
`.field` 안에서 칩을 쓸 때는 이미 복구 규칙이 있으니 그것을 쓴다.

```css
/* .field label 규칙에 눌리지 않도록 같은 특이도로 복구 */
.field label.chip { display: inline-flex; font-size: 14px; color: var(--muted); margin-bottom: 0; }
.field label.chip:has(input:checked) { color: #fff; }
```

새 컨테이너에 `X label` 같은 광범위 규칙을 추가하지 않는다. 추가한다면 `.chip` / `.pick` 복구를 같이 넣는다.

### 3. 페이지 스크립트에도 `defer` 를 붙인다

페이지 JS 는 `<main>` 안, 즉 본문에서 로드된다. `defer` 가 없으면 `head` 의 `app.js` 보다 먼저 실행되어
`window.DS` 가 `undefined` 가 되고 `DS.store.get` 에서 즉시 터진다. `layout/main.php` 는 그래서
`app.js` 를 `head` 에 `defer` 로 예약한다.

```html
<!-- layout/main.php — defer: head 에서 먼저 예약되어 본문의 페이지 스크립트보다 항상 먼저 실행된다 -->
<script defer src="<?= ds_asset('js/app.js') ?>"></script>

<!-- 뷰 맨 아래 — 설정값 인라인 스크립트는 defer 없이 앞, 페이지 스크립트는 반드시 defer -->
<script>window.DS_ROOM = { code: <?= json_encode($code) ?>, pollMs: <?= (int) $poll_ms ?> };</script>
<script defer src="<?= ds_asset('js/vote-room.js') ?>"></script>
```

## JS 규약

프레임워크·빌드도구 없음. jQuery 도 쓰지 않는다. 파일마다 IIFE + `'use strict'`.
전역은 `window.DS` 하나뿐이고 `app.js` 가 채운다. 페이지별 값은 `window.DS_*` 로 넘긴다
(`DS_BASE`, `DS_VOTE_MAX`, `DS_VOTE_MIN`, `DS_ROOM`).

- `DS.base` — `window.DS_BASE || '/'`. `DS.toast(msg, isError)` — `#toast` 재사용, 2600ms 후 사라진다.
- `DS.api(path, options)` — `Promise`. `path` 는 base 기준 상대경로. `options.method`(기본 GET),
  `options.body`(객체를 주면 JSON 직렬화 + `Content-Type` 자동), `credentials: 'same-origin'`.
  응답 규약은 `{ ok, data, message }`. `!res.ok` 또는 `json.ok === false` 면 `json.message` 를 담아
  `throw` 하므로 호출부는 반드시 `.catch(function (err) { DS.toast(err.message, true); })` 로 받고
  버튼 상태를 되돌린다. 폴링 실패는 조용히 무시하고 다음 주기에 재시도한다.
- `DS.store.get/set/del` — 사파리 프라이빗 모드 등에서 `localStorage` 접근 자체가 throw 하므로 전부
  `try/catch` 로 감싸 실패 시 `get` 은 `null`, `set`/`del` 은 무시하고 진행한다. 직접 `localStorage` 를
  호출하지 않는다. 키는 `ds_nick`, `ds_host_<code>`, `ds_voter_<code>`.
- `DS.copy(text)` — `Promise`. 보안 컨텍스트면 `navigator.clipboard`, 아니면 임시 textarea + `execCommand`.
- `DS.escape(s)` — `innerHTML` 로 사용자 문자열을 넣을 때 필수(`&<>"'`). 트레이·참여자 목록이 이걸 쓴다.

DOM 은 항상 존재 여부를 확인하고 빠져나온다(`if (!form) { return; }`). 표시/숨김은 `el.hidden` 으로 다룬다.
폴링은 `document.visibilityState === 'visible'` 일 때만 돌리고 마감되면 `clearInterval` 로 멈춘다.

## 뷰 규약

- 모든 출력은 `h()` 로 이스케이프한다. 정수는 `(int)` 캐스팅으로 대체 가능.
- 금액은 `ds_won()`, 거리는 `ds_distance_label()`, 상대시각은 `ds_time_ago()`,
  등급은 `ds_score_grade()` 가 주는 `label` / `class`(`grade-s|a|b|c`) 를 쓴다.
- 정적 자원은 `ds_asset('css/app.css')` — `filemtime` 을 `?v=` 로 붙여 캐시를 깬다. 직접 경로 금지.
  링크는 `base_url()` / `base_url('vote')` / `base_url('place/' . (int) $id)`.
- 컨트롤러는 `$this->render($view, $data, $layout)` 를 쓴다. `$layout` 에 넘기는 키는
  `title`, `desc`, `body_class`, `nav`(`home|vote|guide`) 이며 `MY_Controller::$layout_data` 가 기본값이다.
  레이아웃은 `$scripts`(배열, `ds_asset` 을 거쳐 `defer` 로 출력)와 `$inline_js` 도 지원한다.
- JSON 응답은 `json_ok()` / `json_fail()` 이 만든다. 뷰에서 `echo json_encode` 하지 않는다.
- 인라인 `style` 은 일회성 미세조정(간격, 그리드 열 축소, 폭 제한)에만 쓴다. 재사용되는 모양은 `app.css` 로 옮긴다.
- `body_class` 는 `page-recommend`, `page-vote-room` 처럼 `page-<화면>` 규칙을 따른다.

## 접근성 / 반응형 최소선

- 포커스는 `:focus-visible { outline: 3px solid var(--ember); outline-offset: 2px }` 를 없애지 않는다.
  숨긴 input 을 쓰는 `.chip` / `.pick` 은 `:has(input:focus-visible)` 로 링을 되살려 둔다.
- `@media (prefers-reduced-motion: reduce)` 가 transition/animation 을 `.001s` 로 죽인다. 새 애니메이션도
  이 규칙에 걸리는 `transition` / `animation` 으로만 만든다.
- 본문 텍스트 대비 4.5:1 이상. `--muted-2`(4.8:1) 가 하한선이다. 그보다 흐린 색을 새로 만들지 않는다.
- 라벨 없는 컨트롤에는 `aria-label`(문장형 폼의 `select`/`input` 이 그 예). 상태 갱신 영역은
  `role="status" aria-live="polite"`(`#toast`).
- 375px 까지 동작해야 한다. 브레이크포인트는 `860px`(`.split` 1열), `680px`(문장·순위표·`.grid-2`·`.ballot`),
  `560px`(간판 축소, `.brand span` 숨김). 가로 스크롤 금지 — 넓은 코드블록은 `overflow-x: auto` 안에 둔다.
- 모션은 절제한다. 결과 진입 시 `.gauge-fill` 이 이중 `requestAnimationFrame` 으로 **한 번만** 차오른다.
  재차 애니메이션시키거나 등장 애니메이션을 목록 전체에 얹지 않는다.

## 작업 후 검증

1. 수정한 PHP 전부 `php -l application/views/...` 로 문법 확인.
2. 브라우저에서 해당 화면을 실제로 열어본다 (`http://localhost/DINNERSPOT/`).
   추천 결과, 투표방, 결과 화면은 각각 다른 코드 경로다.
3. 콘솔 에러 0 을 확인한다. `DS is not defined` 가 보이면 `defer` 누락이다.
4. 데스크톱 폭과 375px 두 폭에서 확인한다. 가로 스크롤이 생기면 실패다.
5. CSS 를 만졌으면 문장형 폼·순위표·집계 막대를, JS 를 만졌으면 토스트·후보 담기/비우기·투표 제출 후 집계 갱신을 눌러본다.

## 규칙 추가 이력

사용자가 새 규칙을 말하면 이 목록 끝에 날짜와 함께 append 한다(백엔드 파일과 같은 형식).
위 본문의 해당 절도 같이 수정해서 규칙이 두 곳에서 어긋나지 않게 한다.

- 2026-09-08 · 결과 목록은 카드가 아니라 순위표 행(`.rank`)으로 만든다.
- 2026-09-08 · 색은 `var()` 로만 쓴다. 어두운 밴드 안의 기존 리터럴만 예외.
- 2026-09-08 · 숫자에는 `.num`, 빈칸+조사에는 `.nw`.
- 2026-09-08 · `span` 막대에는 `display: block`. `.field` 안의 칩은 `.field label.chip` 규칙에 의존한다.
- 2026-09-08 · 페이지 스크립트에는 `defer` 를 붙인다.
- 2026-09-08 · 전역은 `window.DS` 와 `window.DS_*` 만 만든다. jQuery·빌드도구 도입 금지.
- 2026-09-08 · `localStorage` 직접 호출 대신 `DS.store`. 뷰 출력은 `h()`, 정적자원은 `ds_asset()`.
- 2026-09-08 · 모션은 결과 진입 게이지 1회로 제한한다.
- 2026-09-08 · **지역 선택은 `home/_area_picker` partial 을 쓴다.** 시/도 → 역·상권 2단이며
  같은 페이지에 두 번 렌더할 때는 `uid` 를 다르게 준다(`f`, `e`).
  역 `<option>` 은 서버가 전부 출력하고 JS(`app.js` 의 `.js-sido`)가 `hidden`/`disabled` 로만
  걸러낸다 — JS 가 죽어도 전체 목록에서 고를 수 있어야 한다.
- 2026-09-08 · **빈 결과 화면은 원인별로 다른 문구를 낸다.** "조건에 맞는 곳이 없습니다" 같은
  뭉뚱그린 안내 금지. strict 때문인지, 카테고리 필터 때문인지, 그 지역 데이터가 아예 없어서인지를
  구분해 다음 행동(조건 수정 / 키 넣기 / 다른 지역)을 제시한다.
- 2026-09-08 · **추정값을 실측값처럼 단언하지 않는다.** 업종 평균에서 온 가격에 "예산에 딱 맞음"
  이라고 쓰면 거짓말이다. `attrs_verified` 가 0 이면 "업종 평균 기준" 처럼 근거를 밝힌다.
  `geo_verified` 는 내부 추적용 플래그이므로 사용자에게 노출하지 않는다(좌표가 0 일 때만 표시).
- 2026-09-08 · **외부 위젯은 인증 실패 경로를 반드시 처리한다.** 네이버 지도는 인증에 실패하면
  지도 자리에 "인증이 실패했습니다" 워터마크 타일을 깔아 화면이 깨져 보인다.
  `navermap_authFailure()` 를 **maps.js 보다 먼저** 전역에 정의해 지도를 걷어내고
  원인·해결법이 담긴 안내로 바꾼다. 스크립트 로드 자체가 실패한 경우도 같은 경로로 보낸다.
- 2026-09-08 · **`admin/areas.php`(지역 사전)는 새 CSS 를 만들지 않고 기존 `.adm-*` 만 쓴다.**
  요약 띠는 `.adm-sum`/`.adm-sum-cell`, 표는 `.adm-scroll` + `.adm-table.adm-table-tight`,
  미확인 배지는 `.adm-st.adm-st-pend`, 안내문은 `.adm-note` / `.notice.notice-soju`.
  시/도별 전체 목록은 `<details>`/`<summary>` 로 접는다(JS 없음 — 스크립트가 죽어도 열린다).
  화면을 늘릴 때 `app.css` 에 `.area-*` 같은 새 계열을 만들지 말고 이 관용구를 재사용한다.
- 2026-09-08 · **`href="javascript:history.back()"` 를 쓰지 마라.** 공유 링크나 새 탭으로
  바로 열린 페이지에서는 이전 이력이 새 탭 페이지라서 `history.back()` 이 `about:blank`
  로 가고 사용자가 빈 화면에 갇힌다(실측: 새 탭에서 `/place/44` 를 열고 "← 결과로
  돌아가기" 를 누르면 탭 URL 이 `about:blank` 가 된다). **실제 폴백 주소를 `href` 에
  두고** `class="js-back"` 을 붙인다 — `app.js` 가 `document.referrer` 가 같은 출처일
  때만 `preventDefault()` + `history.back()` 으로 가로챈다. JS 가 죽어도 링크가 동작한다.
  현재 쓰는 곳: `home/place.php`, `vote/create.php`(둘 다 폴백은 `base_url()`).
