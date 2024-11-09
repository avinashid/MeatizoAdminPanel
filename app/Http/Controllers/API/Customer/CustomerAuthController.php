<?php

// namespace App\Http\Controllers\Api\Customer;
namespace App\Http\Controllers\API\Customer;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Cart;
use App\Models\Setting;
use App\Models\UserToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Kreait\Firebase\Factory;
use Response;


class CustomerAuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'type' => 'required',
            'id' => 'required'
        ]);
        
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        if(request()->type == 'phone'){
            $user = User::select('id','name','email','country_code','mobile','profile','balance','referral_code','status','type')
                ->where('type', request()->type)->where('mobile', request()->id)->first();
        }
        elseif(request()->type == 'google' || request()->type == 'apple'){
            $user = User::select('id','name','email','country_code','mobile','profile','balance','referral_code','status','type')
                ->where('type', request()->type)->where('email', request()->id)->first();
        }

        if ($user) {
            // Mobile number exists
            Auth::login($user);
            $accessToken = $user->createToken('authToken')->accessToken;
            $user->referral_code = $user->referral_code??"";
            $user->status = intval($user->status) ?? 0;
            $res = ['user' => $user, 'access_token' => $accessToken];

            if(isset($request->fcm_token)) {
                $token = UserToken::where("fcm_token", $request->fcm_token)->first();
                if ($token) {
                    $token->user_id = auth()->user()->id;
                    $token->platform = $request->platform;
                    $token->save();
                } elseif (UserToken::where('user_id', auth()->user()->id)->where('platform', $request->platform)->exists()) {
                    // Find the existing token and update it
                    $existingToken = UserToken::where('user_id', auth()->user()->id)->where('platform', $request->platform)->first();
                    $existingToken->fcm_token = $request->fcm_token;
                    $existingToken->save();
                } else {
                    UserToken::firstOrCreate([
                        'user_id' => auth()->user()->id,
                        'type' => 'customer',
                        'fcm_token' => $request->fcm_token,
                        'platform' => $request->platform
                    ]);
                }
            }

            return CommonHelper::responseWithData($res);
        } else {
            // Mobile number does not exist
            return CommonHelper::responseError(__('user_does_not_exist'));
        }
    }
    public function register(Request $request)
    {
        $requestData = $request->all();
        $validator = Validator::make($requestData, [
            'type'   => 'required|in:phone,apple,google,email',
            'mobile' => 'required_if:type,phone|numeric',
            'email'  => 'required_if:type,apple,google,email|email',
        ]);
        
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

       

        try{

            if(request()->type == 'phone'){
                $user = User::select('id','name','email','country_code','mobile','profile','balance','referral_code','status','type')
                    ->where('type', request()->type)->where('mobile', request()->id)->first();
            }
            elseif(request()->type == 'google' || request()->type == 'apple'){
                $user = User::select('id','name','email','country_code','mobile','profile','balance','referral_code','status','type')
                    ->where('type', request()->type)->where('email', request()->id)->first();
            }

            if($user) {
                // if user exist and auth id is different
                if($user->auth_uid != request()->auth_uid){
                    return CommonHelper::responseError(__('user_is_unauthorised_kindly_contact_admin'));
                }

                if($user->status == User::$deactive){
                    return CommonHelper::responseError(__('this_customer_account_is_deactivated_kindly_contact_admin'));
                }

                //stripe
                //$stripe = Setting::get_value('stripe_payment_method');
                $stripe = CommonHelper::getSettings(['stripe_payment_method','stripe_publishable_key', 'stripe_secret_key']);
                if($stripe) {
                    try {
                        $user->createOrGetStripeCustomer();
                    }catch (\Exception $e){

                    }
                }

            }else{

                $referral_code = strtoupper(substr(sha1(microtime()), 0, 6));

                $user = new User();
                $user->name = $request->get('name');
                $user->email = $request->get('email');
                $user->profile = $request->get('profile','');
                $user->referral_code = $referral_code;
                $user->status = 1;
                $user->country_code = request()->country_code ?? '';
                $user->mobile =$request->get('mobile');
                $user->password = bcrypt(time());
                $user->type = $request->type;
                $user->save();
            }

            Auth::login($user);
            $accessToken = $user->createToken('authToken')->accessToken;
            $user->referral_code = $user->referral_code??"";
            $user->status = intval($user->status) ?? 0;
            $res = ['user' => $user, 'access_token' => $accessToken];

            if(isset($request->fcm_token)) {
                $token = UserToken::where("fcm_token", $request->fcm_token)->first();
                if($token){
                    $token->user_id = auth()->user()->id;
                    $token->platform = $request->platform;
                    $token->save();
                }else{
                    UserToken::firstOrCreate([
                        'user_id' => auth()->user()->id,
                        'type' => 'customer',
                        'fcm_token' => $request->fcm_token,
                        'platform' => $request->platform
                    ]);
                }
            }

            return CommonHelper::responseWithData($res);

            }catch ( \Exception $e){

                Log::error('Login : '.$e->getMessage());
                return CommonHelper::responseError($e->getMessage());
            }
    }

    public function logout (Request $request)
    {
        if(isset($request->fcm_token)){
            $userToken = UserToken::where('type','customer')
                ->where('user_id',$request->user()->id)
                ->where('fcm_token',$request->fcm_token)->first();
            if($userToken){
                $userToken->delete();
            }
        }

        $token = $request->user()->token();
        $token->revoke();

        return CommonHelper::responseSuccess(__('you_have_been_successfully_logged_out'));
    }

    public function notLogin(){
        return CommonHelper::responseError(__('unauthorized'));
    }

    public function deleteAccount(Request $request){       
        try{
            $user_id = auth()->user()->id;
            $user = User::where('id', $user_id)->first();

            if($user->mobile == '9876543210'){
               return CommonHelper::responseError("This function is not available in demo mode!");
            }

            $user->delete();
            return CommonHelper::responseSuccess("Your account deleted successfully!");
        }catch ( \Exception $e){
            Log::error('Login : '.$e->getMessage());
            return CommonHelper::responseError($e->getMessage());
        }
    }

    public function editProfile(Request $request){

        $user = auth()->user();
        $validator = Validator::make($request->all(),[
            'name' => 'required',
            //'email' => 'required|unique:users,email,'.$user->id,
            'email' => 'required|unique:users,email,'.$user->id.',id,deleted_at,NULL',
        ],[
            'email.unique' => 'The :attribute has already been taken.',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        //dd($request->all());

        $user->name = $request->name;
        $user->email = $request->email;

        if(isset($request->mobile) && $user->type != 'phone') {
            $user->mobile = $request->mobile;
        }

        if($request->hasFile('profile')){
            $file = $request->file('profile');

            $fileName = time().'_'.$user->id.'.'.$file->getClientOriginalExtension();

            $image = Storage::disk('public')->putFileAs('customers', $file, $fileName);
            $user->profile = $image;
        }

        if($user->status == 2){
            if(isset($request->referral_code)) {
                $validCode = User::where('status', 1)
                    ->where('referral_code', $request->referral_code)->first();
                if ($validCode) {
                    $user->friends_code = $request->referral_code;
                }
            }
            $user->status = 1;
            CommonHelper::setDefaultMailSetting($user->id, 0);
        }

        $user->save();

        return  CommonHelper::responseSuccess(__('profile_updated_successfully'));
    }

    public function changePassword(Request $request){

        $validator = Validator::make($request->all(),[
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $user = auth()->user();
        $user->password = bcrypt($request->password);
        $user->save();

        return  CommonHelper::responseSuccess(__('password_updated_successfully'));
    }

    public function uploadProfile(Request $request){

        $validator = Validator::make($request->all(),[
            'profile' => 'required',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $user = auth()->user();
        if($request->hasFile('profile')){
            $file = $request->file('profile');
            $image = Storage::disk('public')
                ->putFileAs('customers', $file, $user->id.".jpg");
            $user->profile = $image;
            $user->save();
        }
        return  CommonHelper::responseSuccess(__('profile_updated_successfully'));
    }

    public function addFcmToken(Request $request){ 
        $validator = Validator::make($request->all(),[
            'fcm_token' => 'required',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        $user_id = $request->user('api-customers') ? $request->user('api-customers')->id : '';

        $token = UserToken::where("fcm_token", $request->fcm_token)->first();

        if(isset($user_id) && $user_id != "" && !empty($token) && ($token->user_id == 0 || $token->user_id == "")){
            $token->user_id = $user_id;
            $token->platform = $request->platform;
            $token->save();
            return CommonHelper::responseSuccess(__('token_updated_successfully'));
        }else{
            UserToken::firstOrCreate([
                'user_id' => 0,
                'type' => 'customer',
                'fcm_token' => $request->fcm_token,
                'platform' => $request->platform
            ]);
            return CommonHelper::responseSuccess(__('token_added_successfully'));
        }
    }

    public function updateFcmToken(Request $request){
        $validator = Validator::make($request->all(),[
            'fcm_token' => 'required',
        ]);

        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $user_id = $request->user('api-customers') ? $request->user('api-customers')->id : '';

        $token = UserToken::where("fcm_token", $request->fcm_token)->first();

        if(isset($user_id) && $user_id != "" && !empty($token) && ($token->user_id == 0 || $token->user_id == "")){
            $token->user_id = $user_id;
            $token->platform = $request->platform;
            $token->save();
            return CommonHelper::responseSuccess(__('token_updated_successfully'));
        }else{
            UserToken::firstOrCreate([
                'user_id' => 0,
                'type' => 'customer',
                'fcm_token' => $request->fcm_token,
                'platform' => $request->platform
            ]);
            return CommonHelper::responseSuccess(__('token_added_successfully'));
        }
    }

    public function getLoginUserDetails(Request $request){
        $user_id = $request->user('api-customers') ? $request->user('api-customers')->id : '';
        $total = Cart::select(DB::raw('COUNT(carts.id) AS total'))->Join('products', 'carts.product_id', '=', 'products.id')->where('carts.save_for_later','=',0)->where('user_id','=',$user_id)->first();
        $total = $total->makeHidden(['image_url']);
        $user = User::select('id','name','email','country_code','mobile','profile','balance','referral_code','status')->where('id', $user_id)->first();
        if(!empty($user)){
            return Response::json(array('status' => 1, 'message' => 'success','total'=> 1, 'cart_items_count' => $total->total, 'user' => $user));
        }else{
            return CommonHelper::responseError(__('unauthorized'));
        }
    }

    // public function verifyUser(Request $request){
    //     $validator = Validator::make($request->all(),[
    //         'mobile' => 'required',
    //     ]);

    //     if ($validator->fails()) {
    //         return CommonHelper::responseError($validator->errors()->first());
    //     }

    //     $exists = User::where('mobile', $request->mobile)->exists();

    //     if ($exists) {
    //         // Mobile number exists
    //         return CommonHelper::responseSuccess(__('is_user_already_exist'));
    //     } else {
    //         // Mobile number does not exist
    //         return CommonHelper::responseError(__('mobile_number_does_not_exist'));
    //     }
    // }
}
