<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('offline_payment_methods')->orderBy('id')->get()->each(function (object $method): void {
            $fields = json_decode($method->method_informations ?? '[]', true) ?: [];
            $hasScreenshot = false;

            foreach ($fields as &$field) {
                if (($field['customer_input'] ?? null) === 'payment_screenshot') {
                    $field['input_type'] = 'image';
                    $field['is_required'] = 1;
                    $hasScreenshot = true;
                }
            }
            unset($field);

            if (! $hasScreenshot) {
                $fields[] = [
                    'customer_input' => 'payment_screenshot',
                    'customer_placeholder' => 'Payment screenshot',
                    'is_required' => 1,
                    'input_type' => 'image',
                ];
            }

            DB::table('offline_payment_methods')->where('id', $method->id)->update([
                'method_informations' => json_encode($fields),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Keep existing proof requirements intact when rolling back.
    }
};
