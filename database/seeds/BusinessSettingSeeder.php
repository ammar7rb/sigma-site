<?php

use App\Models\BusinessSetting;
use App\Models\Currency;
use Illuminate\Database\Seeder;

class BusinessSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $egyptianPound = Currency::query()->firstOrCreate(
            ['code' => 'EGP'],
            [
                'name' => 'Egyptian Pound',
                'symbol' => 'ج.م',
                'exchange_rate' => 1,
                'status' => true,
            ]
        );

        $egyptianPound->update([
            'name' => 'Egyptian Pound',
            'symbol' => 'ج.م',
            'status' => true,
        ]);

        BusinessSetting::updateOrInsert(
            ['type' => 'system_default_currency'],
            ['value' => $egyptianPound->id]
        );

        BusinessSetting::updateOrInsert(
            ['type' => 'currency_symbol_position'],
            ['value' => 'right']
        );
    }
}
