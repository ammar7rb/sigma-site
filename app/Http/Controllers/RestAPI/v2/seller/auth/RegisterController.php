<?php

namespace App\Http\Controllers\RestAPI\v2\seller\auth;
use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\Shop;
use App\Services\EgyptPhoneService;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{

    public function store(Request $request)
    {
        $request->merge([
            'phone' => app(EgyptPhoneService::class)->normalize($request->input('phone')),
        ]);

        $validator = Validator::make($request->all(), [
            'email'         => 'required|unique:sellers',
            'shop_address'  => 'required',
            'f_name'        => 'required',
            'l_name'        => 'required',
            'shop_name'     => 'required',
            'phone'         => ['required', 'regex:/^\+201[0125]\d{8}$/', 'unique:sellers,phone'],
            'password'      => 'required|min:8',
            'image'         => 'nullable|mimes:jpg,jpeg,png,gif',
            'logo'          => 'nullable|mimes:jpg,jpeg,png,gif',
            'banner'        => 'nullable|mimes:jpg,jpeg,png,gif',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        DB::beginTransaction();
        try {
            $seller = new Seller();
            $seller->f_name = $request->f_name;
            $seller->l_name = $request->l_name;
            $seller->phone = $request->phone;
            $seller->email = $request->email;
            $seller->image = $request->hasFile('image')
                ? ImageManager::upload('seller/', 'webp', $request->file('image'))
                : 'def.png';
            $seller->password = bcrypt($request->password);
            $seller->status =  $request->status == 'approved'?'approved': "pending";
            $seller->save();

            $shop = new Shop();
            $shop->seller_id = $seller->id;
            $shop->name = $request->shop_name;
            $shop->address = $request->shop_address;
            $shop->contact = $request->phone;
            $shop->image = $request->hasFile('logo')
                ? ImageManager::upload('shop/', 'webp', $request->file('logo'))
                : 'def.png';
            $shop->banner = $request->hasFile('banner')
                ? ImageManager::upload('shop/banner/', 'webp', $request->file('banner'))
                : 'def.png';
            $shop->save();

            DB::table('seller_wallets')->insert([
                'seller_id' => $seller['id'],
                'withdrawn' => 0,
                'commission_given' => 0,
                'total_earning' => 0,
                'pending_withdraw' => 0,
                'delivery_charge_earned' => 0,
                'collected_cash' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['message' => translate('Shop apply fail!')], 403);
        }

    }
}
