<?php

namespace App\Services;

class VendorPaymentInformationService
{
    public function __construct(private readonly SellerWithdrawalMethodService $withdrawalMethodService)
    {
    }

    public function getAddData(object|array $request, object|array $fields = []): array
    {
        $dynamicFields = [];
        foreach ($fields as $field) {
            $dynamicFields[$field['input_name']] = $request['method_info'][$field['input_name']] ?? '';
        }

        return $this->withdrawalMethodService->prepareSubmission([
            'user_id' => auth('seller')->id(),
            'withdraw_method_id' => $request['withdraw_method_id'],
            'method_name' => $request['method_name'],
            'is_active' => 0,
            'method_info' => $dynamicFields,
        ], $request['method_type'] ?? null);
    }

    public function getApiAddData(int $sellerID, object|array $request, object|array $fields = []): array
    {
        $dynamicFields = [];

        foreach ($fields as $field) {
            $dynamicFields[$field['input_name']] = $request['method_info'][$field['input_name']] ?? '';
        }

        return $this->withdrawalMethodService->prepareSubmission([
            'user_id' => $sellerID,
            'withdraw_method_id' => $request['withdraw_method_id'],
            'method_name' => $request['method_name'],
            'is_active' => 0,
            'method_info' => $dynamicFields,
        ], $request['method_type'] ?? null);
    }
}
