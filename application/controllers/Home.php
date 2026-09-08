<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * 검색 / 추천결과 / 상세 화면
 */
class Home extends MY_Controller {

	public function __construct()
	{
		parent::__construct();

		$this->load->library('spot_service');
		$this->load->model('place_model');
	}

	/** 메인 - 조건 입력 */
	public function index()
	{
		$meta = $this->spot_service->form_meta();

		$this->render('home/index', array(
			'meta'   => $meta,
			'recent' => $this->recent_picks(),
		), array(
			'nav'        => 'home',
			'body_class' => 'page-home',
		));
	}

	/** 추천 결과 */
	public function recommend()
	{
		$input = $this->input->method(TRUE) === 'POST'
			? $this->input->post(NULL, FALSE)
			: $this->input->get(NULL, FALSE);

		$input = is_array($input) ? $input : array();

		// 체크박스 계열은 문자열 'on' 으로 오므로 그대로 truthy 처리된다
		if (isset($input['categories']) && is_string($input['categories']))
		{
			$input['categories'] = array_filter(explode(',', $input['categories']));
		}

		$out  = $this->spot_service->recommend($input);
		$meta = $this->spot_service->form_meta();

		$this->render('home/recommend', array(
			'meta'   => $meta,
			'result' => $out,
		), array(
			'title'      => $out['origin']['label'] . ' 회식장소 추천 · DINNERSPOT',
			'nav'        => 'home',
			'body_class' => 'page-recommend',
		));
	}

	/** 장소 상세 */
	public function place($id)
	{
		$place = $this->place_model->get($id);

		if ( ! $place)
		{
			show_404();

			return;
		}

		$cat_map = $this->place_model->category_map();
		$cat     = isset($cat_map[$place['category_code']])
			? $cat_map[$place['category_code']]
			: NULL;

		$this->render('home/place', array(
			'place'   => $place,
			'cat'     => $cat,
			'map_key' => (string) $this->config->item('naver_map_key_id', 'dinnerspot'),
		), array(
			'title'      => $place['name'] . ' · DINNERSPOT',
			'nav'        => 'home',
			'body_class' => 'page-place',
		));
	}

	/** 사용 가이드 / 설정 안내 */
	public function guide()
	{
		$meta = $this->spot_service->form_meta();

		$this->render('home/guide', array(
			'meta'          => $meta,
			'naver_enabled' => $meta['naver_enabled'],
			'map_key'       => (string) $this->config->item('naver_map_key_id', 'dinnerspot'),
			'geo_result'    => $this->session->flashdata('geo_result'),
			'diag'          => $this->session->flashdata('diag'),
			'diag_map'      => $this->session->flashdata('diag_map'),
		), array(
			'title'      => '설정 가이드 · DINNERSPOT',
			'nav'        => 'guide',
			'body_class' => 'page-guide',
		));
	}

	/**
	 * 네이버 연결 진단.
	 *
	 * 실제로 한 번 호출해 인증이 되는지, 안 되면 왜 안 되는지를 알려준다.
	 * 개발자센터 키와 클라우드 플랫폼 키를 헷갈리는 경우가 많아 그것도 짚어준다.
	 */
	public function diagnose()
	{
		if ($this->input->method(TRUE) !== 'POST')
		{
			redirect('guide');

			return;
		}

		$this->load->library('naver_local');

		$this->session->set_flashdata('diag', $this->naver_local->diagnose());
		$this->session->set_flashdata('diag_map',
			$this->naver_local->diagnose_map(base_url()));

		redirect('guide');
	}

	/**
	 * 좌표 미확인 지역을 네이버 지역검색으로 보정한다.
	 *
	 * 역/상권 좌표를 추측으로 시드에 박아넣지 않았기 때문에,
	 * 네이버 키가 있을 때 이 경로로 실제 좌표를 채운다.
	 */
	public function geocode()
	{
		if ($this->input->method(TRUE) !== 'POST')
		{
			redirect('guide');

			return;
		}

		$res = $this->place_model->geocode_pending_areas(30);

		if ($res['skipped'] !== '')
		{
			$msg = '좌표 보정을 건너뛰었습니다: ' . $res['skipped'];
		}
		else
		{
			$msg = $res['done'] . '곳의 좌표를 채웠습니다.';

			if ( ! empty($res['failed']))
			{
				$msg .= ' 못 찾은 곳: ' . implode(', ', array_slice($res['failed'], 0, 10));
			}
		}

		$this->session->set_flashdata('geo_result', $msg);
		redirect('guide');
	}

	public function not_found()
	{
		$this->output->set_status_header(404);

		$this->render('home/404', array(), array(
			'title' => '페이지를 찾을 수 없습니다 · DINNERSPOT',
			'nav'   => '',
		));
	}

	/** 최근 투표 후보로 많이 뽑힌 장소 */
	protected function recent_picks($limit = 6)
	{
		$rows = $this->db->select('id, name, category_code, address, road_address, avg_price, rating, review_count, pick_count')
			->from('t_places')
			->where('is_active', 1)
			->where('pick_count >', 0)
			->order_by('pick_count', 'DESC')
			->order_by('rating', 'DESC')
			->limit($limit)
			->get()
			->result_array();

		$cat_map = $this->place_model->category_map();

		foreach ($rows as &$r)
		{
			$c = isset($cat_map[$r['category_code']]) ? $cat_map[$r['category_code']] : NULL;
			$r['category_label'] = $c ? $c['label'] : '기타';
			$r['category_emoji'] = $c ? $c['emoji'] : '🍴';
		}

		return $rows;
	}
}
