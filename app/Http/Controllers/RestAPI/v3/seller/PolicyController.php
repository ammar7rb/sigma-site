<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Services\PolicyAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PolicyController extends Controller
{
    public function required(Request $request, PolicyAcceptanceService $service): JsonResponse
    {
        return response()->json(['policies' => $service->requiredFor('seller')]);
    }

    public function accept(Request $request, PolicyAcceptanceService $service): JsonResponse
    {
        $validator = Validator::make($request->all(), ['registration_reference' => 'required|uuid', 'policy_version_ids' => 'required|array|min:1', 'policy_version_ids.*' => 'integer']);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $seller = Seller::where('registration_reference', $request->registration_reference)->first();
        if (!$seller) return response()->json(['code' => 'seller_registration_not_found'], 404);
        $required = $service->requiredFor('seller')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($required, array_map('intval', $request->policy_version_ids))) return response()->json(['code' => 'required_policies_not_accepted'], 422);
        $service->accept('seller', $seller->id, $required);
        return response()->json(['status' => true]);
    }
}
