<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * DINNERSPOT 공용 헬퍼
 */

if ( ! function_exists('h'))
{
	/** HTML 이스케이프 축약 */
	function h($str)
	{
		return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
	}
}

if ( ! function_exists('ds_asset'))
{
	/** 캐시 무효화용 mtime 을 붙인 정적 자원 URL */
	function ds_asset($path)
	{
		$path = ltrim($path, '/');
		$full = FCPATH . $path;
		$ver  = file_exists($full) ? filemtime($full) : time();

		return base_url('assets/' . $path) . '?v=' . $ver;
	}
}

if ( ! function_exists('ds_won'))
{
	/** 12000 -> "1.2만원", 9500 -> "9,500원" */
	function ds_won($amount)
	{
		$amount = (int) $amount;

		if ($amount <= 0)
		{
			return '가격 미상';
		}
		if ($amount >= 10000)
		{
			$man = round($amount / 10000, 1);
			$man = ($man == (int) $man) ? (int) $man : $man;

			return $man . '만원';
		}

		return number_format($amount) . '원';
	}
}

if ( ! function_exists('ds_distance_label'))
{
	/** 미터 -> "도보 3분 · 240m" 형태 라벨 */
	function ds_distance_label($meters)
	{
		if ($meters === NULL || $meters < 0)
		{
			return '';
		}

		$meters = (int) round($meters);
		$walk   = max(1, (int) round($meters / 67)); // 도보 약 67m/분

		$dist = ($meters >= 1000)
			? round($meters / 1000, 1) . 'km'
			: $meters . 'm';

		return ($meters <= 1600)
			? '도보 ' . $walk . '분 · ' . $dist
			: $dist;
	}
}

if ( ! function_exists('ds_haversine'))
{
	/** 두 좌표 사이 거리(m) */
	function ds_haversine($lat1, $lng1, $lat2, $lng2)
	{
		$r = 6371000;
		$p1 = deg2rad((float) $lat1);
		$p2 = deg2rad((float) $lat2);
		$dp = deg2rad((float) $lat2 - (float) $lat1);
		$dl = deg2rad((float) $lng2 - (float) $lng1);

		$a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

		return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
	}
}

if ( ! function_exists('ds_strip_naver_tag'))
{
	/** 네이버 검색결과 title 의 <b> 태그와 엔티티 제거 */
	function ds_strip_naver_tag($str)
	{
		$str = strip_tags((string) $str);

		return trim(html_entity_decode($str, ENT_QUOTES, 'UTF-8'));
	}
}

if ( ! function_exists('ds_token'))
{
	/** 32자 랜덤 토큰 */
	function ds_token($len = 32)
	{
		if (function_exists('random_bytes'))
		{
			return substr(bin2hex(random_bytes((int) ceil($len / 2))), 0, $len);
		}

		return substr(md5(uniqid((string) mt_rand(), TRUE)), 0, $len);
	}
}

if ( ! function_exists('ds_room_code'))
{
	/** 사람이 옮겨적기 쉬운 방 코드 (혼동문자 0,O,1,I,L 제외) */
	function ds_room_code($len = 8)
	{
		$pool = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$max  = strlen($pool) - 1;
		$code = '';

		for ($i = 0; $i < $len; $i++)
		{
			$code .= $pool[mt_rand(0, $max)];
		}

		return $code;
	}
}

if ( ! function_exists('ds_time_ago'))
{
	/** "3분 전" 형태 */
	function ds_time_ago($datetime)
	{
		if (empty($datetime))
		{
			return '';
		}

		$diff = time() - strtotime($datetime);

		if ($diff < 60)   return '방금';
		if ($diff < 3600) return floor($diff / 60) . '분 전';
		if ($diff < 86400) return floor($diff / 3600) . '시간 전';

		return floor($diff / 86400) . '일 전';
	}
}

if ( ! function_exists('ds_score_grade'))
{
	/** 점수 -> 등급 라벨/색 클래스 */
	function ds_score_grade($score)
	{
		if ($score >= 85) return array('label' => '강력추천', 'class' => 'grade-s');
		if ($score >= 72) return array('label' => '추천',     'class' => 'grade-a');
		if ($score >= 58) return array('label' => '무난',     'class' => 'grade-b');

		return array('label' => '차선책', 'class' => 'grade-c');
	}
}
