# تكامل تطبيق التاجر: الباقات والتأمين والعروض والكوبونات

هذا المستند يصف واجهات API الفعلية المضافة لتطبيق التاجر. جميع المسارات أدناه تبدأ من `BASE_URL` للتطبيق، مثل:

```text
https://example.com
```

لا تضع `/api` مرتين داخل `baseUrl`؛ التطبيق يضيف المسار الموجود في `AppConstants` بنفسه.

## المصادقة

كل المسارات، ما عدا تسجيل الدخول والتسجيل والتحقق، تتطلب:

```http
Authorization: Bearer <seller_auth_token>
Accept: application/json
```

يحصل التطبيق على `seller_auth_token` من تسجيل دخول التاجر ويحفظه كما هو في `AppConstants.token`.

قبل السماح بأي طلب محمي، يتحقق السيرفر من أن حساب التاجر معتمد وأن OTP الهاتف مكتمل. الأخطاء المهمة:

| الحالة | كود HTTP | `errors[0].code` |
|---|---:|---|
| حساب التاجر لم يعتمد بعد | 403 | `seller_account_not_approved` |
| OTP الهاتف لم يكتمل | 403 | `seller_phone_verification_required` |
| Token غير صالح أو انتهت الجلسة | 401 | `auth-001` |

## التسلسل التجاري الصحيح

1. يسجل التاجر، يكمل OTP، ثم يعتمد الأدمن الحساب.
2. يدفع تأمين التاجر مرة واحدة. في الدفع اليدوي تظهر حالة `pending_review` حتى يعتمد الأدمن العملية.
3. بعد تفعيل التأمين، يختار التاجر ويدفع باقة. الباقة اليدوية تبقى `pending_review` حتى اعتماد الأدمن.
4. عند ظهور `insurance.active = true` و`subscription.active.status = active` يستطيع إضافة منتجات في حدود `remaining_product_limit`.
5. المنتجات المعتمدة فقط يمكن وضعها ضمن عروض البحث أو الصفحة الرئيسية.
6. الكوبونات تعتمد على `coupon_entitlement.remaining` القادم من السيرفر.

لا يجوز منح التاجر صلاحية محليًا بعد رفع إثبات الدفع. يتم تحديث الواجهة دائمًا عبر `GET` بعد قرار الأدمن.

## 1. الباقات

### عرض الباقات والحالة الحالية

```http
GET /api/v3/seller/packages
```

أهم حقول الاستجابة:

```json
{
  "packages": [{"id": 1, "name": "Ultra", "package_price": 300}],
  "subscription": {
    "insurance_satisfied": true,
    "can_purchase": true,
    "pending_review": false,
    "active": {
      "status": "active",
      "remaining_product_limit": 8,
      "remaining_search_promotion_limit": 2,
      "remaining_homepage_promotion_limit": 1,
      "remaining_coupon_limit": 4
    },
    "pending": null
  },
  "digital_payment_available": true,
  "payment_gateways": [{"key_name": "...", "title": "..."}],
  "offline_payment_available": true,
  "offline_payment_methods": []
}
```

### دفع باقة إلكترونيًا

```http
POST /api/v3/seller/packages/pay
Content-Type: application/json

{
  "package_id": 1,
  "payment_method": "gateway_key_name",
  "payment_platform": "vendor_app"
}
```

افتح `redirect_link` في متصفح/ويب فيو آمن. بعد الرجوع من بوابة الدفع، أعد طلب `GET /packages` بدل تفعيل الباقة محليًا.

### دفع باقة تحويلًا يدويًا

```http
POST /api/v3/seller/packages/offline-payment
Content-Type: multipart/form-data
```

الحقول:

| الحقل | النوع | مطلوب |
|---|---|---|
| `package_id` | integer | نعم |
| `method_id` | integer | نعم |
| `method_informations` | JSON string | نعم |
| `payment_note` | string | لا |
| `payment_proof` | image jpg/jpeg/png/webp، حتى 5MB | نعم |

مثال `method_informations`:

```json
{"sender_wallet":"01000000000","sender_name":"Seller name"}
```

الاستجابة الناجحة لا تفعّل الباقة؛ تعيد اشتراكًا بحالة `pending_review` ورسالة انتظار مراجعة الأدمن.

## 2. تأمين التاجر

### حالة التأمين وطرق الدفع

```http
GET /api/v3/seller/insurance/status
```

أهم الحقول:

```json
{
  "insurance": {
    "enabled": true,
    "configured_amount": 100,
    "required": true,
    "active": false,
    "pending_review": false,
    "can_pay": true,
    "latest": null
  },
  "payment_gateways": [],
  "offline_payment_methods": []
}
```

### دفع التأمين إلكترونيًا

```http
POST /api/v3/seller/insurance/pay
Content-Type: application/json

{
  "payment_method": "gateway_key_name",
  "payment_platform": "vendor_app"
}
```

استخدم `redirect_link` ثم أعد تحميل `GET /insurance/status`.

### دفع التأمين تحويلًا يدويًا

```http
POST /api/v3/seller/insurance/offline-payment
Content-Type: multipart/form-data
```

نفس حقول الدفع اليدوي للباقة، لكن بدون `package_id`:

```text
method_id, method_informations, payment_note?, payment_proof
```

لا يمكن شراء باقة قبل أن يصبح التأمين فعالًا. عند الموافقة اليدوية من لوحة الأدمن، تصبح `insurance.active = true`.

## 3. إضافة المنتجات

المسار الموجود أصلًا:

```http
POST /api/v3/seller/products/add
```

التطبيق يعرض قفلًا قبل فتح النموذج، لكن السيرفر يمنع التجاوز أيضًا. الأخطاء المتوقعة عند الإضافة:

| كود الخطأ | المعنى |
|---|---|
| `active_seller_insurance_is_required_before_adding_products` | يجب تفعيل التأمين أولًا |
| `active_seller_package_is_required_before_adding_products` | يجب تفعيل باقة أولًا |
| `seller_package_product_duration_is_not_configured` | الباقة لا تحتوي مدة عرض صحيحة |
| `seller_package_product_limit_has_been_reached` | استهلك التاجر حد المنتجات |

بعد نجاح الإضافة، يحتسب السيرفر الحصة ولا يعتمد على عداد التطبيق.

## 4. عروض المنتجات المميزة

### عرض البحث المميز

```http
GET  /api/v3/seller/promotions/search
POST /api/v3/seller/promotions/search/activate
Content-Type: application/json

{"product_id": 55}
```

### عرض الصفحة الرئيسية

```http
GET  /api/v3/seller/promotions/homepage
POST /api/v3/seller/promotions/homepage/activate
Content-Type: application/json

{"product_id": 55}
```

كل `GET` يعيد:

```json
{
  "summary": {
    "insurance_satisfied": true,
    "active_package": "Ultra",
    "remaining_search_promotion_limit": 2,
    "search_promotion_duration_days": 7,
    "can_promote": true
  },
  "products": [],
  "history": []
}
```

استخدم حقول العروض المطابقة للنوع المطلوب: `remaining_search_promotion_limit` أو `remaining_homepage_promotion_limit`. لا تعرض زر التفعيل عندما `can_promote = false`، مع إبقاء معالجة `403` القادمة من السيرفر.

## 5. الكوبونات

```http
GET  /api/v3/seller/coupon/list?limit=10&offset=1
POST /api/v3/seller/coupon/store
PUT  /api/v3/seller/coupon/update/{id}
```

استجابة القائمة تحتوي:

```json
{
  "coupon_entitlement": {
    "allowed": true,
    "limit": 5,
    "used": 1,
    "adjustment": 0,
    "remaining": 4
  },
  "coupons": []
}
```

أنشئ كوبونًا فقط إذا كانت `allowed = true`. عند الرفض، اعرض رسالة السيرفر، خصوصًا:

* `active_seller_package_is_required_to_create_coupons`
* `seller_coupon_quota_has_been_reached`

تعديل أو حذف كوبون موجود لا يستهلك حصة جديدة.

## 6. الشحن

الشحن أصبح مملوكًا للأدمن. لا تسمح واجهة التاجر بإضافة أو تعديل أسعار الشحن أو طرقه.

عند تفعيل نظام الشحن الثلاثي في لوحة الأدمن، يرفض السيرفر أي تعديل قديم من التاجر على هذه المسارات بـ `403` ورسالة `Shipping is managed by admin`:

```text
/api/v3/seller/shipping-method/*
/api/v3/seller/shipping/selected-shipping-method
/api/v3/seller/shipping/set-category-cost
```

سعر الشحن النهائي يُحسب من إعدادات الأدمن وأسعار المنتج وقاعدة زيادة الكمية، ولا يرسل التطبيق سعرًا موثوقًا من طرفه.

## 7. الدفع اليدوي ومراجعة الأدمن

1. يختار التاجر طريقة التحويل اليدوي التي يعيدها `offline_payment_methods`.
2. يدخل الحقول التي تتطلبها `method_informations` ويرفع `payment_proof` كملف صورة فعلي، وليس كنص أو Base64.
3. تظهر للتاجر حالة انتظار المراجعة.
4. يراجع الأدمن الطلب من لوحة الأدمن:
   * `Admin > Vendors > Insurance Payments`
   * `Admin > Vendors > Package Payments`
5. بعد الموافقة أو الرفض، يعيد التطبيق طلب واجهة الحالة المناسبة لتحديث الشاشة.

## قواعد واجهة التطبيق

* لا تخزّن أو تحسب الحصص كقرار نهائي داخل Flutter.
* بعد أي دفع أو طلب عرض أو إنشاء كوبون، أعد طلب بيانات الحالة من الـ API.
* اعرض رسالة `errors[0].message` أو `message` من الاستجابة بدل رسالة عامة.
* في multipart لا تضع `Content-Type: application/json` يدويًا؛ اترك Dio ينشئ boundary الخاص بـ `FormData`.
* لا تعد صفحة مندوب التوصيل أو الأرباح أو Inbox/الردود إلى قائمة تطبيق التاجر حسب المتفق عليه.

## قائمة تجربة قبل التسليم

1. تسجيل تاجر جديد ثم OTP واعتماد الحساب.
2. دفع التأمين يدويًا، والتأكد أن الحالة `pending_review`.
3. اعتماد التأمين من لوحة الأدمن، ثم تحديث شاشة التطبيق لتظهر `active`.
4. دفع باقة يدويًا، اعتمادها، والتحقق من الحصص.
5. إضافة منتج حتى الوصول إلى الحد، ثم التأكد من الرفض.
6. تفعيل عرض بحث وعرض رئيسية ضمن الحدود.
7. إنشاء كوبونات حتى الوصول إلى الحد، ثم التأكد من قفل زر الإضافة ورفض API.
8. محاولة تعديل شحن التاجر بعد تفعيل شحن الأدمن والتأكد من رفضه.
