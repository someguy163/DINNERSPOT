<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * DINNERSPOT 애플리케이션 설정 (기본값)
 *
 * ###############################################################
 * #  이 파일에는 실제 키를 넣지 마세요.                          #
 * #                                                             #
 * #  1) 이 파일은 git 에 올라갑니다. 키를 쓰면 저장소에 유출됩니다. #
 * #  2) 파일 맨 아래에서 dinnerspot_local.php 를 include 하므로,  #
 * #     거기에 같은 키가 있으면 여기 쓴 값은 무조건 덮어써집니다.  #
 * #     (빈 문자열로도 덮어써집니다 - 값을 넣어도 무시됩니다)      #
 * #                                                             #
 * #  실제 키는 application/config/dinnerspot_local.php 에.        #
 * #  그 파일은 .gitignore 되어 있습니다.                          #
 * ###############################################################
 */

/* -----------------------------------------------------------------
 |  [1] 지역검색 API - 발급처 두 곳 중 아무거나
 |
 |  (A) 클라우드 플랫폼 > NAVER API HUB
 |      콘솔  : https://console.ncloud.com  (NAVER API HUB > Application)
 |               Application 에 "NAVER 검색 > 지역" 등록 후 [인증 정보]
 |      호출  : GET https://naverapihub.apigw.ntruss.com/search/v1/local
 |               X-NCP-APIGW-API-KEY-ID / X-NCP-APIGW-API-KEY
 |      문서  : https://api.ncloud-docs.com/docs/naver-api-hub-search-local
 |
 |  (B) 네이버 개발자센터
 |      발급  : https://developers.naver.com/apps/#/register  (사용 API 에서 "검색")
 |      호출  : GET https://openapi.naver.com/v1/search/local.json
 |               X-Naver-Client-Id / X-Naver-Client-Secret
 |      문서  : https://developers.naver.com/docs/serviceapi/search/local/local.md
 |
 |  둘 다 아래 두 값에 넣으면 되고, naver_api_mode='auto' 가 알아서 고릅니다.
 |  응답 형식이 같아서 나머지 코드는 어느 쪽인지 몰라도 됩니다.
 |  비워두면 네이버 호출 없이 DB 데이터만으로 동작합니다.
 | ----------------------------------------------------------------- */
$config['naver_client_id']     = '';
$config['naver_client_secret'] = '';

/* -----------------------------------------------------------------
 |  [2] 네이버 클라우드 플랫폼 - Maps JavaScript API v3 (선택)
 |
 |  스크립트 : https://oapi.map.naver.com/openapi/v3/maps.js?ncpKeyId=<KeyID>
 |             (구 파라미터 ncpClientId 는 ncpKeyId 로 바뀌었습니다)
 |  콘솔     : https://console.ncloud.com/maps/application
 |             Services > Application Services > Maps > Application 등록 후
 |             "Dynamic Map" 체크 필수. 빠뜨리면 429 Quota Exceed 가 납니다.
 |  문서     : https://guide.ncloud-docs.com/docs/application-maps-app-vpc
 |             https://navermaps.github.io/maps.js.ncp/docs/tutorial-2-Getting-Started.html
 |
 |  [1] 과 발급처가 다릅니다. 비워두면 지도 없이 목록만 렌더링합니다.
 | ----------------------------------------------------------------- */
$config['naver_map_key_id'] = '';

/* 네이버 API 호출 옵션 */
$config['naver_timeout']     = 4;    // 초
$config['naver_display']     = 5;    // 지역검색 API 1회 최대 건수(고정 5)
$config['naver_max_queries'] = 8;    // 한 번의 추천에서 던질 최대 질의 수
$config['naver_cache_hours'] = 24;   // 동일 지역 재수집 억제 시간
$config['naver_recollect_min'] = 1;  // 기준점 근처에 최근 수집분이 이 수보다 적으면 다시 수집
                                     // (1 = 한 번이라도 수집했으면 건너뜀. 같은 질의를
                                     //  다시 던져봐야 같은 결과라 호출만 낭비된다)
$config['naver_sort']        = 'comment';  // comment=리뷰순(인기도 신호로 활용) | random

/* -----------------------------------------------------------------
 |  지역검색을 어느 경로로 호출할지
 |
 |    'auto'       : 자격증명 형태를 보고 알아서 고른다 (기본).
 |                   실패하면 다른 쪽으로 한 번 더 시도한다.
 |    'apihub'     : 클라우드 플랫폼 NAVER API HUB
 |                   https://naverapihub.apigw.ntruss.com/search/v1/local
 |                   X-NCP-APIGW-API-KEY-ID / X-NCP-APIGW-API-KEY
 |    'developers' : 네이버 개발자센터
 |                   https://openapi.naver.com/v1/search/local.json
 |                   X-Naver-Client-Id / X-Naver-Client-Secret
 |
 |  응답 형식은 두 경로가 같아서 나머지 코드는 영향받지 않는다.
 | ----------------------------------------------------------------- */
$config['naver_api_mode'] = 'auto';

/* -----------------------------------------------------------------
 |  추천 후보를 어디서 가져올지
 |
 |    'naver' : 네이버로 수집한 장소만 추천한다 (기본).
 |              키가 없으면 자동으로 'db' 로 폴백한다.
 |    'db'    : DB 에 직접 등록/보정한 장소만 (샘플 데이터 포함).
 |    'both'  : 둘 다 섞어서 추천.
 |
 |  'naver' 는 커버리지가 넓지만 네이버가 가격·평점을 주지 않아
 |  예산/평점 항목이 추정값이 된다. 대신 지역검색 결과 순위(naver_rank)를
 |  인기도 신호로 사용한다.
 | ----------------------------------------------------------------- */
$config['source_mode'] = 'naver';

/* -----------------------------------------------------------------
 |  추천 점수 가중치 (합계 100 기준)
 |  Recommender 라이브러리가 이 값으로 최종 점수를 만든다.
 | ----------------------------------------------------------------- */
$config['score_weights'] = array(
	'distance' => 28,   // 기준점에서 가까울수록
	'budget'   => 22,   // 1인 예산 적합도
	'capacity' => 16,   // 인원 수용 가능 여부
	'rating'   => 15,   // 평점 x 리뷰수 신뢰도
	'amenity'  => 12,   // 룸/주차/심야 등 옵션 충족
	'category' => 7,    // 선택한 음식 종류 일치
);

/* 검색 기본값 */
$config['default_radius']    = 800;   // m
$config['radius_options']    = array(300, 500, 800, 1500, 3000);
$config['default_headcount'] = 6;
$config['default_budget']    = 25000;
$config['result_limit']      = 20;    // 추천 결과 최대 건수

/* 예산 구간 프리셋 (원) */
$config['budget_presets'] = array(
	array('label' => '1만원 이하',   'value' => 10000),
	array('label' => '1~2만원',      'value' => 15000),
	array('label' => '2~3만원',      'value' => 25000),
	array('label' => '3~5만원',      'value' => 40000),
	array('label' => '5만원 이상',   'value' => 60000),
);

/* -----------------------------------------------------------------
 |  관리자 화면 (/admin)
 |
 |  전체 투표 현황을 보는 화면입니다. 비밀번호를 비워두면 **화면 자체가
 |  꺼집니다**(404). 로그인 체계가 없는 프로젝트이므로, 켜려면
 |  dinnerspot_local.php 에 비밀번호를 넣으세요:
 |
 |    $config['admin_password'] = '원하는 비밀번호';
 |
 |  기본이 "꺼짐" 인 이유: 값을 안 넣었는데 관리자 화면이 열려 있으면
 |  링크를 아는 누구나 모든 투표 현황을 볼 수 있게 된다.
 | ----------------------------------------------------------------- */
$config['admin_password'] = '';

/* 투표 설정 */
$config['vote_max_options']   = 6;     // 후보 최대 개수
$config['vote_min_options']   = 2;
$config['vote_code_length']   = 8;
$config['vote_poll_interval'] = 4000;  // ms, 결과 폴링 주기

/* 로컬 오버라이드 */
if (file_exists(APPPATH . 'config/dinnerspot_local.php'))
{
	include APPPATH . 'config/dinnerspot_local.php';
}
