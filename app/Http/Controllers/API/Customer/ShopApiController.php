<?php

namespace App\Http\Controllers\API\Customer;

use App\Helpers\CommonHelper;
use App\Helpers\ProductHelper;
use App\Http\Controllers\Controller;
use App\Http\Repository\CategoryRepository;
use App\Http\Repository\ProductRepository;
use App\Models\Cart;
use App\Models\Category;
use App\Models\City;
use App\Models\Favorite;
use App\Models\Offer;
use App\Models\Pincode;
use App\Models\Product;
use App\Models\Section;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Slider;
use App\Models\Brand;
use App\Models\Country;
use Illuminate\Http\Request;
use DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopApiController extends Controller
{

    public $categoryRepository;

    public function __construct(CategoryRepository $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    public function getShopData(Request $request)
    {

        /*$request->city_id = 1;
        $request->latitude = 23.2419997;
        $request->longitude = 69.6669324;*/

        $validator = Validator::make($request->all(), [
            'latitude' => 'required',
            'longitude' => 'required',
        ], [
            'latitude.required' => 'The latitude field is required.',
            'longitude.required' => 'The longitude field is required.'
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }


        //$city = City::select('id','latitude','longitude')->where('id',$request->city_id)->first();

        /*$sellers = Seller::select('id')->where('city_id', $request->city_id)->get();
        $sellers = $sellers->makeHidden(['logo_url']);
        $seller_ids = array_values(array_column($sellers->toarray(), 'id'));*/

        $seller_ids = CommonHelper::getSellerIds($request->latitude, $request->longitude);

        $user_id = $request->user('api-customers') ? $request->user('api-customers')->id : 0;
        $sections = CommonHelper::getSectionWithProduct($seller_ids, $user_id);
       
        

        $sliders = Slider::where('status', 1)->orderBy('id', 'DESC')->get();
        $sliders = $sliders->makeHidden(['image', 'product', 'category', 'created_at', 'updated_at', 'status']);

        /* $slider =  array_map("array_filter",$slider->toArray());
        $slider = array_filter($slider); */

        foreach ($sliders as $key => $slider) {
            $sliders[$key]->slider_url = $sliders[$key]->slider_url ?? "";
            $sliders[$key]->type_id = $sliders[$key]->type_id ? intval($sliders[$key]->type_id) : 0;
        }

        $offers = Offer::orderBy('id', 'DESC')->get();
        $offers = $offers->makeHidden(['image']);
        $is_category_section_in_homepage = CommonHelper::getIsCategorySectionInHomepage();
        $is_brand_section_in_homepage = CommonHelper::getIsBrandSectionInHomepage();
        $is_seller_section_in_homepage = CommonHelper::getIsSellerSectionInHomepage()['is_seller_section_in_homepage'];
        $is_country_section_in_homepage = CommonHelper::getIsCountrySectionInHomepage()['is_country_section_in_homepage'];
        $output = array(
            'sliders' => $sliders,
            'offers' => $offers,
            'sections' => $sections,
            'is_category_section_in_homepage' => $is_category_section_in_homepage,
            'is_brand_section_in_homepage' => $is_brand_section_in_homepage,
            'is_seller_section_in_homepage' => $is_seller_section_in_homepage,
            'is_country_section_in_homepage' => $is_country_section_in_homepage,
            /*'total' => ($total != "") ? $total : 0,
            'min_price' => $min_price ?? 0,
            'max_price' => $max_price ?? 0,
            'total_min_price' => $total_min_price  ?? 0,
            'total_max_price' => $total_max_price  ?? 0,
            'products' => $product*/
        );
        
        if($is_category_section_in_homepage && $is_category_section_in_homepage==1){
            $count_category_section_in_homepage = CommonHelper::getCountCategorySectionInHomepage();
            $categories = Category::where('status', 1)
            ->where('parent_id', 0)
            ->where('status', 1)
            ->orderBy('row_order', 'ASC')
            ->limit($count_category_section_in_homepage)
            ->get(['id', 'name', 'subtitle', 'image', 'slug']);
        $categories = $categories->makeHidden(['image']);
        $output['categories'] = $categories->toArray();
        }

        
        if($is_brand_section_in_homepage && $is_brand_section_in_homepage==1){
            $count_brand_section_in_homepage = CommonHelper::getCountBrandSectionInHomepage();
            $brands = Brand::orderBy('id','ASC')->where('status',1)->whereExists(function($query) {
                $query->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.brand_id', 'brands.id');
            });
            $brands = $brands->limit($count_brand_section_in_homepage)->get();
            $brands = $brands->makeHidden(['created_at','updated_at','image','status']);
            $output['brands'] = $brands->toArray();
        }

        if($is_seller_section_in_homepage && $is_seller_section_in_homepage==1){
            $count_seller_section_in_homepage = CommonHelper::getIsSellerSectionInHomepage()['count_seller_section_in_homepage'];
            $sellers = Seller::select('sellers.id', 'sellers.name', 'sellers.store_name', 'sellers.logo', DB::raw("ROUND(6371 * acos(cos(radians(" . $request->latitude . "))
                                * cos(radians(sellers.latitude)) * cos(radians(sellers.longitude) - radians(" . $request->longitude . "))
                                + sin(radians(" .$request->latitude. ")) * sin(radians(sellers.latitude))), 2) AS distance"), 'cities.max_deliverable_distance')
            ->leftJoin("cities", "sellers.city_id", "cities.id")
            ->where('status', Seller::$statusActive)
            ->whereExists(function($query) {
                $query->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.seller_id', 'sellers.id');
            })
            ->orderBy('distance','asc')
            ->limit($count_seller_section_in_homepage) 
            ->get();

            $sellers = $sellers->makeHidden(['national_identity_card_url','address_proof_url','logo']);
            $output['sellers'] = $sellers->toArray();
        }
        if($is_country_section_in_homepage && $is_country_section_in_homepage==1){
            $count_country_section_in_homepage = CommonHelper::getIsCountrySectionInHomepage()['count_country_section_in_homepage'];
            $countries = Country::orderBy('id','ASC')->where('status',1)->whereExists(function($query) {
                $query->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.made_in', 'countries.id');
            });
            $countries = $countries->limit($count_country_section_in_homepage)->get();
            $countries = $countries->makeHidden(['created_at','updated_at','status']);
            $output['countries'] = $countries->toArray();
        }
       
        /*  if (!empty($sections)) {

         } else {
             $output = array(
                 'min_price' => $min_price ?? 0,
                 'max_price' => $max_price ?? 0,
                 'total_min_price' => $total_min_price  ?? 0,
                 'total_max_price' => $total_max_price  ?? 0,
                 'category' => $categories,
                 'slider' => $slider,
                 'offers' => $offers,
                 'products' => array(),
             );
         } */
        return CommonHelper::responseWithData($output);
    }
}
