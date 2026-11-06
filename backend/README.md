# الباك إند — زون

Laravel 13 (PHP 8.5) يخدم طرفين من موضع واحد:
- واجهة التطبيق `/api/v1`.
- لوحة الإدارة `/admin` (Filament 5).

ويكلّم الطرف الثالث، خدمة الوكلاء، من مدخلين فقط:
- `AiGateway` لنداءات الذكاء.
- `PushIndexEvent` لإشارات الفهرسة.

الخريطة الكاملة في `../docs/three-parties.md`.

## أين يوجد كل شيء

| المجلد | ما فيه | قاعدته |
|---|---|---|
| `routes/api.php` | كل مسارات التطبيق (135) بمجموعاتها وبواباتها | المرجع المولَّد: `../docs/api.md` |
| `app/Http/Controllers/Api/V1` | متحكم لكل مجال | رفيع: يتحقق، ينادي إجراءً، يعيد مورداً |
| `app/Http/Requests` | التحقق من المدخل (`ApiRequest`) | الأرقام العربية تُقرأ بدقة، والقيمة غير الصالحة تُرفض ولا تصير صفراً |
| `app/Http/Resources` | شكل JSON الذي يراه التطبيق | لا يخرج حقل إلا من هنا |
| `app/Http/Middleware` | التتبع، الإصدار، الحالة، الدور، الرغبة، حصة الذكاء، عناوين اللوحة | |
| `app/Actions/<المجال>` | منطق العمل: إجراء واحد لكل فعل (`CreateListing`، `ConfirmPayment`…) | التطبيق واللوحة ينفّذان الإجراء نفسه |
| `app/Models` | 54 نموذجاً | `$guarded = ['id']`، والتحويلات والعلاقات معلنة |
| `app/Enums` | كل حالة ونوع وقائمة | القيمة الجديدة تضاف هنا أولاً |
| `app/Ai` | `AiGateway` · `AgentsClient` · الإخفاء · الحصة · المرشّحون (`Retrieval`) · المساعدون (`Assistants`) | لا نداء للوكلاء إلا عبر `AiGateway` (اختبار معماري يفرضه) |
| `app/Indexing` | `IndexSync` (الإشارة داخل المعاملة) · `IndexPayloads` (ما يُفهرس، مقنَّعاً) · التوقيع | |
| `app/Integrations` | عقد وسائقان لكل جهة خارجية | `../docs/integrations.md` |
| `app/Jobs` · `app/Jobs/Scheduled` | مهام الطوابير، والمجدولة (17) | كل مجدولة لها سطر في `routes/console.php` |
| `app/Notifications` | `Notices` (كل إشعار بنصه ووجهته) · `AppNotice` · `PushChannel` | تفضيلات الإشعار الست تُحترم |
| `app/Filament` | اللوحة: `Resources` · `Pages` · `Widgets` · `Support` · `Concerns` | كل شاشة تعلن صلاحيتها (`AuthorizesByPermission`) |
| `app/SuperAgent` | الوكيل الأعلى: القوالب الـ 18 · المعاملات · المنفّذ · حارس الأرقام · السقف اليومي | النموذج يختار قالباً، ولا يكتب SQL |
| `app/Support` | `Api` (الغلاف) · `Trace` · `Money` · `Phone` · `Settings` · `Permissions` · `Health` | |
| `database/migrations` | 6 مهاجرات للمجالات + مشاهد `ai_v_*` | `../docs/data-model.md` |
| `tests` | `Feature/Api` · `Feature/Admin` · `Feature/Integrations` · `Unit/ArchitectureTest` | MySQL حقيقي، ولا طلب يغادر الجهاز |

## التشغيل المحلي

يتطلب PHP 8.5 بامتدادات Laravel المعتادة، ومعها pdo_mysql · redis (phpredis) · gd · intl · zip، وMySQL 9.7 LTS، وRedis. الإصدارات كلها وسببها: [`docs/versions.md`](../docs/versions.md).

```bash
composer install
cp .env.example .env && php artisan key:generate
# في .env للتطوير:
#   APP_ENV=local · APP_DEBUG=true · DB_DATABASE=zon · DB_USERNAME/DB_PASSWORD
#   SMS_DRIVER=fake · PAYMENTS_DRIVER=fake · PUSH_DRIVER=fake · IMAGE_HASH_DRIVER=gd
#   OTP_TEST_CODE=204815 (رمز التطبيق الثابت) · MEDIA_DISK=local
php artisan migrate --seed          # المرجع، الباقات، أدوار الموظفين، المعرفة، وبيانات تجريبية محلياً
php artisan zon:create-superadmin you@example.com
php artisan serve                   # الواجهة /api/v1 واللوحة /admin
php artisan queue:work --queue=index,ai,notify,default,media
```

خدمة الوكلاء محلياً في `../agents` (README هناك). وبدونها يعمل كل شيء بترتيب SQL، ويظهر ذلك في `meta.ai`.

## الفحوص

```bash
php artisan test                                   # 98 اختباراً على قاعدة zon_test (phpunit.xml)
vendor/bin/phpstan analyse --memory-limit=1G       # larastan المستوى 6، والاختبارات معه
vendor/bin/pint --test                             # تنسيق laravel
ZON_CONTRACT_DIR=../docs/contracts/fixtures php artisan test   # يسجّل حمولات الوكلاء لفحص العقد
```

قبل الاختبار:
- أنشئ القاعدة `zon_test`.
- أعطِ مستخدم الاختبار صلاحية `CREATE VIEW` (مشاهد الوكيل الأعلى).
- Redis يعمل؛ الاختبارات تستعمل قاعدة الحصة 15 وتفرغها.

## قواعد لا تُكسر

1. **المال:**
   - هللات صحيحة في الحساب (`Money`).
   - السعر يحدده الخادم لا التطبيق.
   - الدفع يُطبَّق بعد قراءة المزوّد فقط، ومرة واحدة.
2. **الذكاء:**
   - كل نص يغادر إلى عقدة الذكاء يمر بـ `AiMasking`: نداءات `AiGateway`، ونصوص العملاء في `IndexPayloads`.
   - من نتيجة الوكيل المعرّف والدرجة فقط، والمعرّفات التي لم تُرسل تُسقط.
3. **الحالة:** تغيير حالة عرض يمر بـ `ChangeListingStatus`: الحالة والسجل وإشارة الفهرس في معاملة واحدة.
4. **اللوحة:**
   - كل إجراء حساس له صلاحية من `Permission`، ويُسجَّل في التدقيق بـ `trace_id`.
   - محتوى المحادثات والمكتب لا يُعرض إلا بصلاحيته المستقلة، وكل اطلاع مسجَّل.
5. **المحاكاة:**
   - مكتوبة صراحة في رأس كل سائق `fake`.
   - مرفوضة في الإنتاج إلا بقرار في `PRODUCTION_ALLOWS_FAKE`.
