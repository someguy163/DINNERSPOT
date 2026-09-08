# DINNERSPOT

회식 장소를 조건에 맞춰 점수순으로 추천하고, 후보를 팀에 링크로 보내 투표로 정하는 서비스.

- **백엔드** CodeIgniter 3.1.13 + MariaDB / MySQL
- **프론트엔드** CI3 서버 렌더링 뷰 + 바닐라 JS (빌드 도구 없음)
- **장소 데이터** 네이버 지역검색 API + DB 캐시 (키가 없으면 샘플 데이터로 동작)

---

## 1. 설치

```bash
mysql -uroot --default-character-set=utf8mb4 < sql/01_schema.sql
mysql -uroot --default-character-set=utf8mb4 dinnerspot < sql/02_seed.sql
```

`http://localhost/DINNERSPOT/` 로 접속하면 바로 동작합니다.

두 파일 모두 `SET NAMES utf8mb4` 를 스스로 실행하므로 `--default-character-set` 없이도
통과하지만, XAMPP 의 클라이언트 기본 charset 이 `euckr` 이라 붙여 두는 편이 안전합니다.
둘 다 재실행 가능합니다 — `01_schema.sql` 은 FK 자식부터 DROP 하고,
`02_seed.sql` 은 `ON DUPLICATE KEY UPDATE` 로 덮어씁니다.
`01_schema.sql` 은 DROP 을 포함하므로 재실행하면 **투표 기록까지 전부 사라집니다.**

DB 접속 정보를 바꿔야 하면 `application/config/database.php`, 접속 URL이 다르면
`application/config/config.php` 의 `base_url` 을 수정하세요.
Apache에 `mod_rewrite` 와 `AllowOverride All` 이 필요합니다 (XAMPP 기본값에 포함).

## 2. 네이버 API 연결 (선택)

키가 없어도 샘플 장소 43곳으로 전체 기능이 돌아갑니다. 실제 상권 데이터를 쓰려면 키를 넣으세요.

> **키는 반드시 `application/config/dinnerspot_local.php` 에 넣습니다.**
> `dinnerspot.php` 에 넣으면 무시됩니다 — 그 파일 맨 아래에서 local 파일을 include 하므로
> local 쪽 값(빈 문자열이라도)이 항상 이깁니다. 게다가 `dinnerspot.php` 는 git 에 올라갑니다.

```php
<?php
$config['naver_client_id']     = '발급받은 Client ID';
$config['naver_client_secret'] = '발급받은 Client Secret';
$config['naver_map_key_id']    = '지도용 Key ID';   // 선택
```

### 지역검색 키는 두 곳 중 아무 데서나

같은 지역검색을 **발급처 두 곳**에서 받을 수 있습니다. 엔드포인트와 인증 헤더가 다르지만
응답 형식은 같아서, `naver_api_mode = 'auto'`(기본)가 자격증명 형태를 보고 알아서 맞춥니다.

| | ⓐ 클라우드 플랫폼 · NAVER API HUB | ⓑ 네이버 개발자센터 |
|---|---|---|
| 콘솔 | [console.ncloud.com](https://console.ncloud.com) → NAVER API HUB → Application | [developers.naver.com](https://developers.naver.com/apps/#/register) |
| 등록 | Application에 **NAVER 검색 › 지역** 추가 → [인증 정보] | 앱 등록 → 사용 API에 **검색** 추가 |
| 엔드포인트 | `GET naverapihub.apigw.ntruss.com/search/v1/local` | `GET openapi.naver.com/v1/search/local.json` |
| 인증 헤더 | `X-NCP-APIGW-API-KEY-ID` / `X-NCP-APIGW-API-KEY` | `X-Naver-Client-Id` / `X-Naver-Client-Secret` |
| 문서 | [API HUB 지역검색](https://api.ncloud-docs.com/docs/naver-api-hub-search-local) | [지역 검색 API](https://developers.naver.com/docs/serviceapi/search/local/local.md) |

어느 쪽이든 `naver_client_id` / `naver_client_secret` 두 값에 넣으면 됩니다.
`naver_api_mode` 를 `'apihub'` / `'developers'` 로 고정할 수도 있습니다.

### 지도는 별도 발급입니다

지도(Maps)는 위 두 곳 어느 것도 아닌, 클라우드 플랫폼의 **Maps Application** 에서 따로 받습니다.

- 콘솔: [console.ncloud.com/maps/application](https://console.ncloud.com/maps/application)
  → Application 등록 → **Dynamic Map** 체크 → Web 서비스 URL에 `http://localhost`
- 거기 표시되는 **Client ID** 를 `naver_map_key_id` 에 넣습니다.
  계정 인증키나 API HUB 키와는 다른 값입니다.
- 스크립트: `https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=<KeyID>`
  (구 파라미터 `ncpClientId` 는 `ncpKeyId` 로 변경)
- 인증에 실패하면 깨진 워터마크 대신 안내 문구로 대체됩니다 (`navermap_authFailure`).

`/guide` 화면의 **연결 테스트** 버튼이 지역검색·지도를 각각 실제로 호출해서
어느 경로로 연결됐는지, 실패했다면 왜인지 알려줍니다.

### 지역검색 API의 제약과 대응

지역검색은 **한 번의 호출로 최대 5건**만 돌려줍니다(`display` 최대 5, `start` 고정 1).
그래서 이렇게 처리합니다.

1. `"지역명 + 성격"` 조합으로 질의를 여러 개 만들어 순차 호출 (`naver_max_queries`, 기본 6회)
2. 결과를 `t_places` 테이블에 upsert — 상호+도로명주소 해시를 중복 판정 키로 사용
3. 같은 지역 근처에 최근 수집된 데이터가 15건 이상이면 재호출하지 않음 (`naver_cache_hours`, 기본 24시간)
4. 인증 실패·쿼터 초과처럼 재시도가 무의미한 오류는 첫 응답에서 중단

네이버는 **가격·좌석·주차 정보를 주지 않습니다.** 수집된 장소의 `avg_price`, `max_party`,
`has_room` 등은 **업종 기준 추정값**이며 (`Place_model::guess_attributes()`),
실제 값으로 덮어쓰려면 `t_places` 테이블을 직접 수정하면 됩니다. 사람이 보정한 값은
재동기화 때 덮어쓰지 않습니다.

## 3. 구조

```
application/
  config/dinnerspot.php        앱 설정 (API 키, 점수 가중치, 투표 옵션)
  core/MY_Controller.php       공통 베이스 (레이아웃 렌더, JSON 응답, payload 파싱)
  libraries/
    Naver_local.php            네이버 지역검색 클라이언트 (좌표 변환, 오류 분류)
    Recommender.php            점수 엔진 — 순수 계산, DB/네트워크 의존 없음
    Spot_service.php           유스케이스 조합: 정규화 → 수집 → 조회 → 점수화
  models/
    Place_model.php            장소 조회/upsert, 카테고리 매핑, 지역 사전
    Vote_model.php             투표방/후보/표
  controllers/
    Home.php                   검색·결과·상세·가이드 화면
    Vote.php                   투표방 화면
    Api.php                    JSON API
  views/
    layout/main.php            공통 레이아웃
    home/, vote/               페이지 뷰
assets/
  css/app.css                  전체 스타일 (단일 파일)
  js/app.js                    공통 (토스트, fetch 래퍼, 저장소, 위치)
  js/recommend.js              게이지 애니메이션 + 후보 트레이
  js/vote-create.js            투표방 생성
  js/vote-room.js              투표 제출 + 결과 폴링
sql/
  01_schema.sql                CREATE TABLE 8개 (DROP → CREATE, 재실행 가능)
  02_seed.sql                  INSERT — 지역·카테고리 사전 + 샘플 장소 43곳 (upsert)
```

### 추천 점수

`Recommender` 가 항목별 0~100 점을 낸 뒤 가중 합산합니다. 가중치는
`config/dinnerspot.php` 의 `score_weights` 에서 조절합니다.

| 항목 | 기본 비중 | 판정 |
|---|---|---|
| `distance` | 28 | 반경의 1/4 안이면 만점, 반경 밖은 급감. 좌표 미상이면 중립 55 |
| `budget` | 22 | 1인 예상금액이 예산 ±15% 안이면 만점. **초과에 3배 민감**, 저렴은 하한 50 |
| `capacity` | 16 | 수용인원이 참석인원의 1.5배 이상이면 만점. 미상이면 인원수에 따라 40~78 |
| `rating` | 15 | 리뷰 수가 적으면 평균(3.9)으로 당기는 베이지안 보정 후 3.0~5.0 구간을 스트레치 |
| `amenity` | 12 | **고른 조건만** 채점. 아무것도 안 골랐으면 갖춘 만큼 가산 |
| `category` | 7 | 종류를 고르면 **34 이상으로 자동 상승** — 명시한 요구가 거리·예산에 묻히지 않게 |

여기에 목적(`purpose`)별 보정이 붙습니다.

| 목적 | 가중치 조정 | 선호 / 회피 |
|---|---|---|
| 팀 회식 | — | 고기·한식·찌개·치킨 / 카페 |
| 접대·손님 | 평점 ×1.5, 조건 ×1.4, 예산 ×0.6 | 한식·일식·양식·해산물 / 뷔페·분식·호프·면 |
| 가성비 | 예산 ×1.8, 평점 ×0.8 | 찌개·한식·뷔페·분식·면 |
| 2차·술자리 | 거리 ×1.5, 인원 ×0.7 | 술집·호프·치킨 / 뷔페·한식·양식 |
| 조용한 자리 | 조건 ×1.6, 평점 ×1.2 | 일식·양식·한식 / 뷔페·호프·분식 |

마지막으로 같은 업종이 상위에 몰리지 않도록 3번째부터 소폭 감점하는 리랭킹을 거칩니다.
`strict=1` 이면 조건 미충족 항목을 감점이 아니라 **제외**합니다.

### 투표

로그인이 없습니다. 서버가 토큰을 발급하고 브라우저가 `localStorage` 에 보관합니다.

- `host_key` — 방장. 마감 권한
- `voter_key` — 참여자. 재투표 시 본인 표를 갈아끼우는 데 사용

후보는 생성 시점의 장소 정보를 `t_vote_options.snapshot` 에 JSON으로 함께 저장합니다.
원본 `t_places` 행이 나중에 바뀌어도 이미 진행 중인 투표 결과가 흔들리지 않습니다.

결과는 폴링으로 갱신합니다(`vote_poll_interval`, 기본 4초). 탭이 보이지 않으면 멈추고,
마감되면 폴링을 종료합니다.

## 4. API

응답 규약: 성공 `{ ok: true, data: ... }` / 실패 `{ ok: false, message: "..." }`

| 메서드 | 경로 | 설명 |
|---|---|---|
| GET | `/api/areas` | 지역 사전 |
| GET | `/api/categories` | 카테고리 사전 |
| GET·POST | `/api/recommend` | 추천. `area_id` \| `lat`+`lng` \| `keyword`, `headcount`, `budget`, `purpose`, `radius`, `categories[]`, `need_room`, `need_parking`, `need_late`, `strict` |
| GET | `/api/place/{id}` | 장소 상세 |
| POST | `/api/vote/create` | 투표방 생성. `place_ids`, `title`, `host_nick`, `headcount`, `max_choice`, `allow_change`, `deadline_at` |
| GET | `/api/vote/{code}` | 방 상태. `?voter_key=` `?host_key=` |
| POST | `/api/vote/{code}/cast` | 투표. `voter_key`, `nickname`, `option_ids`, `comment` |
| POST | `/api/vote/{code}/close` | 마감. `host_key` |

```bash
curl "http://localhost/DINNERSPOT/api/recommend?area_id=1&headcount=8&budget=25000&purpose=team"
```

## 5. 화면

| 경로 | 화면 |
|---|---|
| `/` | 조건 입력 (문장형 폼) |
| `/recommend` | 추천 결과 순위표 + 후보 담기 |
| `/place/{id}` | 장소 상세 |
| `/vote` | 코드로 투표방 참여 |
| `/vote/new?ids=1,2,3` | 투표방 생성 |
| `/vote/r/{code}` | 투표방 |
| `/vote/r/{code}/result` | 결과만 보기 |
| `/guide` | API 키 설정 안내 및 현재 연결 상태 |

## 6. 알아둘 점

- `t_places` 의 샘플 데이터(`memo` 에 `샘플`)는 **실제 업체 정보가 아닙니다.** 화면에도 `샘플` 배지로 표시됩니다.
  실서비스로 쓰려면 네이버 연동을 켜고 샘플 행을 지우세요:
  `DELETE FROM t_places WHERE source='manual' AND memo='샘플';`
- CodeIgniter 3.1.x 는 PHP 8.2에서 동적 프로퍼티 deprecation 알림을 다량 발생시킵니다.
  알림이 JSON 응답을 오염시키므로 `index.php` 의 development 분기에서 `E_DEPRECATED`/`E_STRICT` 를 제외했습니다.
- 검색 요청은 `t_search_logs` 에 기록됩니다. 추천 품질 확인용이며 커지면 주기적으로 정리하세요.
- `t_vote_ballots` 는 집계 편의를 위해 `room_id` 를 비정규화해 갖고 있습니다. 세 컬럼의 방이
  어긋나지 않도록 `(room_id, option_id)` / `(room_id, voter_id)` 복합 FK 로 묶여 있으니,
  후보/참여자 테이블의 `UNIQUE (room_id, id)` 를 지우면 안 됩니다.
- CSRF 보호는 꺼져 있습니다(`config['csrf_protection'] = FALSE`). 외부에 공개할 경우 켜고
  `assets/js/app.js` 의 `DS.api` 에 토큰을 실어 보내야 합니다.
