-- =============================================================
--  DINNERSPOT - 회식장소 추천 서비스
--  스키마 정의 (MySQL 5.7+ / MariaDB 10.2+)
--  실행: mysql -uroot --default-character-set=utf8mb4 < sql/01_schema.sql
--         (--default-character-set 을 빼면 XAMPP 클라이언트 기본 charset 이
--          euckr 이라 t_vote_rooms.title 의 한글 DEFAULT 에서 ERROR 1067 로 죽는다.
--          아래 SET NAMES 가 2차 방어선이지만 CLI 인자를 붙이는 편이 확실하다.)
--
--  ###########################################################
--  #  주의: 이 파일은 DROP TABLE 로 시작한다.                 #
--  #  이미 쓰던 DB 에 재실행하면 **투표 기록과 수집한 장소가  #
--  #  전부 사라진다.**                                        #
--  #                                                          #
--  #  스키마를 바꿀 때는 이 파일을 다시 돌리지 말고            #
--  #  sql/00_migrate.sql 에 변경분을 적고 그것을 실행한다.     #
--  #  (00_migrate.sql 은 데이터를 보존하며 여러 번 실행 가능)  #
--  #                                                          #
--  #  이 파일은 "빈 DB 에서 처음 설치할 때" 만 쓴다.           #
--  #  단, 신규 설치용 정본이므로 스키마 변경은 여기에도 반영한다.#
--  ###########################################################
-- =============================================================

CREATE DATABASE IF NOT EXISTS `dinnerspot`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `dinnerspot`;
SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 선(先) DROP - FK 자식부터 지운다.
--
-- 아래 테이블 블록마다 `DROP TABLE IF EXISTS` 가 한 번 더 있지만,
-- 그 순서(부모 t_vote_rooms 가 자식보다 먼저)로는 이미 데이터가 있는 DB에
-- 재실행할 때 ERROR 1451 (Cannot delete or update a parent row) 로 중단되고
-- t_places/t_areas/t_categories 만 비워진 반쪽 상태가 남는다.
-- 그래서 의존 관계 역순으로 여기서 먼저 전부 떨어뜨린다.
-- FK 를 가진 테이블을 새로 추가하면 이 목록의 앞쪽에 넣어야 한다.
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_vote_ballots`;
DROP TABLE IF EXISTS `t_vote_voters`;
DROP TABLE IF EXISTS `t_vote_options`;
DROP TABLE IF EXISTS `t_vote_rooms`;
DROP TABLE IF EXISTS `t_search_logs`;
DROP TABLE IF EXISTS `t_naver_queries`;
DROP TABLE IF EXISTS `t_places`;
DROP TABLE IF EXISTS `t_categories`;
DROP TABLE IF EXISTS `t_areas`;

-- -------------------------------------------------------------
-- 지역 (검색 기준점). 네이버 지역검색은 키워드 기반이라
-- "역/동" 단위 기준 좌표를 미리 갖고 있어야 반경 계산이 된다.
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_areas`;
CREATE TABLE `t_areas` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL COMMENT '표시명 (예: 강남역)',
  `sido`       VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '시/도 (서울, 경기, 부산 ...)',
  `sigungu`    VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '시/군/구',
  `kind`       ENUM('station','district') NOT NULL DEFAULT 'station'
               COMMENT 'station=지하철역, district=상권/행정동',
  `line_info`  VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '지나는 노선 (예: 2호선·신분당선)',
  `lat`        DECIMAL(10,7) NOT NULL DEFAULT 0,
  `lng`        DECIMAL(10,7) NOT NULL DEFAULT 0,

  -- 좌표 출처 추적. 추측값을 넣지 않고, 미확인은 0 으로 두었다가
  -- 네이버 지역검색으로 보정한다 (Area_model::geocode_pending).
  `geo_verified` TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1=출처 확인된 좌표',
  `geo_source`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '좌표 출처 URL 또는 naver',

  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_areas_name_sido` (`name`, `sido`),
  KEY `idx_areas_name` (`name`),
  KEY `idx_areas_group` (`is_active`, `sido`, `sort_order`),
  KEY `idx_areas_geo` (`geo_verified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='검색 기준 지역 사전 (역/상권)';

-- -------------------------------------------------------------
-- 음식 카테고리 (네이버 category 문자열 → 내부 코드 매핑용)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_categories`;
CREATE TABLE `t_categories` (
  `code`       VARCHAR(20)  NOT NULL COMMENT '내부 코드 (korean, bbq ...)',
  `label`      VARCHAR(40)  NOT NULL COMMENT '표시명 (한식, 고기/구이 ...)',
  `keywords`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '매칭 키워드 콤마구분',
  `emoji`      VARCHAR(10)  NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='음식 카테고리 사전';

-- -------------------------------------------------------------
-- 장소 마스터
--  source = naver : 네이버 지역검색 API 결과 캐시
--  source = manual: 직접 등록/보정한 장소
-- 네이버가 주지 않는 회식 관련 속성(단체석/룸/주차/예산)은
-- 기본값 추정 후 관리자가 보정하는 구조.
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_places`;
CREATE TABLE `t_places` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`        ENUM('naver','manual') NOT NULL DEFAULT 'manual',
  `source_key`    VARCHAR(191) NOT NULL COMMENT '중복 판정 키 (naver: 상호+도로명주소 해시)',
  `name`          VARCHAR(150) NOT NULL,
  -- t_categories(code) 로의 FK 를 일부러 걸지 않는다. 네이버 동기화가
  -- 사전에 없는 분류를 만나면 Place_model::map_category() 가 'etc' 로 떨어뜨리는
  -- 것이 정책이고, FK 를 걸면 그 경로가 INSERT 실패로 바뀐다.
  -- 대신 사전 밖 코드가 쌓이지 않는지 주기적으로 확인한다:
  --   SELECT DISTINCT category_code FROM t_places
  --    WHERE category_code NOT IN (SELECT code FROM t_categories);
  `category_code` VARCHAR(20)  NOT NULL DEFAULT 'etc',
  `category_raw`  VARCHAR(150) NOT NULL DEFAULT '' COMMENT '네이버 원본 분류 문자열',
  `address`       VARCHAR(255) NOT NULL DEFAULT '',
  `road_address`  VARCHAR(255) NOT NULL DEFAULT '',
  `phone`         VARCHAR(40)  NOT NULL DEFAULT '',
  `homepage`      VARCHAR(255) NOT NULL DEFAULT '',
  `lat`           DECIMAL(10,7) NOT NULL DEFAULT 0,
  `lng`           DECIMAL(10,7) NOT NULL DEFAULT 0,

  -- 회식 적합도 판정용 속성
  `avg_price`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1인당 평균 예상금액(원). 0=미상',
  `price_level`   TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1 저렴 ~ 4 고가',
  `max_party`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '최대 수용 인원. 0=미상',
  `has_room`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '룸/단체석',
  `has_parking`   TINYINT(1) NOT NULL DEFAULT 0,
  `open_late`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '23시 이후 영업',
  `no_alcohol`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '주류 미취급',
  `rating`        DECIMAL(3,2) NOT NULL DEFAULT 0 COMMENT '0.00 ~ 5.00. 네이버는 주지 않아 0',
  `review_count`  INT UNSIGNED NOT NULL DEFAULT 0,

  -- 네이버 지역검색을 sort=comment(리뷰순)로 호출하므로 결과 내 순위가
  -- 인기도 대리지표가 된다. 평점을 못 받는 대신 이 값을 점수에 쓴다.
  -- 1 이 최상위, 0 은 미상. 여러 질의에 걸쳐 나오면 가장 좋은(작은) 순위를 유지한다.
  `naver_rank`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '네이버 결과 내 순위 (1=최상위, 0=미상)',

  -- 가격/좌석/주차가 사람이 확인한 실측값인지. 네이버 자동수집은 업종 추정값이라 0.
  -- 0 이면 점수 엔진이 예산/인원 항목에 상한을 씌워 실측값을 밀어내지 못하게 한다.
  `attrs_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=속성이 실측값',

  `image_url`     VARCHAR(500) NOT NULL DEFAULT '',
  `tags`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT '콤마구분 자유태그',
  `memo`          VARCHAR(500) NOT NULL DEFAULT '',

  `hit_count`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '추천 노출 횟수',
  `pick_count`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '투표 후보로 채택된 횟수',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `synced_at`     DATETIME NULL DEFAULT NULL COMMENT '네이버 최종 동기화 시각',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_places_source` (`source`, `source_key`),
  KEY `idx_places_geo` (`lat`, `lng`),
  KEY `idx_places_cat` (`category_code`, `is_active`),
  KEY `idx_places_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='회식 장소 마스터';

-- -------------------------------------------------------------
-- 투표방 - 로그인 없이 코드/링크로 참여
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_vote_rooms`;
CREATE TABLE `t_vote_rooms` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         CHAR(8)      NOT NULL COMMENT '공유용 방 코드',
  `title`        VARCHAR(120) NOT NULL DEFAULT '회식 장소 투표',
  `host_nick`    VARCHAR(40)  NOT NULL DEFAULT '',
  `host_key`     CHAR(32)     NOT NULL COMMENT '방장 식별 토큰 (마감/삭제 권한)',
  `headcount`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_choice`   TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1인당 선택 가능 수',
  `allow_change` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '재투표 허용',

  -- 참여 방식
  --   open   : 링크를 아는 사람이 이름을 직접 입력하고 투표 (기본)
  --   invite : 방장이 명단을 넣고 1인 1링크를 발급. 이름이 미리 확정되고
  --            참여자 행이 투표 전부터 존재하므로 "누가 아직 안 했는지" 를 알 수 있다.
  `mode`         ENUM('open','invite') NOT NULL DEFAULT 'open' COMMENT '참여 방식',

  `deadline_at`  DATETIME NULL DEFAULT NULL,
  `status`       ENUM('open','closed') NOT NULL DEFAULT 'open',
  `criteria`     TEXT NULL COMMENT '방 생성 시 사용한 추천 조건 JSON',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_rooms_code` (`code`),
  KEY `idx_vote_rooms_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='투표방';

-- -------------------------------------------------------------
-- 투표 후보 - 장소 스냅샷을 함께 보관해 원본이 바뀌어도 결과가 안 흔들리게
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_vote_options`;
CREATE TABLE `t_vote_options` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id`    BIGINT UNSIGNED NOT NULL,
  `place_id`   BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '원본 장소. 지워지면 NULL — 집계는 snapshot 으로 한다',
  `snapshot`   TEXT NOT NULL COMMENT '후보 장소 스냅샷 JSON',
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- t_vote_ballots 의 복합 FK (room_id, option_id) 대상. 이 키가 없으면
  -- "방 1의 표가 방 2의 후보를 찍는" 레코드를 막을 수 없다.
  UNIQUE KEY `uk_vote_options_room_id` (`room_id`, `id`),
  KEY `idx_vote_options_room` (`room_id`, `sort_order`),
  KEY `idx_vote_options_place` (`place_id`),
  CONSTRAINT `fk_vote_options_room` FOREIGN KEY (`room_id`)
    REFERENCES `t_vote_rooms` (`id`) ON DELETE CASCADE,
  -- 스냅샷 보관이 본질이라 CASCADE 가 아니라 SET NULL 이다.
  -- 장소가 지워져도 후보/집계는 살아남고 place_id 만 끊긴다.
  CONSTRAINT `fk_vote_options_place` FOREIGN KEY (`place_id`)
    REFERENCES `t_places` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='투표 후보';

-- -------------------------------------------------------------
-- 투표 참여자 (방 단위 익명 세션)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_vote_voters`;
CREATE TABLE `t_vote_voters` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id`    BIGINT UNSIGNED NOT NULL,
  `voter_key`  CHAR(32)    NOT NULL COMMENT '브라우저 로컬 토큰',
  `nickname`   VARCHAR(40) NOT NULL,
  `comment`    VARCHAR(200) NOT NULL DEFAULT '',

  -- 1인 1링크용 초대 토큰. invite 모드에서 방 생성 시 명단만큼 미리 만들어둔다.
  -- URL(/vote/i/<token>) 에 담기므로 브라우저 저장소에 의존하지 않는다 —
  -- 시크릿 창으로 열어도 같은 사람으로 식별된다.
  `invite_token` CHAR(16) NULL DEFAULT NULL COMMENT '초대 링크 토큰 (open 모드는 NULL)',

  -- 초대만 되고 아직 투표하지 않은 행이 존재하므로 NULL 을 허용한다.
  -- NULL = 미투표. 이게 "누가 안 했는지" 의 근거다.
  `voted_at`   DATETIME NULL DEFAULT NULL COMMENT '투표 시각. NULL=미투표',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_voters` (`room_id`, `voter_key`),
  UNIQUE KEY `uk_vote_voters_invite` (`invite_token`),
  -- 같은 방에 같은 이름 금지. 참여자 목록에서 동명이 구분되지 않으면
  -- 누가 아직 투표하지 않았는지 알 수 없다.
  UNIQUE KEY `uk_vote_voters_nick` (`room_id`, `nickname`),
  -- t_vote_ballots 의 복합 FK (room_id, voter_id) 대상.
  UNIQUE KEY `uk_vote_voters_room_id` (`room_id`, `id`),
  CONSTRAINT `fk_vote_voters_room` FOREIGN KEY (`room_id`)
    REFERENCES `t_vote_rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='투표 참여자';

-- -------------------------------------------------------------
-- 실제 표
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_vote_ballots`;
CREATE TABLE `t_vote_ballots` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id`    BIGINT UNSIGNED NOT NULL,
  `option_id`  BIGINT UNSIGNED NOT NULL,
  `voter_id`   BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_ballots` (`option_id`, `voter_id`),

  -- room_id 는 집계 편의를 위한 비정규화 컬럼이다. option/voter 를
  -- 각각 단독 FK 로만 걸면 세 컬럼의 room 이 서로 어긋난 표가 통과하므로
  -- (room_id, option_id) / (room_id, voter_id) 복합 FK 로 묶는다.
  KEY `idx_vote_ballots_room_option` (`room_id`, `option_id`),
  KEY `idx_vote_ballots_room_voter` (`room_id`, `voter_id`),
  CONSTRAINT `fk_vote_ballots_room` FOREIGN KEY (`room_id`)
    REFERENCES `t_vote_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vote_ballots_option` FOREIGN KEY (`room_id`, `option_id`)
    REFERENCES `t_vote_options` (`room_id`, `id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vote_ballots_voter` FOREIGN KEY (`room_id`, `voter_id`)
    REFERENCES `t_vote_voters` (`room_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='투표 기록';

-- -------------------------------------------------------------
-- 네이버 질의 캐시
--
-- 캐시를 "지역" 단위로 잡으면 안 된다. 던지는 질의는 지역 + 카테고리 +
-- 목적의 조합이라, 같은 지역이라도 사용자가 새 카테고리를 고르면
-- 한 번도 던진 적 없는 질의가 생긴다.
-- 지역 단위로 막으면 그 질의가 영원히 나가지 않아 해당 업종이 후보에
-- 절대 들어오지 않는다 (실측: 강남역에서 "카페/디저트"를 골라도
-- 네이버 호출 0회, 카페 결과 0건).
-- 그래서 질의 문자열 단위로 최근 호출 여부를 기록한다.
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_naver_queries`;
CREATE TABLE `t_naver_queries` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `query_hash` CHAR(40)     NOT NULL COMMENT 'sha1(질의문) - 인덱스 길이 제한 회피',
  `query_text` VARCHAR(191) NOT NULL COMMENT '실제 질의문 (디버깅용)',
  `hit_count`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '결과 건수',
  `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_naver_queries` (`query_hash`),
  KEY `idx_naver_queries_time` (`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='네이버 지역검색 질의 캐시 (질의 단위 재호출 억제)';

-- -------------------------------------------------------------
-- 검색 로그 (추천 품질 개선용)
-- -------------------------------------------------------------
DROP TABLE IF EXISTS `t_search_logs`;
CREATE TABLE `t_search_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `keyword`     VARCHAR(120) NOT NULL DEFAULT '',
  `criteria`    TEXT NULL COMMENT '요청 조건 JSON',
  `result_cnt`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `naver_hit`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '네이버 API 호출 여부',
  `elapsed_ms`  INT UNSIGNED NOT NULL DEFAULT 0,
  `ip`          VARCHAR(45) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_search_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='검색 로그';
