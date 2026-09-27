<?php

return [
    // نقطة انطلاق الشحن الافتراضية. عدّلها إلى موقع المخزن الفعلي عند توفره.
    'dispatch' => [
        'latitude' => 30.0444,
        'longitude' => 31.2357,
    ],

    'governorates' => [
        'القاهرة', 'الجيزة', 'الإسكندرية', 'الدقهلية', 'البحر الأحمر', 'البحيرة',
        'الفيوم', 'الغربية', 'الإسماعيلية', 'المنوفية', 'المنيا', 'القليوبية',
        'الوادي الجديد', 'السويس', 'أسوان', 'أسيوط', 'بني سويف', 'بورسعيد',
        'دمياط', 'الشرقية', 'جنوب سيناء', 'كفر الشيخ', 'مطروح', 'الأقصر',
        'قنا', 'شمال سيناء', 'سوهاج',
    ],

    // Used only when the administrator leaves the dispatch address blank.
    // A specific warehouse address, when entered, is geocoded server-side.
    'governorate_centers' => [
        'القاهرة' => ['latitude' => 30.0444, 'longitude' => 31.2357],
        'الجيزة' => ['latitude' => 30.0131, 'longitude' => 31.2089],
        'الإسكندرية' => ['latitude' => 31.2001, 'longitude' => 29.9187],
        'الدقهلية' => ['latitude' => 31.0409, 'longitude' => 31.3785],
        'البحر الأحمر' => ['latitude' => 27.2579, 'longitude' => 33.8116],
        'البحيرة' => ['latitude' => 31.0341, 'longitude' => 30.4682],
        'الفيوم' => ['latitude' => 29.3084, 'longitude' => 30.8428],
        'الغربية' => ['latitude' => 30.7865, 'longitude' => 31.0004],
        'الإسماعيلية' => ['latitude' => 30.5965, 'longitude' => 32.2715],
        'المنوفية' => ['latitude' => 30.5526, 'longitude' => 31.0100],
        'المنيا' => ['latitude' => 28.1099, 'longitude' => 30.7503],
        'القليوبية' => ['latitude' => 30.4658, 'longitude' => 31.1847],
        'الوادي الجديد' => ['latitude' => 25.4400, 'longitude' => 30.5464],
        'السويس' => ['latitude' => 29.9668, 'longitude' => 32.5498],
        'أسوان' => ['latitude' => 24.0889, 'longitude' => 32.8998],
        'أسيوط' => ['latitude' => 27.1801, 'longitude' => 31.1837],
        'بني سويف' => ['latitude' => 29.0661, 'longitude' => 31.0994],
        'بورسعيد' => ['latitude' => 31.2653, 'longitude' => 32.3019],
        'دمياط' => ['latitude' => 31.4175, 'longitude' => 31.8144],
        'الشرقية' => ['latitude' => 30.5877, 'longitude' => 31.5020],
        'جنوب سيناء' => ['latitude' => 28.2410, 'longitude' => 33.6220],
        'كفر الشيخ' => ['latitude' => 31.1107, 'longitude' => 30.9388],
        'مطروح' => ['latitude' => 31.3543, 'longitude' => 27.2373],
        'الأقصر' => ['latitude' => 25.6872, 'longitude' => 32.6396],
        'قنا' => ['latitude' => 26.1551, 'longitude' => 32.7160],
        'شمال سيناء' => ['latitude' => 31.1313, 'longitude' => 33.7987],
        'سوهاج' => ['latitude' => 26.5591, 'longitude' => 31.6957],
    ],
];
