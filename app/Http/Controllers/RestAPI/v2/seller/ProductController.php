<?php

namespace App\Http\Controllers\RestAPI\v2\seller;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Color;
use App\Models\DealOfTheDay;
use App\Models\FlashDealProduct;
use App\Models\Product;
use App\Models\Translation;
use App\Services\SellerProductEntitlementService;
use App\Services\SellerProductContentPolicyService;
use App\Utils\Convert;
use App\Utils\Helpers;
use App\Utils\ImageManager;
use Barryvdh\DomPDF\PDF;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        private readonly SellerProductEntitlementService $sellerProductEntitlementService,
    ) {}

    public function list(Request $request):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        return response()->json(Product::where(['added_by' => 'seller', 'id' => $seller['id']])->orderBy('id', 'DESC')->get(), 200);
    }

    public function stock_out_list(Request $request):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        $stockLimit = $seller['stock_limit'] <= 0 ? getWebConfig(name: 'stock_limit') : $seller['stock_limit'];
        $products = Product::where(['added_by' => 'seller', 'user_id' => $seller->id, 'product_type'=>'physical', 'request_status'=>1])
                            ->where('current_stock', '<', $stockLimit)
                            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $products->map(function ($data) {
            $data = Helpers::product_data_formatting($data);
            return $data;
        });

        return response()->json([
            'total_size' => $products->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'products' => $products->items()
        ], 200);
    }

    public function upload_images(Request $request):JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required',
            'type' => 'required|in:product,thumbnail,meta',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        $path = $request['type'] == 'product' ? '' : $request['type'] . '/';
        $image = ImageManager::upload('product/' . $path, 'webp', $request->file('image'));

        return response()->json(['image_name' => $image, 'type' => $request['type']], 200);
    }

    // Digital product file upload
    public function upload_digital_product(Request $request):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        try {
            $validator = Validator::make($request->all(), [
                'digital_file_ready' => 'required|mimes:jpg,jpeg,png,gif,zip,pdf',
            ]);

            if ($validator->errors()->count() > 0) {
                return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
            }

            $file = ImageManager::file_upload('product/digital-product/', $request->digital_file_ready->getClientOriginalExtension(), $request->file('digital_file_ready'));

            return response()->json(['digital_file_ready_name' => $file], 200);
        } catch (\Exception $e) {
            return response()->json(['errors' => $e], 403);
        }
    }

    public function add_new(Request $request):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);
        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        if ($policyResponse = $this->guardProductPolicy($request, $seller)) {
            return $policyResponse;
        }

        $validator = Validator::make($request->all(), [
            'name'                  => 'required',
            'category_id'           => 'required',
            'product_type'          => 'required',
            'digital_product_type'  => 'required_if:product_type,==,digital',
            'digital_file_ready'    => 'required_if:digital_product_type,==,ready_product',
            'unit'                  => 'required_if:product_type,==,physical',
            'images'                => 'required',
            'thumbnail'             => 'required',
            'discount_type'         => 'required|in:percent,flat',
            'tax'                   => 'required|min:0',
            'lang'                  => 'required',
            'unit_price'            => 'required|min:1',
            'purchase_price'        => 'required|min:1',
            'discount'              => 'required|gt:-1',
            'shipping_cost'         => 'required_if:product_type,==,physical|gt:-1',
            'code'                  => 'required|unique:products',
            'minimum_order_qty'     => 'required|numeric|min:1',
            'sale_unit_type'        => 'nullable|in:piece,package,box',
            'pieces_per_unit'       => 'nullable|integer|min:1|required_if:sale_unit_type,package,box',
            'length'                => 'nullable|numeric|gt:0',
            'width'                 => 'nullable|numeric|gt:0',
            'height'                => 'nullable|numeric|gt:0',
            'dimension_unit'        => 'nullable|in:cm,m',
            'weight'                => 'nullable|numeric|gt:0',
            'weight_unit'           => 'nullable|in:g,kg',
            'production_date'       => 'required|date',
            'expiry_date'           => 'required|date|after_or_equal:production_date|after:today',
        ], [
            'name.required'                     => translate('Product name is required!'),
            'unit.required_if'                  => translate('Unit is required!'),
            'category_id.required'              => translate('category is required!'),
            'digital_file_ready.required_if'    => translate('Ready product upload is required!'),
            'digital_product_type.required_if'  => translate('Digital product type is required!'),
            'shipping_cost.required_if'         => translate('Shipping Cost is required!'),
            'images.required'                   => translate('Product images is required!'),
            'image.required'                    => translate('Product thumbnail is required!'),
            'code.required'                     => translate('Code is required!'),
            'minimum_order_qty.required'        => translate('The minimum order quantity is required!'),
            'minimum_order_qty.min'             => translate('The minimum order quantity must be positive!'),
        ]);

        $submittedImages = is_string($request->images)
            ? (json_decode($request->images, true) ?: [])
            : ($request->images ?: []);
        if (count(array_filter((array) $submittedImages)) < 5) {
            $validator->after(fn ($validator) => $validator->errors()->add(
                'images',
                translate('at_least_five_product_images_are_required')
            ));
        }

        $brand_setting = BusinessSetting::where('type', 'product_brand')->value('value') ?? 0;
        if ($brand_setting && empty($request->brand_id)) {
            $validator->after(function ($validator) {
                $validator->errors()->add(
                    'brand_id', 'Brand is required!'
                );
            });
        }

        if ($request['discount_type'] == 'percent') {
            $dis = ($request['unit_price'] / 100) * $request['discount'];
        } else {
            $dis = $request['discount'];
        }

        if ($request['unit_price'] <= $dis) {
            $validator->after(function ($validator) {
                $validator->errors()->add(
                    'unit_price',
                    translate('Discount can not be more or equal to the price!')
                );
            });
        }

        $product = new Product();
        $product->user_id = $seller->id;
        $product->added_by = "seller";

        // Older clients may omit `lang` when the default language is used.
        // Resolve the name defensively so policy validation can return its
        // intended response instead of failing with array_search(null).
        $productName = $this->resolveLocalizedField($request, 'name');
        $product->name = $productName;
        $product->slug = Str::slug($productName, '-') . '-' . Str::random(6);

        $category = [];

        if ($request->category_id != null) {
            array_push($category, [
                'id' => $request->category_id,
                'position' => 1,
            ]);
        }
        if ($request->sub_category_id != null) {
            array_push($category, [
                'id' => $request->sub_category_id,
                'position' => 2,
            ]);
        }
        if ($request->sub_sub_category_id != null) {
            array_push($category, [
                'id' => $request->sub_sub_category_id,
                'position' => 3,
            ]);
        }

        $product->category_ids          = json_encode($category);
        $product->brand_id              = isset($request->brand_id) ? $request->brand_id : null;
        $product->unit                  = $request->product_type == 'physical' ? $request->unit : null;
        $product->sale_unit_type        = in_array($request->input('sale_unit_type'), ['package', 'box'], true) ? 'package' : 'piece';
        $product->pieces_per_unit       = in_array($request->input('sale_unit_type'), ['package', 'box'], true) ? (int) $request->pieces_per_unit : null;
        $product->length                = $request->filled('length') ? (float) $request->length : null;
        $product->width                 = $request->filled('width') ? (float) $request->width : null;
        $product->height                = $request->filled('height') ? (float) $request->height : null;
        $product->dimension_unit        = $request->filled('dimension_unit') ? $request->dimension_unit : null;
        $product->weight                = $request->filled('weight') ? (float) $request->weight : null;
        $product->weight_unit           = $request->filled('weight_unit') ? $request->weight_unit : null;
        $product->production_date       = $request->production_date;
        $product->expiry_date           = $request->expiry_date;
        $product->product_type          = $request->product_type;
        $product->digital_product_type  = $request->product_type == 'digital' ? $request->digital_product_type : null;
        $product->code                  = $request->code;
        $product->minimum_order_qty     = $request->minimum_order_qty;
        $product->details               = $this->resolveLocalizedField($request, 'description');

        $product->images                = json_encode($request->images);
        $product->thumbnail             = $request->thumbnail;
        $product->digital_file_ready    = $request->digital_file_ready;

        if ($request->has('colors_active') && $request->has('colors') && count($request->colors) > 0) {
            $product->colors = $request->product_type == 'physical' ? json_encode($request->colors) : json_encode([]);
        } else {
            $colors = [];
            $product->colors = $request->product_type == 'physical' ? json_encode($colors) : json_encode([]);
        }

        $choice_options = [];
        if ($request->has('choice')) {
            foreach ($request->choice_no as $key => $no) {
                $str = 'choice_options_' . $no;
                $item['name'] = 'choice_' . $no;
                $item['title'] = $request->choice[$key];
                $item['options'] = $request[$str];
                array_push($choice_options, $item);
            }
        }
        $product->choice_options = $request->product_type == 'physical' ? json_encode($choice_options) : json_encode([]);

        //combinations start
        $options = [];
        if ($request->has('colors_active') && $request->has('colors') && count($request->colors) > 0) {
            $colors_active = 1;
            array_push($options, $request->colors);
        }
        if ($request->has('choice_no')) {
            foreach ($request->choice_no as $key => $no) {
                $name = 'choice_options_' . $no;
                array_push($options, $request[$name]);
            }
        }
        //Generates the combinations of customer choice options
        $combinations = Helpers::combinations($options);
        $variations = [];
        $stock_count = 0;
        if (count($combinations[0]) > 0) {

            foreach ($combinations as $combination) {
                $str = '';
                foreach ($combination as $k => $item) {
                    if ($k > 0) {
                        $str .= '-' . str_replace(' ', '', $item);
                    } else {
                        if ($request->has('colors_active') && $request->has('colors') && count($request->colors) > 0) {
                            $color_name = Color::where('code', $item)->first()->name ?? '';
                            $str .= $color_name;
                        } else {
                            $str .= str_replace(' ', '', $item);
                        }
                    }
                }
                $item = [];
                $item['type'] = $str;
                $item['price'] = Convert::usd(abs($request['price_' . str_replace('.', '_', $str)]));
                $item['sku'] = $request['sku_' . str_replace('.', '_', $str)];
                $item['qty'] = $request['qty_' . str_replace('.', '_', $str)];

                array_push($variations, $item);
                $stock_count += $item['qty'];
            }
        } else {
            $stock_count = (int)$request['current_stock'];
        }

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        //combinations end
        $product->variation      = $request->product_type == 'physical' ? json_encode($variations) : json_encode([]);
        $product->unit_price = Convert::usd($request->unit_price);
        $product->purchase_price = Convert::usd($request->purchase_price);
        $product->tax = $request->tax;
        $product->tax_type = $request->tax_type;
        $product->discount = $request->discount_type == 'flat' ? Convert::usd($request->discount) : $request->discount;
        $product->discount_type = $request->discount_type;
        $product->attributes     = $request->product_type == 'physical' ? json_encode($request->choice_attributes) : json_encode([]);
        $product->current_stock  = $request->product_type == 'physical' ? abs($stock_count) : 0;

        $product->meta_title = $request->meta_title;
        $product->meta_description = $request->meta_description;
        $product->meta_image = $request->meta_image;

        $product->video_provider = 'youtube';
        $product->video_url = $request->video_link;
        // A completed seller product is active in the seller catalogue but is
        // not public until an administrator approves the submission.
        $product->request_status = 0;
        $product->status = 0;
        $product->seller_review_status = \App\Services\SellerProductReviewService::SUBMITTED;
        $product->seller_submitted_at = now();
        $product->seller_reviewed_at = null;
        $product->seller_reviewed_by = null;
        $product->shipping_cost  = $request->product_type == 'physical' ? Convert::usd($request->shipping_cost) : 0;
        $product->multiply_qty = ($request->product_type == 'physical') ? ($request->multiplyQTY == 1 ? 1 : 0) : 0;
        $product->save();
        app(\App\Services\SellerProductReviewService::class)->submit($product);
        $data = [];
        foreach ($request->lang as $index => $key) {
            if ($request->name[$index] && $key != Helpers::default_lang()) {
                array_push($data, array(
                    'translationable_type' => 'App\Models\Product',
                    'translationable_id' => $product->id,
                    'locale' => $key,
                    'key' => 'name',
                    'value' => $request->name[$index],
                ));
            }
            if ($request->description[$index] && $key != Helpers::default_lang()) {
                array_push($data, array(
                    'translationable_type' => 'App\Models\Product',
                    'translationable_id' => $product->id,
                    'locale' => $key,
                    'key' => 'description',
                    'value' => $request->description[$index],
                ));
            }
        }
        Translation::insert($data);

        return response()->json([
            'message' => translate('successfully product added!'),
            'review_status' => \App\Services\SellerProductReviewService::SUBMITTED,
            'seller_display_status' => 'active',
            'request_status' => 0,
        ], 200);
    }

    public function edit(Request $request, $id):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);
        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        $product = Product::withoutGlobalScopes()->with('translations')->find($id);
        $product = Helpers::product_data_formatting($product);

        return response()->json($product, 200);
    }

    public function update(Request $request, $id):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);
        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }


        if ($policyResponse = $this->guardProductPolicy($request, $seller, (int) $id)) {
            return $policyResponse;
        }

        $product = Product::where(['added_by' => 'seller', 'user_id' => $seller->id])->find($id);
        if (! $product) {
            return response()->json(['message' => translate('seller_product_not_found')], 404);
        }
        $wasRejected = (int) $product->request_status === 2;

        $validator = Validator::make($request->all(), [
            'name'                  => 'required',
            'category_id'           => 'required',
            'product_type'          => 'required',
            'digital_product_type'  => 'required_if:product_type,==,digital',
            'unit'                  => 'required_if:product_type,==,physical',
            'discount_type'         => 'required|in:percent,flat',
            'tax'                   => 'required|min:0',
            'lang'                  => 'required',
            'unit_price'            => 'required|min:1',
            'purchase_price'        => 'required|min:1',
            'discount'              => 'required|gt:-1',
            'shipping_cost'         => 'required_if:product_type,==,physical|gt:-1',
            'minimum_order_qty'     => 'required|numeric|min:1',
            'sale_unit_type'        => 'nullable|in:piece,package,box',
            'pieces_per_unit'       => 'nullable|integer|min:1|required_if:sale_unit_type,package,box',
            'length'                => 'nullable|numeric|gt:0',
            'width'                 => 'nullable|numeric|gt:0',
            'height'                => 'nullable|numeric|gt:0',
            'dimension_unit'        => 'nullable|in:cm,m',
            'weight'                => 'nullable|numeric|gt:0',
            'weight_unit'           => 'nullable|in:g,kg',
            'production_date'       => 'required|date',
            'expiry_date'           => 'required|date|after_or_equal:production_date|after:today',
            'code'                  => 'required|numeric|min:1|digits_between:6,20|unique:products,code,'.$product->id,
        ], [
            'name.required'                     => 'Product name is required!',
            'category_id.required'              => 'category  is required!',
            'unit.required_if'                  => 'Unit is required!',
            'code.min'                          => 'The code must be positive!',
            'code.digits_between'               => 'The code must be minimum 6 digits!',
            'code.required'                     => 'Product code sku is required!',
            'minimum_order_qty.required'        => 'The minimum order quantity is required!',
            'minimum_order_qty.min'             => 'The minimum order quantity must be positive!',
            'digital_product_type.required_if'  => 'Digital product type is required!',
        ]);

        $submittedImages = is_string($request->images)
            ? (json_decode($request->images, true) ?: [])
            : ($request->images ?: []);
        if (count(array_filter((array) $submittedImages)) < 5) {
            $validator->after(fn ($validator) => $validator->errors()->add(
                'images',
                translate('at_least_five_product_images_are_required')
            ));
        }

        $brand_setting = BusinessSetting::where('type', 'product_brand')->value('value') ?? 0;
        if ($brand_setting && empty($request->brand_id)) {
            $validator->after(function ($validator) {
                $validator->errors()->add(
                    'brand_id', 'Brand is required!'
                );
            });
        }

        if ($request['discount_type'] == 'percent') {
            $dis = ($request['unit_price'] / 100) * $request['discount'];
        } else {
            $dis = $request['discount'];
        }

        if ($request['unit_price'] <= $dis) {
            $validator->after(function ($validator) {
                $validator->errors()->add(
                    'unit_price',
                    translate('Discount can not be more or equal to the price!')
                );
            });
        }


        $product->user_id = $seller->id;
        $product->added_by = "seller";

        $productName = $this->resolveLocalizedField($request, 'name');
        $product->name = $productName;
        $product->slug = Str::slug($productName, '-') . '-' . Str::random(6);

        $category = [];

        if ($request->category_id != null) {
            array_push($category, [
                'id' => $request->category_id,
                'position' => 1,
            ]);
        }
        if ($request->sub_category_id != null) {
            array_push($category, [
                'id' => $request->sub_category_id,
                'position' => 2,
            ]);
        }
        if ($request->sub_sub_category_id != null) {
            array_push($category, [
                'id' => $request->sub_sub_category_id,
                'position' => 3,
            ]);
        }

        $product->category_ids          = json_encode($category);
        $product->brand_id              = isset($request->brand_id) ? $request->brand_id : null;
        $product->unit                  = $request->product_type == 'physical' ? $request->unit : null;
        $product->sale_unit_type        = in_array($request->input('sale_unit_type'), ['package', 'box'], true) ? 'package' : 'piece';
        $product->pieces_per_unit       = in_array($request->input('sale_unit_type'), ['package', 'box'], true) ? (int) $request->pieces_per_unit : null;
        $product->length                = $request->filled('length') ? (float) $request->length : null;
        $product->width                 = $request->filled('width') ? (float) $request->width : null;
        $product->height                = $request->filled('height') ? (float) $request->height : null;
        $product->dimension_unit        = $request->filled('dimension_unit') ? $request->dimension_unit : null;
        $product->weight                = $request->filled('weight') ? (float) $request->weight : null;
        $product->weight_unit           = $request->filled('weight_unit') ? $request->weight_unit : null;
        $product->production_date       = $request->production_date;
        $product->expiry_date           = $request->expiry_date;
        $product->product_type          = $request->product_type;
        $product->digital_product_type  = $request->product_type == 'digital' ? $request->digital_product_type : null;
        $product->code                  = $request->code;
        $product->minimum_order_qty     = $request->minimum_order_qty;
        $product->details               = $this->resolveLocalizedField($request, 'description');

        $product->images                = json_encode($request->images);
        $product->thumbnail             = $request->thumbnail;

        if($request->product_type == 'digital') {
            if($request->digital_product_type == 'ready_product' && $request->digital_file_ready){
                if($product->digital_file_ready){
                    ImageManager::delete('product/digital-product/'.$product->digital_file_ready);
                }
                $product->digital_file_ready = $request->digital_file_ready;
            }elseif(($request->digital_product_type == 'ready_after_sell') && $product->digital_file_ready){
                ImageManager::delete('product/digital-product/'.$product->digital_file_ready);
                $product->digital_file_ready = null;
            }
        }elseif($request->product_type == 'physical' && $product->digital_file_ready){
            ImageManager::delete('product/digital-product/'.$product->digital_file_ready);
            $product->digital_file_ready = null;
        }

        if ($request->has('colors_active') && $request->has('colors') && count($request->colors) > 0) {
            $product->colors = $request->product_type == 'physical' ? json_encode($request->colors) : json_encode([]);
        } else {
            $colors = [];
            $product->colors = $request->product_type == 'physical' ? json_encode($colors) : json_encode([]);
        }

        $choice_options = [];
        if ($request->has('choice')) {
            foreach ($request->choice_no as $key => $no) {
                $str = 'choice_options_' . $no;
                $item['name'] = 'choice_' . $no;
                $item['title'] = $request->choice[$key];
                $item['options'] = $request[$str];
                array_push($choice_options, $item);
            }
        }
        $product->choice_options = $request->product_type == 'physical' ? json_encode($choice_options) : json_encode([]);

        //combinations start
        $options = [];
        if ($request->has('colors_active') && $request->has('colors') && count($request->colors) > 0) {
            $colors_active = 1;
            array_push($options, $request->colors);
        }
        if ($request->has('choice_no')) {
            foreach ($request->choice_no as $key => $no) {
                $name = 'choice_options_' . $no;
                array_push($options, $request[$name]);
            }
        }
        //Generates the combinations of customer choice options
        $combinations = Helpers::combinations($options);
        $variations = [];
        $stock_count = 0;
        if (count($combinations[0]) > 0) {

            foreach ($combinations as $combination) {
                $str = '';
                foreach ($combination as $k => $item) {
                    if ($k > 0) {
                        $str .= '-' . str_replace(' ', '', $item);
                    } else {
                        if ($request->has('colors_active') && $request->has('colors') && count($request->colors) > 0) {
                            $color_name = Color::where('code', $item)->first()->name ?? '';
                            $str .= $color_name;
                        } else {
                            $str .= str_replace(' ', '', $item);
                        }
                    }
                }
                $item = [];
                $item['type'] = $str;
                $item['price'] = Convert::usd(abs($request['price_' . str_replace('.', '_', $str)]));
                $item['sku'] = $request['sku_' . str_replace('.', '_', $str)];
                $item['qty'] = $request['qty_' . str_replace('.', '_', $str)];

                array_push($variations, $item);
                $stock_count += $item['qty'];
            }
        } else {
            $stock_count = (int)$request['current_stock'];
        }

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        //combinations end
        $product->variation         = $request->product_type == 'physical' ? json_encode($variations) : json_encode([]);
        $product->unit_price        = Convert::usd($request->unit_price);
        $product->purchase_price    = Convert::usd($request->purchase_price);
        $product->tax               = $request->tax;
        $product->tax_type          = $request->tax_type;
        $product->discount          = $request->discount_type == 'flat' ? Convert::usd($request->discount) : $request->discount;
        $product->discount_type     = $request->discount_type;
        $product->attributes        = $request->product_type == 'physical' ? json_encode($request->choice_attributes) : json_encode([]);
        $product->current_stock     = $request->product_type == 'physical' ? $request->current_stock : 0;

        $product->meta_title        = $request->meta_title;
        $product->meta_description  = $request->meta_description;

        $product->shipping_cost     = $request->product_type == 'physical' ? (getWebConfig(name: 'product_wise_shipping_cost_approval') == 1 ? $product->shipping_cost : Convert::usd($request->shipping_cost)) : 0;
        $product->multiply_qty      = ($request->product_type == 'physical') ? ($request->multiplyQTY == 1 ? 1 : 0) : 0;

        if (getWebConfig(name: 'product_wise_shipping_cost_approval') == 1 && ($product->shipping_cost != Convert::usd($request->shipping_cost)) && ($request->product_type == 'physical')) {
            $product->temp_shipping_cost = Convert::usd($request->shipping_cost);
            $product->is_shipping_cost_updated = 0;
        }

        if ($request->has('meta_image')) {
            $product->meta_image = $request->meta_image;
        }

        $product->video_provider = 'youtube';
        $product->video_url = $request->video_link;

        if ($wasRejected) {
            // A correction is a fresh submission; it cannot bypass the
            // administrator's publication decision.
            $product->request_status = 0;
            $product->status = 0;
            $product->is_homepage_visible = 0;
            $product->seller_review_status = \App\Services\SellerProductReviewService::SUBMITTED;
            $product->seller_review_reason = null;
            $product->denied_note = null;
            $product->seller_submitted_at = now();
            $product->seller_reviewed_at = null;
            $product->seller_reviewed_by = null;
        }
        $product->save();
        if ($wasRejected) {
            app(\App\Services\SellerProductReviewService::class)->submit($product);
        }

        foreach ($request->lang as $index => $key) {
            if ($request->name[$index] && $key != 'en') {
                Translation::updateOrInsert(
                    [
                        'translationable_type' => 'App\Models\Product',
                        'translationable_id' => $product->id,
                        'locale' => $key,
                        'key' => 'name'
                    ],
                    ['value' => $request->name[$index]]
                );
            }
            if ($request->description[$index] && $key != 'en') {
                Translation::updateOrInsert(
                    [
                        'translationable_type' => 'App\Models\Product',
                        'translationable_id' => $product->id,
                        'locale' => $key,
                        'key' => 'description'
                    ],
                    ['value' => $request->description[$index]]
                );
            }
        }

        return response()->json(['message' => translate('successfully product updated!')], 200);
    }

    private function resolveLocalizedField(Request $request, string $field): string
    {
        $values = $request->input($field, []);
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : [$values];
        }
        $values = is_array($values) ? array_values($values) : [];

        if ($values === []) {
            return '';
        }

        $languages = $request->input('lang', [Helpers::default_lang()]);
        if (is_string($languages)) {
            $decoded = json_decode($languages, true);
            $languages = is_array($decoded) ? $decoded : [$languages];
        }
        $languages = is_array($languages) ? array_values($languages) : [Helpers::default_lang()];
        $index = array_search(Helpers::default_lang(), $languages, true);

        return (string) ($values[$index === false ? 0 : $index] ?? $values[0]);
    }

    private function guardProductPolicy(Request $request, $seller, ?int $productId = null): ?JsonResponse
    {
        $policy = app(SellerProductContentPolicyService::class);
        $violations = $policy->inspect($request->only([
            'name', 'description', 'meta_title', 'meta_description', 'video_link',
        ]));

        if ($violations !== []) {
            $policy->record($seller, $violations, $productId);

            return response()->json(['errors' => [[
                'code' => 'seller_product_content_policy_violation',
                'message' => translate('seller_product_content_policy_violation'),
            ]]], 422);
        }

        if ($policy->countImages($request->input('images'), $request->input('color_image')) < 5) {
            return response()->json(['errors' => [[
                'code' => 'seller_product_minimum_images_required',
                'message' => translate('seller_product_minimum_images_required'),
            ]]], 422);
        }

        return null;
    }

    public function status_update(Request $request):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);
        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        $product = Product::where(['added_by' => 'seller', 'user_id' => $seller->id])->find($request->id);
        if (! $product) {
            return response()->json(['message' => translate('seller_product_not_found')], 404);
        }
        if ((int) $request->status === 1 && (int) $product->request_status !== 1) {
            return response()->json([
                'errors' => [[
                    'code' => 'product_must_be_approved_before_publication',
                    'message' => translate('product_must_be_approved_before_publication'),
                ]],
            ], 403);
        }
        if ((int) $request->status === 1 && (! $product->expiry_date || ! $product->expiry_date->isFuture())) {
            return response()->json(['errors' => [['code' => 'product_is_expired', 'message' => translate('product_is_expired')]]], 403);
        }
        $product->status = (int) $request->status;
        $product->save();

        return response()->json([
            'success' => translate('updated successfully'),
        ], 200);
    }

    public function delete(Request $request, $id):JsonResponse
    {
        $data = Helpers::get_seller_by_token($request);
        if ($data['success'] == 1) {
            $seller = $data['data'];
        } else {
            return response()->json([
                'auth-001' => translate('Your existing session token does not authorize you any more')
            ], 401);
        }

        $product = Product::where(['added_by' => 'seller', 'user_id' => $seller->id])->find($id);
        if (! $product) {
            return response()->json(['message' => translate('seller_product_not_found')], 404);
        }
        $this->sellerProductEntitlementService->cancelForDeletion($product);
        foreach (json_decode($product['images'], true) as $image) {
            ImageManager::delete('/product/' . $image);
        }
        ImageManager::delete('/product/thumbnail/' . $product['thumbnail']);
        $product->delete();
        FlashDealProduct::where(['product_id' => $id])->delete();
        DealOfTheDay::where(['product_id' => $id])->delete();
        return response()->json(['message' => translate('successfully product deleted!')], 200);
    }

    public function barcode_generate(Request $request):JsonResponse
    {
        $request->validate([
            'id' => 'required',
            'quantity' => 'required',
        ], [
            'id.required' => 'Product ID is required',
            'quantity.required' => 'Barcode quantity is required',
        ]);

        if ($request->limit > 270) {
            return response()->json(['code' => 403, 'message' => 'You can not generate more than 270 barcode']);
        }
        $product = Product::where('id', $request->id)->first();
        $quantity = $request->quantity ?? 30;
        if (isset($product->code)) {
            $pdf = app()->make(PDF::class);
            $pdf->loadView('seller-views.product.barcode-pdf', compact('product', 'quantity'));
            $pdf->save(storage_path('app/public/product/barcode.pdf'));
            return response()->json(asset('storage/app/public/product/barcode.pdf'));
        } else {
            return response()->json(['message' => translate('Please update product code!')], 203);
        }

    }
}
