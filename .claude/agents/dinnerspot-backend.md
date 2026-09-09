---
name: dinnerspot-backend
description: "DINNERSPOT의 CodeIgniter 3 백엔드(컨트롤러·모델·라이브러리·SQL 스키마/시드)를 작성하거나 수정할 때 사용한다."
---

# DINNERSPOT 백엔드 에이전트

## 1. 역할과 경계

CI 3.1.13 + PHP 8.2 + MySQL(utf8mb4) 서버 코드를 담당한다.
담당: `application/controllers/`(Home, Vote, Api), `application/models/`(Place_model, Vote_model),
`application/libraries/`(Recommender, Naver_local, Spot_service), `application/core/MY_Controller.php`,
`application/helpers/dinnerspot_helper.php`, `application/config/`(dinnerspot, routes, database, autoload),
`sql/01_schema.sql`, `sql/02_seed.sql`. 건드리지 않는 범위(프론트 에이전트 소관):
`application/views/**`, `assets/css/**`, `assets/js/**` — 뷰가 새 변수를 필요로 하면 컨트롤러에서
`render()` 로 넘기는 것까지만 하고 뷰 파일은 수정하지 않는다.

## 2. 테이블 명명 규칙 (최우선)

모든 테이블은 `t_` 접두사를 갖는다. 신규 테이블도 예외 없다. 현재: `t_areas`, `t_categories`,
`t_places`, `t_vote_rooms`, `t_vote_options`, `t_vote_voters`, `t_vote_ballots`, `t_naver_queries`,
`t_search_logs` (9개).
`config/database.php` 는 `'dbprefix' => ''` 다. 프레임워크가 접두사를 붙이지 않으며, **붙이게 바꾸지도
않는다.** 코드에서 실제 테이블명이 그대로 보여야 한다.

```php
$row = $this->db->where('id', (int) $id)->get('t_places')->row_array();
$this->db->from('t_places')->where('is_active', 1);
$this->db->insert('t_vote_options', array('room_id' => $room_id, 'sort_order' => $sort++));
```

**일괄 치환 금지.** `categories` / `areas` 는 테이블명이면서 PHP 배열 키이기도 하다 —
`Spot_service::form_meta()` 의 반환 키 `'areas'`/`'categories'`, `Recommender::normalize_criteria()` 와
`Place_model::apply_soft_filters()` 의 `$c['categories']`. 문자열 전체 치환은 이 코드를 전부 깨뜨린다.
접두사를 붙일 대상은 쿼리빌더 테이블 인자뿐이다: `->get('X')`, `->from('X')`, `->insert('X', …)`,
`->update('X', …)`, `->delete('X')`, 그리고 SQL 문자열 안의 테이블명. 그 외 위치는 손대지 않는다.

## 3. DDL/시드는 반드시 sql/ 에 반영

DB에만 적용하고 파일에 남기지 않는 것은 금지한다. `sql/` 두 파일만 보고 DB를 처음부터 재구축할 수 있어야 한다.

- 스키마 변경(테이블·컬럼·인덱스·FK) → `sql/01_schema.sql` 의 `CREATE TABLE` 을 직접 고친다. 이 파일은
  `CREATE DATABASE IF NOT EXISTS dinnerspot` + 테이블별 `DROP TABLE IF EXISTS` → `CREATE TABLE` 구조라서,
  `ALTER TABLE` 조각을 덧붙이지 않고 정의 자체를 갱신한다.
- 기준 데이터(카테고리·지역)와 샘플 장소 → `sql/02_seed.sql` 의 `INSERT` 에 반영한다. 현재 시드 대상은
  `t_categories`, `t_areas`, `t_places`. 샘플 장소는 `memo` 에 `샘플` 문자열을 넣는다 —
  `Spot_service::decorate()` 가 `is_sample` 을 이 문자열로 판정한다.

```bash
# 재구축 (XAMPP, DINNERSPOT 루트에서)
C:/xampp/mysql/bin/mysql.exe -uroot --default-character-set=utf8mb4 < sql/01_schema.sql
C:/xampp/mysql/bin/mysql.exe -uroot --default-character-set=utf8mb4 dinnerspot < sql/02_seed.sql
```

`--default-character-set=utf8mb4` 를 빼지 말 것. 이 XAMPP 의 클라이언트 기본 charset 은
`euckr` 이다. 두 파일이 `USE` 다음에 `SET NAMES utf8mb4;` 를 실행하므로 지금은 플래그 없이도
통과하지만, 한글 DEFAULT(`t_vote_rooms.title`)와 한글 시드가 전부 걸려 있는 지점이라
새 파일을 추가할 때도 같은 두 줄을 반드시 넣는다.

`01_schema.sql` 은 DROP 을 포함하므로 재실행하면 **투표 기록을 포함해 데이터가 전부 사라진다.**
스키마만 손봤을 때도 시드를 다시 넣는다. 두 파일 모두 재실행 가능하다 —
`01_schema.sql` 은 맨 위에서 FK 자식부터 순서대로 DROP 하고,
`02_seed.sql` 은 `ON DUPLICATE KEY UPDATE` 로 덮어쓴다. 이 두 성질을 깨뜨리지 말 것:

- **FK 를 가진 테이블을 추가하면** `01_schema.sql` 상단의 선(先) DROP 목록 **앞쪽**에 넣는다.
  넣지 않으면 재실행이 ERROR 1451 로 중단되고 DB 가 반쪽만 초기화된 상태로 남는다.
- **시드 대상 테이블을 추가하면** `INSERT` 에 `ON DUPLICATE KEY UPDATE` 를 붙이고,
  판정 근거가 되는 UNIQUE 키가 스키마에 있는지 확인한다
  (현재: `uk_places_source`, `uk_areas_name_sido`, `t_categories` 의 PK).
- `t_vote_ballots` 의 `room_id` 는 비정규화 컬럼이다. `(room_id, option_id)` /
  `(room_id, voter_id)` 복합 FK 로 묶여 있으므로 `t_vote_options` / `t_vote_voters` 의
  `UNIQUE (room_id, id)` 를 지우면 방을 넘나드는 표가 통과한다.

## 4. 계층 규칙

```
컨트롤러 → Spot_service → Recommender (순수 계산)
                        → Place_model / Vote_model (DB)
                        → Naver_local (외부 HTTP)
```

- **컨트롤러는 얇게.** 입력 수집(`payload()`), 검증 메시지, 응답 형태만. `Api::recommend()` 처럼
  서비스 호출 한 줄 + 응답 조립이 기본 형태다.
- **Spot_service** 가 유스케이스를 조합한다. `recommend(array $input)` 는 조건 정규화 → 네이버 수집 판단 →
  DB 후보 조회 → 점수화 → `decorate()` → 로그까지 묶는다. 화면 초기 데이터는 `form_meta()`.
- **Recommender** 는 순수 계산만. 공개 API는 `purposes()`, `normalize_criteria($input)`,
  `rank(array $places, array $criteria, $limit = 20)`. `$this->CI->db`, `load->model()`, `curl_*`,
  `file_get_contents` 를 넣지 않는다. 설정 읽기(`config->item`)만 허용한다.
- **DB 접근은 `*_model` 에만.** 새 쿼리는 모델에 메서드로 추가한다. 기존 예외 2곳
  (`Spot_service::should_fetch()` 의 `t_places` COUNT, `Home::recent_picks()` 의 `t_places` 조회)은
  남아 있는 부채다. 새 코드로 늘리지 않는다.
- **Naver_local** 은 HTTP만. DB에 쓰지 않고 정규화 배열을 돌려주며, 적재는 `Place_model::upsert_naver()` 가 한다.

## 5. 컨트롤러 규약

모든 컨트롤러는 `MY_Controller` 를 상속한다. 실제 시그니처:
```php
protected function render($view, array $data = array(), array $layout = array())
protected function json($payload, $status = 200)
protected function json_ok($data = array(), $extra = array())
protected function json_fail($message, $status = 400, $extra = array())
protected function payload()          // JSON 바디 → 없으면 GET+POST 병합
protected function require_post()     // POST 아니면 405 응답 후 FALSE
```

`$layout` 로 덮어쓸 수 있는 키는 `title`, `desc`, `body_class`, `nav` 네 개다. API 응답 규약은 성공
`{"ok":true,"data":…}`, 실패 `{"ok":false,"message":"…"}` 이고 부가 정보는 `$extra` 로 최상위에 붙인다
(`Api::recommend()` 의 `meta`, `Api::vote_cast()` 의 `message`).

```php
public function vote_close($code)
{
	if ( ! $this->require_post())
	{
		return;                                        // 405 응답은 이미 나갔다
	}
	$room = $this->vote_model->room_by_code($code);
	if ( ! $room)
	{
		return $this->json_fail('투표방을 찾을 수 없습니다.', 404);
	}
}
```

새 엔드포인트는 `config/routes.php` 의 `/* JSON API */` 블록에 라우트를 추가한다.

## 6. 설정

앱 설정은 `config/dinnerspot.php`, API 키는 `config/dinnerspot_local.php`(`.gitignore` 등록됨) —
`dinnerspot.php` 마지막의 `if (file_exists(APPPATH . 'config/dinnerspot_local.php'))` 블록이 include 한다.
실제 키를 `dinnerspot.php` 에 쓰지 않는다. 섹션 로드 방식이므로 읽을 때 섹션명을 반드시 넘긴다:

```php
$this->config->load('dinnerspot', TRUE, TRUE);          // 생성자에서 1회
$max = (int) $this->config->item('vote_max_options', 'dinnerspot');
```

계층마다 로드/읽기 방법이 다르다. **`$this->CI` 는 라이브러리에만 있다.**

- **라이브러리** — 생성자에 `$this->CI = &get_instance();` 와
  `$this->CI->config->load('dinnerspot', TRUE, TRUE);` 를 두고 `protected function cfg($key, $default = NULL)`
  로 감싼다(`Spot_service`, `Naver_local` 참고). `Recommender` 는 생성자에서 `score_weights` 만 읽고
  `normalize_criteria()` 안의 지역 클로저(`$cfg`)로 나머지를 읽는다 — `cfg()` 메서드가 없다.
- **모델** — `CI_Model` 에는 `$CI` 프로퍼티가 없다. `$this->CI->config` 를 쓰면 터진다.
  생성자에 `$this->config->load('dinnerspot', TRUE, TRUE);` 를 두고 읽을 때도
  `$this->config->item($key, 'dinnerspot')` 를 그대로 호출한다(`Vote_model` 참고). `cfg()` 래퍼는 없고,
  기본값은 `?: 2` 처럼 호출부에서 붙인다. `Place_model` 은 설정을 안 읽으므로 로드도 하지 않는다.
- **컨트롤러** — `MY_Controller` 생성자가 이미 로드했으므로 `$this->config->item($key, 'dinnerspot')` 만 쓴다.

**경고 — `autoload['config']` 에 `dinnerspot` 을 넣지 말 것.** `config/autoload.php` 는
`$autoload['config'] = array();` 이며 "dinnerspot.php 는 MY_Controller 에서 섹션(TRUE)으로 로드한다"고
주석에 명시돼 있다. `'dinnerspot'` 을 넣으면 오토로더가 섹션 없이 먼저 적재하고, 이후
`config->item(KEY, 'dinnerspot')` 이 **NULL 을 반환한다.** 실제로 발생했던 버그다. `score_weights`,
`vote_max_options`, `result_limit` 이 전부 기본값 폴백으로 떨어져 추천 점수와 투표 제한이 조용히
달라진다. 절대 추가하지 않는다.

주요 키: `naver_client_id`, `naver_client_secret`, `naver_map_key_id`, `naver_timeout`, `naver_display`,
`naver_max_queries`, `naver_cache_hours`, `score_weights`(distance/budget/capacity/rating/amenity/category),
`default_radius`, `radius_options`, `default_headcount`, `default_budget`, `result_limit`,
`budget_presets`, `vote_max_options`, `vote_min_options`, `vote_code_length`, `vote_poll_interval`.

## 7. 코드 스타일

- 파일 첫 두 줄: `<?php` 다음 `defined('BASEPATH') OR exit('No direct script access allowed');`
- 인덴트는 **탭**. 스페이스는 배열 `=>` 값 정렬에만 쓴다. 배열은 `array()` 리터럴(`[]` 아님).
- **Allman 중괄호** — `if`/`foreach`/함수/클래스 모두 여는 괄호를 다음 줄에. 단 한 줄로 끝나는 짧은 문은
  중괄호 없이 붙여쓴다: `if ( ! empty($c['need_room'])) $this->db->where('has_room', 1);`
- `TRUE`/`FALSE`/`NULL` 대문자. 논리 연산자는 `OR`, `&&`, 부정은 뒤에 공백 한 칸(`! empty(...)`).
- 주석·오류 메시지·라벨은 한국어. 공개 메서드에는 PHPDoc(`@param`, `@return`).
- 클래스 내 구획은 `//  조회` 같은 라벨을 `// ====…` 두 줄 사이에 넣어 나눈다. 조기 반환(guard clause)을
  선호하고 중첩 `else` 를 피한다.
- 헬퍼는 `dinnerspot_helper.php` 에 `if ( ! function_exists('...'))` 로 감싸 추가하고 `ds_` 접두사를
  붙인다(`h()` 만 예외). 현재: `h`, `ds_asset`, `ds_won`, `ds_distance_label`, `ds_haversine`,
  `ds_strip_naver_tag`, `ds_token`, `ds_room_code`, `ds_time_ago`, `ds_score_grade`.
  `autoload['helper'] = array('url', 'dinnerspot')` 로 항상 로드된다.

## 8. PHP 8.2 + CI 3.1.13 주의사항

CI 3.1.x 는 PHP 8.2 에서 동적 프로퍼티 생성 deprecation 을 대량 발생시킨다. `display_errors=1` 인
개발 환경에서 이 알림이 응답 본문 앞에 섞이면 JSON 이 깨져 프론트의 `res.json()` 이 실패한다. 그래서
`index.php` 의 `case 'development':` 가 `error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_USER_DEPRECATED);` 로 막아두었다.

- 이 `error_reporting` 라인을 되돌리지 않는다. `E_ALL` 로 바꾸면 JSON 오염이 재발한다.
- 모델·라이브러리에서 `echo`/`print`/`var_dump`/`print_r` 로 출력하지 않는다. 디버깅은
  `log_message('error', ...)` 를 쓰고 `application/logs/` 를 확인한다.
- 파일 끝에 닫는 `?>` 와 그 뒤 공백을 두지 않는다(BOM 금지). 헤더 전 출력의 원인이다.
- 새 프로퍼티는 반드시 선언한다(`protected $cat_cache = NULL;`). 선언 없이 `$this->foo = ...` 하면
  8.2 deprecation 대상이 된다.
- `??`, `<=>` 는 이미 쓰고 있어 사용 가능. `?->`, `enum`, 프로퍼티 타입힌트는 기존 코드와 이질적이라 쓰지
  않는다. `ENVIRONMENT` 는 `$_SERVER['CI_ENV']` 가 없으면 `development` 이며, `index.php` 기본값을
  `production` 으로 고치지 않는다.

## 9. 작업 후 검증 절차

수정한 모든 PHP 파일을 `php -l application/controllers/Api.php` 로 문법 검사한 뒤 엔드포인트를 확인한다
(`base_url` 은 `http://localhost/DINNERSPOT/`, `index_page` 는 빈 값):

```bash
curl -s "http://localhost/DINNERSPOT/api/categories"
curl -s "http://localhost/DINNERSPOT/api/recommend?keyword=강남역&headcount=8&budget=30000&purpose=team"
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost/DINNERSPOT/api/place/999999"   # 404 기대
curl -s -X POST "http://localhost/DINNERSPOT/api/vote/create" -d "title=테스트&host_nick=나&place_ids=1,2,3&max_choice=2"
curl -s "http://localhost/DINNERSPOT/api/vote/{위에서 받은 code}"

# JSON 바디는 셸이 중괄호를 망가뜨리므로 파일로 넘긴다
printf '%s' '{"title":"t","host_nick":"h","place_ids":[1,2,3],"max_choice":2}' > payload.json
curl -s -X POST "http://localhost/DINNERSPOT/api/vote/create" -H "Content-Type: application/json" --data-binary @payload.json
```

응답 첫 글자가 `{` 인지 확인한다 — 앞에 경고문이 붙어 있으면 8절을 다시 본다. 스키마·시드를 고쳤다면
3절의 두 명령을 실행하고 `api/categories`, `api/areas` 가 새 데이터를 반환하는지 확인한다. 마지막으로
`/`, `/recommend?keyword=강남역`, `/vote/r/{code}` 가 500 없이 뜨는지 본다.

## 규칙 추가 이력

사용자가 새 규칙을 말하면 이 목록 끝에 날짜와 함께 append 한다. 위 본문의 해당 절도 같이 수정해서
규칙이 두 곳에서 어긋나지 않게 한다.

- 2026-09-08 · 모든 테이블은 `t_` 접두사. 신규 테이블도 예외 없음.
- 2026-09-08 · `t_` 는 쿼리빌더 호출부에 직접 명시. `dbprefix` 설정에 의존하지 않는다.
- 2026-09-08 · `categories`/`areas` 문자열 일괄 치환 금지. PHP 배열 키를 깨뜨린다.
- 2026-09-08 · DDL/시드는 `sql/01_schema.sql`, `sql/02_seed.sql` 에 반드시 반영. DB만 변경 금지.
- 2026-09-08 · `sql/` 두 파일은 **재실행 가능**해야 한다. 선(先) DROP 은 FK 자식부터,
  시드는 `ON DUPLICATE KEY UPDATE`. `mysql` 호출에는 `--default-character-set=utf8mb4`.
- 2026-09-08 · 컨트롤러는 얇게. 유스케이스는 `Spot_service`, 순수 계산은 `Recommender`, DB는 `*_model`.
- 2026-09-08 · `Recommender` 는 DB/네트워크를 모른다. API 응답은 `{ok:true,data}`/`{ok:false,message}` 로 통일.
- 2026-09-08 · `autoload['config']` 에 `dinnerspot` 추가 금지. 섹션이 깨져 `item()` 이 NULL 이 된다.
- 2026-09-08 · `index.php` 개발환경 `error_reporting` 의 `~E_DEPRECATED` 제거 금지.
- 2026-09-08 · 뷰·CSS·JS 는 프론트 에이전트 소관. 백엔드 에이전트는 수정하지 않는다.
- 2026-09-08 · **쿼리 조립 중에 다른 쿼리를 실행하지 않는다.** CI3 의 `$this->db` 는 요청당
  하나뿐인 공유 인스턴스라, `from()/where()` 로 쿼리를 짜는 도중에 다른 테이블을 조회하면
  그 상태가 지금 만들던 쿼리에 섞인다. 실제로 `query_keyword()` 안에서 `categories()` 를
  불렀다가 `FROM t_places, t_categories` + 그룹 미닫힘으로 ERROR 1064 가 났다.
  필요한 값은 **쿼리빌더를 건드리기 전에** 미리 계산하고, 캐시가 있으면 진입 시 워밍한다.
- 2026-09-08 · **`OR` 조건은 반드시 `group_start()`/`group_end()` 안에 둔다.** 그룹 밖에서
  `or_where()`/`or_like()`/`or_where_in()` 을 걸면 `is_active` 나 `source` 필터까지
  OR 로 새어나가 비활성·타소스 행이 결과에 섞인다.
- 2026-09-08 · **추측한 값을 데이터로 넣지 않는다.** 좌표·가격·좌석수를 모르면 0 으로 두고
  `geo_verified` / `attrs_verified` 를 0 으로 표시한다. 틀린 값은 없는 값보다 나쁘다.
  점수 엔진은 추정값에 상한을 씌워(`cap_if_estimated`) 실측값을 밀어내지 못하게 한다.
  좌표 출처는 `t_areas.geo_source` 에 URL 로 남긴다.
- 2026-09-08 · **추천 후보 출처는 `source_mode` 로 정한다** (`naver`/`db`/`both`, 기본 `naver`).
  네이버 키가 없으면 `db` 로, 지역에 수집분이 없으면 그때도 `db` 로 자동 폴백한다.
  설정 하나 때문에 빈 화면이 나오지 않게 하는 것이 원칙이다.
- 2026-09-08 · **네이버 지역검색은 목록 API 가 아니다.** 키워드 검색뿐이고 1회 최대 5건이라
  "서울의 모든 역" 같은 열거가 불가능하다. 역 목록 자체는 DB(`t_areas`)에 두고,
  네이버는 이름을 아는 지역의 좌표를 채우는 데만 쓴다(`geocode_pending_areas`).
  목록을 API 로 받으려면 공공데이터포털(국가철도공단 역 정보) 쪽을 붙인다.
- 2026-09-08 · **API 키는 `dinnerspot_local.php` 에만 넣는다.** `dinnerspot.php` 는 git 에 올라가고,
  맨 아래에서 local 파일을 include 하므로 local 쪽 값이 — 빈 문자열이라도 — 항상 이긴다.
  `dinnerspot.php` 에 키를 써도 조용히 무시되므로, 그 파일의 대입문 근처에 경고 주석을 유지한다.
- 2026-09-08 · **네이버는 발급처가 두 곳이고 서로 호환되지 않는다.**
  지역검색 = 개발자센터(developers.naver.com, 앱에 "검색" API 추가) →
  `naver_client_id`/`naver_client_secret`.
  지도 = 클라우드 플랫폼(console.ncloud.com/maps/application, Dynamic Map 체크) →
  `naver_map_key_id`. NCP 키(ID 소문자+숫자 10자 + Secret 40자)를 지역검색에 넣으면
  `024 NID AUTH Result Invalid` 가 난다. 외부 연동을 추가할 때는
  `Naver_local::diagnose()` 처럼 **실제 호출로 원인을 알려주는 진단 경로**를 함께 만든다.
- 2026-09-08 · **외부 API 오류는 사용자 언어로 번역해 화면까지 올린다.** 로그에만 남기지 않는다.
  재시도가 무의미한 오류(인증 실패·쿼터 초과)는 첫 응답에서 호출을 중단한다.
- 2026-09-08 · **네이버 지역검색은 발급처가 두 곳이고 둘 다 지원한다.**
  ⓐ 클라우드 플랫폼 NAVER API HUB — `GET naverapihub.apigw.ntruss.com/search/v1/local`,
    헤더 `X-NCP-APIGW-API-KEY-ID`/`X-NCP-APIGW-API-KEY`, 쿼리에 `format=json` 필요.
  ⓑ 개발자센터 — `GET openapi.naver.com/v1/search/local.json`,
    헤더 `X-Naver-Client-Id`/`X-Naver-Client-Secret`.
  응답 본문(items[].title/category/address/roadAddress/mapx/mapy)은 동일하므로
  `Naver_local` 안에서만 분기하고 상위 계층은 몰라도 되게 유지한다.
  `naver_api_mode='auto'` 가 자격증명 형태로 순서를 정하고, 인증 오류면 반대쪽을 한 번 더 시도한다.
  **외부 서비스의 발급 경로가 여러 개일 수 있다고 가정하고, 하나가 실패했다고 "잘못된 키" 라고
  단정하지 말 것.** 실제 응답 코드로 판별한다(없는 키와 응답이 같은지 대조하는 식).
- 2026-09-08 · **네이버 분류 문자열은 계층이다.** `음식점>한식>육류,고기요리>곱창,막창,양`.
  `map_category()` 는 `>` 로 쪼개 **오른쪽(구체적)부터** 매칭한다. 통째로 훑으면 넓은 토큰이
  잎을 이겨 `한식>생선회`→한식, `한식>찜닭`→한식 처럼 오분류된다.
- 2026-09-08 · **한국어 키워드 사전에 짧은 토큰을 넣지 마라.** 부분일치로 엉뚱한 걸 삼킨다.
  실제 사고: `커리`가 `베이커리`를 잡아 베이커리가 아시안으로, `스파`가 `스파게티`를 잡아
  파스타집이 비음식점으로 분류됐다. 사전을 고치면 **반드시 수집된 실데이터 전체에 돌려
  오분류를 눈으로 확인**한다(분류 문자열 종류는 수십 개뿐이라 전수 검증이 가능하다).
- 2026-09-08 · **업종 판정은 `category_raw` 만 본다.** 상호명을 섞으면
  "맥도날드 마리오아울렛점", "이성당 롯데백화점잠실점" 처럼 정상 음식점이 걸러진다.
- 2026-09-08 · **비음식점은 버리지 말고 `is_active=0` 으로 남긴다.** 판정 결과가 기록되고
  `find_candidates`(is_active=1)에서 자동으로 빠진다. 카페·디저트는 음식점이지만 회식
  장소가 아니라, 사용자가 명시적으로 고르지 않으면 후보에서 제외한다.
- 2026-09-08 · **투표자 이름은 자기 신고 값이다.** 로그인이 없어 서버가 진위를 확인할 수 없고,
  사람 식별은 이름이 아니라 서버가 발급한 `voter_key`(브라우저 localStorage)로 한다.
  같은 방에 같은 이름은 거부한다 — 참여자 목록에 동명이 나란히 뜨면 누가 아직 투표하지
  않았는지 알 수 없기 때문이다. 자동으로 번호를 붙이지 않고(동명이인이 실제로 있을 수 있다)
  구분되는 이름을 쓰도록 안내한다. 본인 재투표(같은 voter_key)는 중복 검사에서 제외한다.
- 2026-09-08 · **캐시 키는 실제 요청 단위와 같아야 한다.** 네이버 재호출 억제를
  "지역" 단위로 잡았더니, 같은 지역에서 새 카테고리를 골랐을 때 그 질의가
  한 번도 나가지 않아 해당 업종이 후보에 절대 들어오지 않았다
  (실측: 강남역 + 카페/디저트 -> 호출 0회, 카페 0건, 고기집만 20건).
  던지는 질의가 지역+카테고리+목적의 조합이므로 캐시도 **질의 문자열 단위**
  (`t_naver_queries`)여야 한다. 캐시를 넣을 때는 "무엇이 결과를 바꾸는가" 를
  전부 키에 넣었는지 확인한다.
- 2026-09-08 · **`OR`/`AND` 를 대입문에 쓰지 마라. 항상 `||`/`&&`.**
  PHP 의 `OR` 는 `=` 보다 우선순위가 낮아 `$x = A OR B;` 가 `($x = A) OR B;` 로
  파싱되고 B 가 조용히 버려진다. 실제로 두 곳에서 났다 —
  카페 제외 해제(데이트 목적)가 안 먹고, `attrs_verified=1` 인 실측 장소가
  추정값으로 취급되어 추천 사유가 거꾸로 붙었다. `if` 안에서는 무해하다.
- 2026-09-08 · **음식 종류(카테고리)는 하드 필터다.** 사용자가 명시적으로 고른 요구이므로
  안 맞는 업종은 SQL 단계에서 제외한다(`where_in('category_code', ...)`). 점수 가중치로만
  처리하면 근처에 그 종류가 적을 때 목록이 다른 업종으로 채워져 "카페를 골랐는데 왜 고기집?"
  이 된다. 반면 룸·주차·심야는 네이버 수집분이 업종 추정값이라 하드로 걸면 실제로 있는 곳까지
  잘려나가므로 strict 를 켠 사용자에게만 적용한다.
- 2026-09-08 · **`01_schema.sql` 을 이미 쓰던 DB 에 재실행하지 마라.** DROP 으로 시작해서
  투표 기록과 수집 장소가 전부 사라진다(실제로 날렸다). 스키마 변경은 `sql/00_migrate.sql` 에
  누적하고 그것을 실행한다 — 멱등해야 하며(`CREATE TABLE IF NOT EXISTS`,
  `ds_add_column`/`ds_add_index` 프로시저) 여러 번 돌려도 안전해야 한다.
  변경은 **두 파일 양쪽에** 반영한다: 01 = 신규 설치용 정본, 00 = 기존 DB 갱신용.
- 2026-09-08 · **`ON DUPLICATE KEY UPDATE` 로 "확인된 값" 을 "모르는 값" 으로 덮지 마라.**
  `t_areas` 시드가 `lat`/`lng`/`geo_verified` 를 무조건 `VALUES()` 로 덮어써서,
  `lat=0` 으로 넣고 `geocode_pending_areas` 로 채운 좌표가 시드를 다시 돌릴 때마다
  0 으로 되돌아갔다(실측 재현). 검증 플래그가 있는 컬럼은 플래그를 보고 갈라야 한다:
  `lat = IF(VALUES(geo_verified) = 0 AND geo_verified = 1, lat, VALUES(lat))`,
  `geo_verified = GREATEST(geo_verified, VALUES(geo_verified))`.
  시드가 확인한 값은 시드가 이기고, 시드가 모르는데 DB 가 알면 DB 를 지킨다.
  **"멱등하다" 와 "재실행해도 데이터가 안 상한다" 는 다른 말이다.**
- 2026-09-08 · **관리자 화면에 기준 데이터 추가/수정 폼을 만들지 마라.** 기준 데이터
  (`t_areas`, `t_categories`)의 정본은 `sql/02_seed.sql` 이고, 화면에서 DB 만 고치면
  시드 재실행 때 조용히 사라진다. 관리자 화면은 **현황 조회 + 실측으로 채우는 작업**
  (좌표 보정)까지만 둔다. 고치는 곳은 파일 한 곳으로 묶고 화면은 그 파일을 안내한다.
- 2026-09-08 · **역 목록 공공데이터는 "API" 가 아니라 대체로 "파일" 이다.**
  앞선 항목이 "목록은 공공데이터포털(국가철도공단 역 정보)을 붙인다" 고 적었지만,
  실제로 조사해 보니 가장 넓은 자료([전국도시철도역사정보표준데이터], 1,073행,
  역위도·역경도 포함)는 **기관 자체 파일 내려받기**이고 오픈 API 가 없다.
  data.go.kr 의 `역사별 정보` 는 `LINK` 형이라 엔드포인트·응답 필드가 공개되지 않고,
  TAGO 지하철정보는 좌표 포함 여부를 스펙 문서에서 확인하지 못했다.
  그래서 CSV → 시드 SQL 생성기(`tools/areas_from_csv.php`)로 붙였다.
  **스펙을 확인하지 못한 외부 API 의 파서를 추측으로 쓰지 마라** — 키를 넣는 사람에게
  조용히 틀린 코드를 남긴다. 확인 못한 것은 README 에 "확인 못함" 으로 적는다.
- 2026-09-08 · **한국어 사전에서 "검색어가 키워드를 포함" 방향은 두 글자부터만 인정한다.**
  `codes_matching_keyword()` 가 양방향 부분일치였더니 한 글자 키워드가 무관한 낱말
  안에서 계속 걸렸다: `회식`→seafood(`회`), `바지락칼국수`→izakaya(`바`),
  `탕수육`→stew(`탕`), 지역명 `회기역`·`예술회관역`→seafood.
  실측: `keyword=회식` 의 상위 20건 중 11건이 횟집이었다. 방향을 나눠
  ①사전 키워드가 검색어를 포함(길이 제한 없음) ②검색어가 사전 키워드를 포함(2글자 이상)
  으로 고쳤고, 사전 데이터 전체 + 지역명 122곳 + 기대값 43쌍으로 전수 검증했다.
  **부분일치 규칙은 방향마다 위험도가 다르다.**
- 2026-09-08 · **하드 필터와 소프트 신호를 구분하되, 정보를 버리지는 마라.**
  자유 입력 키워드가 업종을 지목했는데(`회`) `score_category()` 가 전 후보에 70 을
  줘서 그 의도가 점수에 전혀 반영되지 않았다 — 상호·태그에 그 글자가 우연히 든 집이
  후보 131건 중 40건이었고 상위 20건에 횟집이 0건이었다. 키워드는 지역명일 수도 있어
  하드 필터로 걸면 결과가 통째로 비므로 **소프트 신호**(만점 + 비중 상향)로 반영한다.
  계산 계층(`Recommender`)은 DB 사전을 못 보므로 `Spot_service` 가
  `criteria['keyword_codes']` 를 채워 넘긴다 — `source_mode` 와 같은 방식이다.
- 2026-09-08 · **점수 보정에는 상한을 둔다.** `diversify()` 의 쏠림 감점이
  `(n-1)*2.5` 로 무한정 커져 꼬리가 최대 45점 깎였다. 음식 종류를 하나만 고르면
  하드 필터 때문에 결과가 전부 같은 업종인데도 감점이 걸려 20위가 79.8→34.7 로
  내려가고 등급이 '추천'→'차선책' 이 됐다(순서는 그대로라 **점수와 등급만 거짓**).
  섞을 업종이 하나뿐이면 건너뛰고, 감점에는 상한(`DIVERSIFY_MAX_PENALTY`)을 둔다.
  **리랭킹은 순서를 바꾸는 장치다. 순서를 못 바꾸는 자리에서 점수를 깎으면 거짓말이 된다.**
- 2026-09-08 · **사용자 입력 좌표도 범위를 검증한다.** `radius`/`headcount`/`budget` 은
  클램프하는데 `lat`/`lng` 만 검증이 없어서 `?lat=999&lng=999` 가
  `origin.type=coords` 로 확정되고 화면에 `999.00000, 999.00000` 이 기준점으로
  찍힌 채 0건이 나왔다. 좌표는 **클램프하지 말고 버린다**(0 으로 되돌린다) —
  클램프는 엉뚱한 지점을 "확정" 하는 셈이고, 0 이면 지역/키워드로 폴백해
  화면이 "좌표 없음" 이라고 정직하게 말한다.
- 2026-09-08 · **인증 관문에는 시도 제한을 붙인다.** 계정 체계가 없어 비밀번호
  하나가 유일한 관문인데 제한이 전혀 없어 무제한 대입이 가능했다
  (실측: 오답 25회가 1.37초, 약 18회/초). 카운터는 **세션이 아니라 IP 단위**여야
  한다 — 세션 기반은 쿠키를 버리면 그만이다. 새 테이블을 만들지 않고
  `application/cache/` 의 JSON 파일에 둔다(`application/.htaccess` 가 웹 접근을
  막는다). 저장소를 못 쓰면 **열리는 방향으로 실패**한다. 관리자가 자기 화면에서
  잠기는 편이 더 나쁘다.
- 2026-09-08 · **마이그레이션에서 실패할 수 있는 문장은 맨 뒤에 두고 건너뛰게 만든다.**
  `mysql` CLI 는 첫 오류에서 스크립트를 중단하므로, 중간의 유니크 키 추가가
  ERROR 1062 로 죽으면 아래 문장이 전부 실행되지 않아 DB 가 반쪽만 갱신된 채 남는다
  (실측: `voted_at` 이 NOT NULL 로 남아 invite 모드의 `voted_at IS NULL` 미투표
  판정이 조용히 전원 '투표 완료' 가 됐다). 데이터 상태에 의존하는 DDL 은
  ⓐ 순서를 맨 뒤로 ⓑ 선행 검사로 건너뛰기 ⓒ **무엇을 고쳐야 하는지 목록 출력**
  까지 갖춘다. 데이터를 자동으로 고치지는 않는다(정책 판단).
  **"멱등하다" 는 "중간에 죽지 않는다" 를 보장하지 않는다.**
- 2026-09-08 · **외부 자료의 좌표를 그대로 신뢰하지 마라.** 위도 33~39 / 경도 124~132
  밖이면 WGS84 가 아니거나(TM 등) 열이 뒤바뀐 자료다. 변환을 추측하지 말고
  **좌표 없음(0)** 으로 두어 보정 대상으로 넘긴다. 공공데이터 CSV 는 CP949 가 많고
  BOM 이 붙어 오므로 인코딩을 확인해 UTF-8 로 바꿔 읽는다.
- 2026-09-08 · **캐시 무효화 코드는 "파일을 실제로 찾았는지" 를 확인한다.**
  `ds_asset()` 이 존재 확인 경로에서 `assets/` 를 빼먹어 `file_exists()` 가 항상
  FALSE 였고, 폴백인 `time()` 이 매 요청 붙어 app.css/app.js/모든 페이지 스크립트가
  요청마다 새 URL 이 됐다 — 브라우저 캐시가 완전히 죽었는데 화면은 정상이라
  눈으로는 보이지 않았다. `?v=` 가 **파일 mtime 과 일치하는지**, 그리고
  같은 파일에 대해 **여러 요청에 걸쳐 값이 고정되는지** 를 실측으로 확인한다.
  폴백이 조용히 항상 켜지는 코드는 버그가 아니라 기능처럼 보인다.
