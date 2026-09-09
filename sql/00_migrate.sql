-- =============================================================
--  DINNERSPOT - 데이터를 지우지 않는 스키마 갱신 (마이그레이션)
--
--  실행: mysql -uroot --default-character-set=utf8mb4 dinnerspot < sql/00_migrate.sql
--
--  01_schema.sql 은 DROP TABLE 로 시작하므로 재실행하면 **투표 기록과
--  수집한 장소가 전부 사라진다.** 스키마를 바꿀 일이 생겼을 때 그 파일을
--  다시 돌리면 안 된다. 대신 이 파일에 변경분을 누적하고 이 파일을 돌린다.
--
--  이 파일의 모든 문장은 **여러 번 실행해도 안전**해야 한다:
--    - 테이블 추가       : CREATE TABLE IF NOT EXISTS
--    - 컬럼/인덱스 추가  : 아래 ds_add_column / ds_add_index 프로시저
--    - 기준 데이터 갱신  : INSERT ... ON DUPLICATE KEY UPDATE  (02_seed.sql 에)
--
--  01_schema.sql 은 "빈 DB에서 처음 만들 때"만 쓴다.
--  새 스키마 변경은 두 파일 **양쪽에** 반영해야 한다
--  (01 = 신규 설치용 정본, 00 = 기존 DB 갱신용).
-- =============================================================

USE `dinnerspot`;
SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 멱등 헬퍼: 없을 때만 컬럼/인덱스를 추가한다.
-- MariaDB/MySQL 은 ADD COLUMN IF NOT EXISTS 지원이 버전마다 달라
-- information_schema 를 직접 보는 프로시저로 통일한다.
-- -------------------------------------------------------------
DROP PROCEDURE IF EXISTS ds_add_column;
DROP PROCEDURE IF EXISTS ds_add_index;

DELIMITER $$

CREATE PROCEDURE ds_add_column(
  IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END $$

CREATE PROCEDURE ds_add_index(
  IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = p_table AND INDEX_NAME = p_index
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_def);
    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END $$

DELIMITER ;


-- =============================================================
--  변경 이력 - 아래에 계속 누적한다 (지우지 말 것)
-- =============================================================

-- 2026-09-08 · 네이버 질의 캐시
-- 재호출 억제를 "지역" 단위로 하면 같은 지역에서 새 카테고리를 골랐을 때
-- 그 질의가 영원히 나가지 않는다. 질의 문자열 단위로 기록한다.
CREATE TABLE IF NOT EXISTS `t_naver_queries` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `query_hash` CHAR(40)     NOT NULL COMMENT 'sha1(질의문)',
  `query_text` VARCHAR(191) NOT NULL COMMENT '실제 질의문 (디버깅용)',
  `hit_count`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '결과 건수',
  `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_naver_queries` (`query_hash`),
  KEY `idx_naver_queries_time` (`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='네이버 지역검색 질의 캐시';

-- 2026-09-08 · 장소: 네이버 순위 / 속성 실측 여부
CALL ds_add_column('t_places', 'naver_rank',
  "SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '네이버 결과 내 순위 (1=최상위, 0=미상)'");
CALL ds_add_column('t_places', 'attrs_verified',
  "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=가격·좌석이 실측값'");

-- 2026-09-08 · 지역: 종류 / 노선 / 좌표 출처
CALL ds_add_column('t_areas', 'kind',
  "ENUM('station','district') NOT NULL DEFAULT 'station' COMMENT 'station=지하철역, district=상권'");
CALL ds_add_column('t_areas', 'line_info',
  "VARCHAR(60) NOT NULL DEFAULT '' COMMENT '지나는 노선'");
CALL ds_add_column('t_areas', 'geo_verified',
  "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=출처 확인된 좌표'");
CALL ds_add_column('t_areas', 'geo_source',
  "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '좌표 출처 URL 또는 naver'");
-- geo_verified 를 걸러 읽는 인덱스. 01_schema.sql 에는 있는데 여기 없어서
-- 마이그레이션으로 올라온 DB 만 이 인덱스가 빠져 있었다
-- (Place_model 의 geocode_pending 계열이 `where('geo_verified', 0)` 로 조회한다).
CALL ds_add_index('t_areas', 'idx_areas_geo',
  "KEY `idx_areas_geo` (`geo_verified`)");

-- 2026-09-08 · 투표방: 참여 방식 (open / invite=1인1링크)
CALL ds_add_column('t_vote_rooms', 'mode',
  "ENUM('open','invite') NOT NULL DEFAULT 'open' COMMENT '참여 방식'");

-- 2026-09-08 · 참여자: 초대 토큰 + 미투표 상태
CALL ds_add_column('t_vote_voters', 'invite_token',
  "CHAR(16) NULL DEFAULT NULL COMMENT '초대 링크 토큰 (open 모드는 NULL)'");
CALL ds_add_index('t_vote_voters', 'uk_vote_voters_invite',
  "UNIQUE KEY `uk_vote_voters_invite` (`invite_token`)");

-- voted_at 은 초대만 되고 아직 투표하지 않은 행을 위해 NULL 을 허용해야 한다.
-- 이미 NULL 허용이면 아무 일도 하지 않는다(같은 정의를 다시 적용).
ALTER TABLE `t_vote_voters`
  MODIFY `voted_at` DATETIME NULL DEFAULT NULL COMMENT '투표 시각. NULL=미투표';


-- -------------------------------------------------------------
-- uk_vote_voters_nick (room_id, nickname) - **반드시 맨 마지막에**
--
-- 레거시 DB 에 같은 방 동명이인이 이미 있으면 이 유니크 키 추가가
-- ERROR 1062 로 죽는다. mysql CLI 는 첫 오류에서 스크립트를 중단하므로,
-- 이 문장이 중간에 있으면 아래 문장이 전부 실행되지 않아 DB 가 반쪽만
-- 갱신된 상태로 남는다(실측: voted_at 이 NOT NULL 로 남아 invite 모드의
-- `voted_at IS NULL` 미투표 판정이 조용히 전원 '투표 완료' 가 됐다).
--
-- 그래서 (1) 순서를 맨 뒤로 옮기고 (2) 중복이 남아 있으면 이 단계만
-- 건너뛰면서 어느 행을 고쳐야 하는지 출력한다. 이름을 자동으로 바꾸지는
-- 않는다 — 동명이인이 실제로 있을 수 있어 번호 부여는 정책 판단이고,
-- 규칙으로 금지돼 있다. 이름을 고친 뒤 이 파일을 다시 실행하면 붙는다.
-- (중복 판정은 DB 콜레이션 기준이라 José=Jose, Ａ=A 도 중복으로 잡힌다 —
--  유니크 키가 보는 기준과 같다.)
-- -------------------------------------------------------------
DROP PROCEDURE IF EXISTS ds_uk_voter_nick;

DELIMITER $$

CREATE PROCEDURE ds_uk_voter_nick()
BEGIN
  DECLARE v_dup INT DEFAULT 0;

  SELECT COUNT(*) INTO v_dup FROM (
    SELECT `room_id` FROM `t_vote_voters`
     GROUP BY `room_id`, `nickname` HAVING COUNT(*) > 1
  ) d;

  IF v_dup > 0 THEN
    SELECT CONCAT('건너뜀: 같은 방에 중복된 이름이 ', v_dup,
      '쌍 남아 있어 uk_vote_voters_nick 을 추가하지 못했습니다. ',
      '아래 목록의 이름을 서로 구분되게 고친 뒤 이 파일을 다시 실행하세요. ',
      '(그 밖의 변경은 모두 적용되었습니다)') AS `주의`;

    SELECT `room_id` AS `방ID`, `nickname` AS `중복된 이름`, COUNT(*) AS `건수`
      FROM `t_vote_voters`
     GROUP BY `room_id`, `nickname` HAVING COUNT(*) > 1;

  ELSEIF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 't_vote_voters'
       AND INDEX_NAME = 'uk_vote_voters_nick'
  ) THEN
    ALTER TABLE `t_vote_voters`
      ADD UNIQUE KEY `uk_vote_voters_nick` (`room_id`, `nickname`);
  END IF;
END $$

DELIMITER ;

CALL ds_uk_voter_nick();


-- 정리
DROP PROCEDURE IF EXISTS ds_add_column;
DROP PROCEDURE IF EXISTS ds_add_index;
DROP PROCEDURE IF EXISTS ds_uk_voter_nick;

SELECT '마이그레이션 완료 - 데이터는 보존되었습니다' AS result;
