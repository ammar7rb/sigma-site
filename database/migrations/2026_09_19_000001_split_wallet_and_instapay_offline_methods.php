<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offline_payment_methods') || ! Schema::hasColumn('offline_payment_methods', 'payment_channel')) {
            return;
        }

        $legacy = DB::table('offline_payment_methods')
            ->where('payment_channel', 'other')
            ->orderBy('id')
            ->get()
            ->first(function (object $method): bool {
                $fields = json_decode($method->method_fields ?? '[]', true) ?: [];

                return collect($fields)->contains(
                    fn (array $field): bool => ($field['input_name'] ?? '') === 'wallet_or_instapay_account'
                );
            });

        $source = $legacy ?: DB::table('offline_payment_methods')->orderBy('id')->first();
        $sourceFields = json_decode($source?->method_fields ?? '[]', true) ?: [];
        $sourceInformation = json_decode($source?->method_informations ?? '[]', true) ?: [];
        $account = collect($sourceFields)->firstWhere('input_name', 'wallet_or_instapay_account')['input_data']
            ?? collect($sourceFields)->firstWhere('input_name', 'wallet_number')['input_data']
            ?? collect($sourceFields)->firstWhere('input_name', 'instapay_account')['input_data']
            ?? '';
        $instructions = collect($sourceFields)->firstWhere('input_name', 'instructions')['input_data'] ?? '';
        $customerInformation = $sourceInformation ?: [
            ['customer_input' => 'sender_wallet_or_phone', 'customer_placeholder' => 'Sender wallet / phone number', 'is_required' => 1],
            ['customer_input' => 'sender_name', 'customer_placeholder' => 'Sender name', 'is_required' => 0],
            ['customer_input' => 'payment_screenshot', 'customer_placeholder' => 'Payment screenshot', 'is_required' => 1, 'input_type' => 'image'],
        ];
        $status = (int) ($source?->status ?? 1);

        $walletFields = [['input_name' => 'wallet_number', 'input_data' => $account]];
        $instapayFields = [['input_name' => 'instapay_account', 'input_data' => $account]];
        if ($instructions !== '') {
            $walletFields[] = ['input_name' => 'account_holder_name', 'input_data' => $instructions];
            $instapayFields[] = ['input_name' => 'account_holder_name', 'input_data' => $instructions];
        }

        if ($legacy) {
            DB::table('offline_payment_methods')->where('id', $legacy->id)->update([
                'method_name' => 'Electronic Wallet',
                'payment_channel' => 'wallet',
                'method_fields' => json_encode($walletFields, JSON_UNESCAPED_UNICODE),
                'method_informations' => json_encode($customerInformation, JSON_UNESCAPED_UNICODE),
                'status' => $status,
                'updated_at' => now(),
            ]);
        } elseif (! DB::table('offline_payment_methods')->where('payment_channel', 'wallet')->exists()) {
            DB::table('offline_payment_methods')->insert([
                'method_name' => 'Electronic Wallet', 'payment_channel' => 'wallet',
                'method_fields' => json_encode($walletFields, JSON_UNESCAPED_UNICODE),
                'method_informations' => json_encode($customerInformation, JSON_UNESCAPED_UNICODE),
                'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! DB::table('offline_payment_methods')->where('payment_channel', 'instapay')->exists()) {
            DB::table('offline_payment_methods')->insert([
                'method_name' => 'InstaPay', 'payment_channel' => 'instapay',
                'method_fields' => json_encode($instapayFields, JSON_UNESCAPED_UNICODE),
                'method_informations' => json_encode($customerInformation, JSON_UNESCAPED_UNICODE),
                'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $wallet = DB::table('offline_payment_methods')->where('payment_channel', 'wallet')->first();
        $instapay = DB::table('offline_payment_methods')->where('payment_channel', 'instapay')->first();
        if (! $wallet || ! $instapay) {
            return;
        }

        $walletFields = json_decode($wallet->method_fields ?? '[]', true) ?: [];
        $account = collect($walletFields)->firstWhere('input_name', 'wallet_number')['input_data'] ?? '';
        $holder = collect($walletFields)->firstWhere('input_name', 'account_holder_name')['input_data'] ?? '';
        DB::table('offline_payment_methods')->where('id', $wallet->id)->update([
            'method_name' => 'Manual Transfer / Auto Payment Form',
            'payment_channel' => 'other',
            'method_fields' => json_encode([
                ['input_name' => 'wallet_or_instapay_account', 'input_data' => $account],
                ['input_name' => 'instructions', 'input_data' => $holder],
            ], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
        DB::table('offline_payment_methods')->where('id', $instapay->id)->delete();
    }
};
