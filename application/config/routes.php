<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| DINNERSPOT 라우팅
| -------------------------------------------------------------------------
*/

$route['default_controller'] = 'home';
$route['404_override']       = 'home/not_found';
$route['translate_uri_dashes'] = FALSE;

/* ---------- 화면 ---------- */
$route['recommend']            = 'home/recommend';
$route['place/(:num)']         = 'home/place/$1';
$route['guide']                = 'home/guide';
$route['guide/geocode']        = 'home/geocode';
$route['guide/diagnose']       = 'home/diagnose';

/* ---------- 투표 ---------- */
$route['vote/new']             = 'vote/create_form';
$route['vote/r/([A-Za-z0-9]+)']        = 'vote/room/$1';
$route['vote/r/([A-Za-z0-9]+)/result'] = 'vote/result/$1';
$route['vote/r/([A-Za-z0-9]+)/invites'] = 'vote/invites/$1';
/* 1인 1링크 - 초대 토큰으로 바로 입장 (이름이 이미 확정된 상태) */
$route['vote/i/([a-f0-9]+)']           = 'vote/invite/$1';

/* ---------- JSON API ---------- */
$route['api/areas']            = 'api/areas';
$route['api/categories']       = 'api/categories';
$route['api/recommend']        = 'api/recommend';
$route['api/place/(:num)']     = 'api/place/$1';
$route['api/vote/create']      = 'api/vote_create';
$route['api/vote/([A-Za-z0-9]+)']            = 'api/vote_state/$1';
$route['api/vote/([A-Za-z0-9]+)/cast']       = 'api/vote_cast/$1';
$route['api/vote/([A-Za-z0-9]+)/close']      = 'api/vote_close/$1';
$route['api/vote/i/([a-f0-9]+)']             = 'api/invite_state/$1';
$route['api/vote/i/([a-f0-9]+)/cast']        = 'api/invite_cast/$1';

/* ---------- 관리자 (admin_password 미설정 시 404) ---------- */
$route['admin']                = 'admin/index';
$route['admin/login']          = 'admin/login';
$route['admin/logout']         = 'admin/logout';
$route['admin/room/([A-Za-z0-9]+)'] = 'admin/room/$1';
