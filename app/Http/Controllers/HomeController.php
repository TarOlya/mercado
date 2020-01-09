<?php
/**
 * LaraClassified - Classified Ads Web Application
 * Copyright (c) BedigitCom. All Rights Reserved
 *
 * Website: http://www.bedigit.com
 *
 * LICENSE
 * -------
 * This software is furnished under a license and may be used and copied
 * only in accordance with the terms of such license and with the inclusion
 * of the above copyright notice. If you Purchased from Codecanyon,
 * Please read the full License from here - http://codecanyon.net/licenses/standard
 */

namespace App\Http\Controllers;

use App\Helpers\ArrayHelper;
use App\Helpers\DBTool;
use App\Models\Message;
use App\Models\Post;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\SubAdmin1;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Torann\LaravelMetaTags\Facades\MetaTag;
use App\Helpers\Localization\Helpers\Country as CountryLocalizationHelper;
use App\Helpers\Localization\Country as CountryLocalization;


class HomeController extends FrontController
{

    private $cats;

	/**
	 * HomeController constructor.
	 */
	public function __construct()
	{
		parent::__construct();
		
		// Check Country URL for SEO
		$countries = CountryLocalizationHelper::transAll(CountryLocalization::getCountries());
		view()->share('countries', $countries);

		$this->commonQueries();
	}

    /**
     * Common Queries
     */
    public function commonQueries()
    {
        // Get all Categories
        $cacheId = 'categories.all.' . config('app.locale');
        $cats = Cache::remember($cacheId, $this->cacheExpiration, function () {
            $cats = Category::trans()->orderBy('lft')->get();
            return $cats;
        });
        if ($cats->count() > 0) {
            $cats = collect($cats)->keyBy('tid');
        }
        view()->share('cats', $cats);
    }
	
	/**
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 */
	public function index()
	{
		$data = [];
		$countryCode = config('country.code');
		
		// Get all homepage sections
		$cacheId = $countryCode . '.homeSections';
		$data['sections'] = Cache::remember($cacheId, $this->cacheExpiration, function () use ($countryCode) {
			$sections = collect([]);
			
			// Check if the Domain Mapping plugin is available
			if (config('plugins.domainmapping.installed')) {
				try {
					$sections = \App\Plugins\domainmapping\app\Models\DomainHomeSection::where('country_code', $countryCode)->orderBy('lft')->get();
				} catch (\Exception $e) {}
			}
			
			// Get the entry from the core
			if ($sections->count() <= 0) {
				$sections = HomeSection::orderBy('lft')->get();
			}
			
			return $sections;
		});
		
		if ($data['sections']->count() > 0) {
			foreach ($data['sections'] as $section) {
				// Clear method name
				$method = str_replace(strtolower($countryCode) . '_', '', $section->method);
				
				// Check if method exists
				if (!method_exists($this, $method)) {
					continue;
				}
				
				// Call the method
				try {
					if (isset($section->value)) {
						$this->{$method}($section->value);
					} else {
						$this->{$method}();
					}
				} catch (\Exception $e) {
					flash($e->getMessage())->error();
					continue;
				}
			}
		}
		
		// Get SEO
		$this->setSeo();

		// R.S.
		// // ARHIVE ALL ANONIM POSTS WHO IS OLDER THAN 30 DAYS 
		$insert = "UPDATE  ". DBTool::rawTable('posts') . " SET arhived = 1 , arhived_at = NOW () WHERE created_at < NOW() - INTERVAL 30 DAY AND user_id = 0";
		self::execute($insert);

		return view('home.index', $data);
	}

	/**
	 * Execute the SQL
	 *
	 * @param $sql
	 * @param array $bindings
	 * @return mixed
	 */
	private static function execute($sql, $bindings = [])
	{
		// DEBUG
		// echo 'SQL<hr><pre>' . $sql . '</pre><hr>'; // exit();
		// echo 'BINDINGS<hr><pre>'; print_r($bindings); echo '</pre><hr>'; // exit();
		try {
			$result = DB::select(DB::raw($sql), $bindings);
		} catch (\Exception $e) {
			$result = null;
			// DEBUG
			// dd($e->getMessage());
		}

		return $result;
	}
	
	/**
	 * Get search form (Always in Top)
	 *
	 * @param array $value
	 */
	protected function getSearchForm($value = [])
	{
		view()->share('searchFormOptions', $value);
	}
	
	/**
	 * Get locations & SVG map
	 *
	 * @param array $value
	 */
	protected function getLocations($value = [])
	{
		// Get the default Max. Items
		$maxItems = 14;
		if (isset($value['max_items'])) {
			$maxItems = (int)$value['max_items'];
		}
		
		// Get the Default Cache delay expiration
		$cacheExpiration = $this->getCacheExpirationTime($value);
		
		// Modal - States Collection
		$cacheId = config('country.code') . '.home.getLocations.modalAdmins';
		$modalAdmins = Cache::remember($cacheId, $cacheExpiration, function () {
			$modalAdmins = SubAdmin1::currentCountry()->orderBy('name')->get(['code', 'name'])->keyBy('code');
			
			return $modalAdmins;
		});
		view()->share('modalAdmins', $modalAdmins);
		
		// Get cities
		$cacheId = config('country.code') . 'home.getLocations.cities';
		$cities = Cache::remember($cacheId, $cacheExpiration, function () use ($maxItems) {
			$cities = City::currentCountry()->take($maxItems)->orderBy('population', 'DESC')->orderBy('name')->get();
			
			return $cities;
		});
		$cities = collect($cities)->push(ArrayHelper::toObject([
			'id'             => 999999999,
			'name'           => t('More cities') . ' &raquo;',
			'subadmin1_code' => 0,
		]));
		
		// Get cities number of columns
		$numberOfCols = 4;
		if (file_exists(config('larapen.core.maps.path') . strtolower(config('country.code')) . '.svg')) {
			if (isset($value['show_map']) && $value['show_map'] == '1') {
				$numberOfCols = (isset($value['items_cols']) && !empty($value['items_cols'])) ? (int)$value['items_cols'] : 3;
			}
		}
		
		// Chunk
		$maxRowsPerCol = round($cities->count() / $numberOfCols, 0); // PHP_ROUND_HALF_EVEN
		$maxRowsPerCol = ($maxRowsPerCol > 0) ? $maxRowsPerCol : 1; // Fix array_chunk with 0
		$cities = $cities->chunk($maxRowsPerCol);
		
		view()->share('cities', $cities);
		view()->share('citiesOptions', $value);
	}
	
	/**
	 * Get sponsored posts
	 *
	 * @param array $value
	 */
	protected function getSponsoredPosts($value = [])
	{
		// Get the default Max. Items
		$maxItems = 20;
		if (isset($value['max_items'])) {
			$maxItems = (int)$value['max_items'];
		}
		
		// Get the default orderBy value
		$orderBy = 'random';
		if (isset($value['order_by'])) {
			$orderBy = $value['order_by'];
		}
		
		// Get the default Cache delay expiration
		$cacheExpiration = $this->getCacheExpirationTime($value);
		
		$sponsored = null;
		
		// Get featured posts
		$posts = $this->getPosts($maxItems, 'sponsored', $cacheExpiration);
	
		if (!empty($posts)) {
			if ($orderBy == 'random') {
				$posts = ArrayHelper::shuffleAssoc($posts);
			}
			$attr = ['countryCode' => config('country.icode')];
			$sponsored = [
				'title' => t('Home - Sponsored Ads'),
				'link'  => lurl(trans('routes.v-search', $attr), $attr),
				'posts' => $posts,
			];
			$sponsored = ArrayHelper::toObject($sponsored);
		}
		
		view()->share('featured', $sponsored);
		view()->share('featuredOptions', $value);
	}
	
	/**
	 * Get latest posts
	 *
	 * @param array $value
	 */
	protected function getLatestPosts($value = [])
	{

		// Get the default Max. Items
		$maxItems = 12;
		if (isset($value['max_items'])) {
			$maxItems = (int)$value['max_items'];
		}
		// Get the default orderBy value
		$orderBy = 'date';
		if (isset($value['order_by'])) {
			$orderBy = $value['order_by'];
		}
		
		// Get the Default Cache delay expiration
		$cacheExpiration = $this->getCacheExpirationTime($value);

		// Get latest posts
		$posts = $this->getPosts($maxItems, 'latest', $cacheExpiration);
		// var_dump("Posts");
		// var_dump($posts);
		if (!empty($posts)) {
			if ($orderBy == 'random') {
				$posts = ArrayHelper::shuffleAssoc($posts);
			}
		}
		
		view()->share('posts', $posts);
		view()->share('latestOptions', $value);
	}

	/**
	 * Get bigBanners
	 * R.S
	 * @param array $value
	 */
	protected function getBanner($value = []){

		$bigBanners = [];
		$smallBanners = [];

		if($value['active'] == 1){
			// Big bigBanners
			for($i = 1 ; $i <= 3 ; $i++){
				if(isset($value['banner_img_' . $i ]) && !is_null($value['banner_img_' .$i])){
					$bigBanners[$i] = $value['banner_img_' . $i];
				}
			}

			// Small bigBanners
			for($i = 1 ; $i <= 3 ; $i++){
				if(isset($value['banner_img_small_' . $i ]) && !is_null($value['banner_img_small_' . $i ])){
					$smallBanners[$i] = $value['banner_img_small_' . $i];
				}
			}

			view()->share('bigBanners', $bigBanners);
			view()->share('smallBanners', $smallBanners);
		}
	}

	/**
	 * Get bigBanners
	 * R.S
	 * @param array $value
	 */
	protected function getBanner2($value = []){

		$bigBanners = [];
		$smallBanners = [];
		if($value['active'] == 1){
			// Big bigBanners
			for($i = 1 ; $i <= 3 ; $i++){
				if(isset($value['banner_img_' . $i ]) && !is_null($value['banner_img_' .$i])){
					$bigBanners[$i] = $value['banner_img_' . $i];
				}
			}

			// Small bigBanners
			for($i = 1 ; $i <= 3 ; $i++){
				if(isset($value['banner_img_small_' . $i ]) && !is_null($value['banner_img_small_' . $i ])){
					$smallBanners[$i] = $value['banner_img_small_' . $i];
				}
			}

			view()->share('bigBanners2', $bigBanners);
			view()->share('smallBanners2', $smallBanners);
		}
	}

	/**
	 * Get latest jobs
	 *R.S
	 * @param array $value
	 */
	protected function getLatestJobs($value = []){

		// Get the default Max. Items
		$maxItems = 12;
		if (isset($value['max_items'])) {
			$maxItems = (int)$value['max_items'];
		}

		// Get the default orderBy value
		$orderBy = 'date';
		if (isset($value['order_by'])) {
			$orderBy = $value['order_by'];
		}
		
		// Get the Default Cache delay expiration
		$cacheExpiration = $this->getCacheExpirationTime($value);

		// Get latest jobs
		$posts = $this->getPosts($maxItems, 'jobs', $cacheExpiration);

		if (!empty($posts)) {
			if ($orderBy == 'random') {
				$posts = ArrayHelper::shuffleAssoc($posts);
			}
		}
		
		view()->share('jobs', $posts);
		view()->share('latestOptions', $value);
	}
	
	/**
	 * Get list of categories
	 *
	 * @param array $value
	 */
	protected function getCategories($value = [])
	{
		// Get the default Max. Items
		$maxItems = 12;
		if (isset($value['max_items'])) {
			$maxItems = (int)$value['max_items'];
		}
		
		// Number of columns
		$numberOfCols = 3;
		
		// Get the Default Cache delay expiration
		$cacheExpiration = $this->getCacheExpirationTime($value);
		
		$cacheId = 'categories.parents.' . config('app.locale') . '.take.' . $maxItems;

		if (isset($value['type_of_display']) && in_array($value['type_of_display'], ['cc_normal_list', 'cc_normal_list_s'])) {
			
			$categories = Cache::remember($cacheId, $cacheExpiration, function () {
				$categories = Category::trans()->orderBy('lft')->get();
				
				return $categories;
			});
			$categories = collect($categories)->keyBy('translation_of');
			$categories = $subCategories = $categories->groupBy('parent_id');
			
			if ($categories->has(0)) {
				$categories = $categories->get(0)->take($maxItems);
				$subCategories = $subCategories->forget(0);
				
				$maxRowsPerCol = round($categories->count() / $numberOfCols, 0, PHP_ROUND_HALF_EVEN);
				$maxRowsPerCol = ($maxRowsPerCol > 0) ? $maxRowsPerCol : 1;
				$categories = $categories->chunk($maxRowsPerCol);
			} else {
				$categories = collect([]);
				$subCategories = collect([]);
			}
			
			view()->share('categories', $categories);
			view()->share('subCategories', $subCategories);
			
		} else {
			
			$categories = Cache::remember($cacheId, $cacheExpiration, function () use ($maxItems) {
				$categories = Category::trans()->where('parent_id', 0)->take($maxItems)->orderBy('lft')->get();

				return $categories;
			});
			// var_dump($categories);

			// R.S
			// Get Count And Name Of Category Language != English
			if(!strpos($cacheId, ".en")){
				foreach($categories as $key=>$val){
					if($val->id != $val->translation_of){
						// var_dump($val);
						$category = Category::where('id', $val->translation_of)->take($maxItems)->orderBy('lft')->get();
						//Leave The Original Name of Category
						$category[0]->name = $categories[$key]->name;
						unset($categories[$key]);
						$categories[$key] =  $category[0];
					}
				}
			}
			// var_dump($categories);


			if (isset($value['type_of_display']) && $value['type_of_display'] == 'c_picture_icon') {
				$categories = collect($categories)->keyBy('id');
			} else {
				// $maxRowsPerCol = round($categories->count() / $numberOfCols, 0); // PHP_ROUND_HALF_EVEN
				$maxRowsPerCol = ceil($categories->count() / $numberOfCols);
				$maxRowsPerCol = ($maxRowsPerCol > 0) ? $maxRowsPerCol : 1; // Fix array_chunk with 0
				$categories = $categories->chunk($maxRowsPerCol);
			}
				view()->share('categories', $categories);

		}

		view()->share('categoriesOptions', $value);
	}
	
	/**
	 * Get mini stats data
	 */
	protected function getStats()
	{
		// Count posts
		$countPosts = Post::currentCountry()->unarchived()->count();
		
		// Count cities
		$countCities = City::currentCountry()->count();
		
		// Count users
		$countUsers = User::count();
		
		// Share vars
		view()->share('countPosts', $countPosts);
		view()->share('countCities', $countCities);
		view()->share('countUsers', $countUsers);
	}
	
	/**
	 * Set SEO information
	 */
	protected function setSeo()
	{
		$title = getMetaTag('title', 'home');
		$description = getMetaTag('description', 'home');
		$keywords = getMetaTag('keywords', 'home');
		
		// Meta Tags
		MetaTag::set('title', $title);
		MetaTag::set('description', strip_tags($description));
		MetaTag::set('keywords', $keywords);
		
		// Open Graph
		$this->og->title($title)->description($description);
		view()->share('og', $this->og);
	}
	
	/**
	 * @param int $limit
	 * @param string $type (latest, sponsored or jobs)
	 * @param int $cacheExpiration
	 * @return mixed
	 */
	private function getPosts($limit = 20, $type = 'latest', $cacheExpiration = 0)
	{
		// var_dump($type);
		// Select fields
		$select = [
			'tPost.id',
			'tPost.country_code',
			'tPost.category_id',
			'tPost.post_type_id',
			'tPost.title',
			'tPost.price',
			'tPost.city_id',
			'tPost.featured',
			'tPost.created_at',
			'tPost.reviewed',
			'tPost.verified_email',
			'tPost.verified_phone',
			'tPayment.package_id',
			'tPackage.lft'
		];
		
		// GroupBy fields
		$groupBy = [
			'tPost.id'
		];
		
		// If the MySQL strict mode is activated, ...
		// Append all the non-calculated fields available in the 'SELECT' in 'GROUP BY' to prevent error related to 'only_full_group_by'
		if (env('DB_MODE_STRICT')) {
			$groupBy = $select;
		}
		
		$paymentJoin = '';
		$sponsoredCondition = '';
		$sponsoredOrder = '';
		if ($type == 'sponsored') {
			$paymentJoin .= 'INNER JOIN ' . DBTool::table('payments') . ' AS tPayment ON tPayment.post_id=tPost.id AND tPayment.active=1' . "\n";
			$paymentJoin .= 'INNER JOIN ' . DBTool::table('packages') . ' AS tPackage ON tPackage.id=tPayment.package_id' . "\n";
			$sponsoredCondition = ' AND tPost.featured = 1';
			$sponsoredOrder = 'tPackage.lft DESC, ';
		} else {
			// $paymentJoin .= 'LEFT JOIN ' . DBTool::table('payments') . ' AS tPayment ON tPayment.post_id=tPost.id AND tPayment.active=1' . "\n";
			$latestPayment = "(SELECT MAX(id) lid, post_id FROM " . DBTool::table('payments') . " WHERE active=1 GROUP BY post_id) latestPayment";
			$paymentJoin .= 'LEFT JOIN ' . $latestPayment . ' ON latestPayment.post_id=tPost.id AND tPost.featured=1' . "\n";
			$paymentJoin .= 'LEFT JOIN ' . DBTool::table('payments') . ' AS tPayment ON tPayment.id=latestPayment.lid' . "\n";
			$paymentJoin .= 'LEFT JOIN ' . DBTool::table('packages') . ' AS tPackage ON tPackage.id=tPayment.package_id' . "\n";
		}
		$reviewedCondition = '';
		if (config('settings.single.posts_review_activation')) {
//			$reviewedCondition = ' AND tPost.reviewed = 1';
                        $reviewedCondition = ' AND tPost.reviewed > 0';
		}
		
		// R.S
		$notInBlackList = " AND  tPost.phone NOT IN (select entry from blacklist) ";

		if ($type == 'jobs') {
			$sql = 'SELECT DISTINCT ' . implode(',', $select) . '
                FROM ' . DBTool::table('posts') . ' AS tPost
                INNER JOIN ' . DBTool::table('categories') . ' AS tCategory ON tCategory.id=tPost.category_id AND tCategory.active=1
                ' . $paymentJoin . '
                WHERE tPost.country_code = :countryCode
					AND (tPost.verified_email=1 AND tPost.verified_phone=1)
					AND tPost.category_id=799
					AND tPost.archived!=1 ' . $reviewedCondition . $sponsoredCondition . $notInBlackList .'
                GROUP BY ' . implode(',', $groupBy) . '
                ORDER BY ' . $sponsoredOrder . 'tPost.created_at DESC
                LIMIT 0,' . (int)$limit;
		} else {
			$sql = 'SELECT DISTINCT ' . implode(',', $select) . '
                FROM ' . DBTool::table('posts') . ' AS tPost
                INNER JOIN ' . DBTool::table('categories') . ' AS tCategory ON tCategory.id=tPost.category_id AND tCategory.active=1
                ' . $paymentJoin . '
                WHERE tPost.country_code = :countryCode
					AND (tPost.verified_email=1 AND tPost.verified_phone=1)
					AND tPost.category_id !=799
					AND tPost.archived!=1 ' . $reviewedCondition . $sponsoredCondition . $notInBlackList .'
                GROUP BY ' . implode(',', $groupBy) . '
                ORDER BY ' . $sponsoredOrder . 'tPost.created_at DESC
                LIMIT 0,' . (int)$limit;
		}
		
		$bindings = [
			'countryCode' => config('country.code'),
		];
		
		$cacheId = config('country.code') . '.home.getPosts.' . $type;
		$posts = Cache::remember($cacheId, $cacheExpiration, function () use ($sql, $bindings) {
			$posts = DB::select(DB::raw($sql), $bindings);
			
			return $posts;
		});
		
		// Transform the collection attributes
		$posts = collect($posts)->map(function ($post) {
			$post->title = mb_ucfirst($post->title);
			
			return $post;
		})->toArray();
		
		return $posts;
	}
	
	/**
	 * @param array $value
	 * @return int
	 */
	private function getCacheExpirationTime($value = [])
	{
		// Get the default Cache Expiration Time
		$cacheExpiration = 0;
		if (isset($value['cache_expiration'])) {
			$cacheExpiration = (int)$value['cache_expiration'];
		}
		
		return $cacheExpiration;
	}
}