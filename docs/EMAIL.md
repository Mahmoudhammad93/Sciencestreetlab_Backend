# تشغيل الإيميل بالكامل

الإيميل على السيرفر مربوط بـ **Brevo** (Sendinblue). التطبيق لا يرسل SMTP مباشرة. يرسل عبر واجهة Brevo:

`POST https://api.brevo.com/v3/smtp/email`

المرسل الحالي: `noreply@sciencestreetlab.com`  
روابط الإيميل (إعادة تعيين كلمة المرور، تأكيد الطلب، QR) تتبني من `FRONTEND_URL`. على السيرفر لازم تكون عنوان الموقع العام، مش `http://localhost:5173`.  
اسم المرسل: `Science Street Lab`

المفتاح **لا يُكتب في Git**. يعيش في واحد من مكانين فقط:

- `BREVO_API_KEY` في ملف `.env` غير المتتبع
- أو ملف السيرفر `storage/app/private/brevo.key` (صلاحيات `640`، المالك `www-data`)

لو المفتاح موجود و`MAIL_MAILER` لسه `log` أو فاضي، التطبيق يحوّل الإرسال إلى Brevo تلقائياً. لو `MAIL_FROM_ADDRESS` لسه `hello@example.com`، يستخدم `noreply@sciencestreetlab.com`.

## الحالة الحالية

| الجزء | الحالة |
|---|---|
| كود الإرسال (Brevo transport) | على الحاويات الحية `science-street-backend` و `science-street-queue` |
| المفتاح | محفوظ على السيرفر، مش في المستودع |
| المرسل | `noreply@sciencestreetlab.com` |
| الـ queue | شغال، والإيميلات **ما بتتبعتش جوه الطلب**. بتتسجل في طابور `database` |
| إرسال فعلي من السيرفر | **موقوف من Brevo** لأن IP السيرفر مش مصرح له |

آخر رد من Brevo عند تجربة الحساب:

```text
HTTP 401 unauthorized
unrecognised IP address 13.39.47.202
```

من غير الخطوات تحت، أي طلب شراء ناجح هيسجل job، والـ job هيفشل، ومش هيوصل إيميل.

## المطلوب عشان يشتغل بالكامل

اعمل الخطوات دي بالترتيب من حساب Brevo. مفيش تغيير كود مطلوب لها.

### 1. مصرّح لـ IP السيرفر

ده اللي واقف عليه الإرسال دلوقتي.

1. افتح [Authorized IPs](https://app.brevo.com/security/authorised_ips).
2. أضف IP السيرفر: `13.39.47.202`.
3. لو هتجرب الإرسال من جهازك المحلي، أضف IP جهازك كمان. آخر مرة ظهر `196.151.32.94`، ولو النت اتغير الـ IP يتغير.

Brevo بيرفض مفتاح `xkeysib-` من أي IP مش في القائمة. القائمة تتقفل من إعدادات الأمان في الحساب، مش من الكود.

### 2. ثبّت المرسل

المطلوب: `noreply@sciencestreetlab.com` يكون **Sender مفعّل** في Brevo، مش مجرد عنوان مكتوب في `.env`.

1. افتح [Senders](https://app.brevo.com/senders).
2. أضف `noreply@sciencestreetlab.com` لو مش موجود.
3. أكد إيميل التفعيل اللي Brevo يبعته للصندوق ده، أو خلّي الحالة `Verified`.
4. الاسم الظاهر يفضل `Science Street Lab`.

لو المرسل مش مفعّل، Brevo هيرفض الرسالة حتى بعد ما الـ IP يتضاف. رسالة الرفض عادة فيها إن الـ sender غير مصرح.

`hello@sciencestreetlab.com` عنوان تواصل في إعدادات الموقع. **مش** عنوان الإرسال. ما تغيّرهوش عشان الإيميل.

### 3. وثّق الدومين (عشان الإيميل يوصل ومش يروح Spam)

تفعيل المرسل لوحده يخلي Brevo يقبل الإرسال. عشان Gmail و Outlook يسلّموا الرسالة ولا يحطوها في السبام، الدومين `sciencestreetlab.com` لازم يكون موثّق في Brevo.

1. افتح [Domains](https://app.brevo.com/senders/domain/list).
2. أضف `sciencestreetlab.com` لو مش مضاف.
3. انسخ سجلات DNS اللي Brevo يطلعها (عادة DKIM وبيان SPF، وأحياناً DMARC) وحطها عند مزود الدومين.
4. استنى الانتشار، ثم اضغط Validate / Authenticate في Brevo لحد ما الحالة تبقى authenticated.

لا تخترع قيم السجلات. استخدم القيم اللي Brevo يعرضها للحساب ده بالظبط. لو فيه SPF قديم على الدومين، ادمج `include` بتاع Brevo في نفس السجل بدل ما تعمل سجل SPF ثاني. سجل SPF واحد فقط مسموح به.

### 4. خلّي الـ queue شغال

الإيميلات دي كلها `ShouldQueue`:

- تأكيد الطلب بعد الدفع الناجح، ومعاه QR لكل كورس اتسجل
- تفعيل الإيميل عند التسجيل
- إعادة تعيين كلمة المرور

الـ worker هو حاوية `science-street-queue`، والأمر:

```text
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

لو الحاوية واقفة، الدفع ينجح والتسجيل يتم، لكن الإيميل يفضل في جدول `jobs` ومش يطلع.

بعد أي تغيير في `.env` أو في ملف المفتاح، أعد تشغيل الحاويتين عشان الـ worker يقرأ الإعدادات من جديد:

```bash
docker restart science-street-backend science-street-queue
```

تغيير `.env` على الهوست **مش** يوصل للحاوية إلا بعد إعادة إنشاء الحاوية. التشغيل الحالي بيقرأ المفتاح من `storage/app/private/brevo.key`، والملف ده على volume مش بيتمسح مع restart.

### 5. متسحبش نشر جديد يمسح كود الإرسال

كود Brevo اتنشر على الحاويات الحية. لسه مش في Git. أي نشر بيعيد بناء الصورة من المستودع ويمسح ملفات الحاوية هيرجع الميل لوضع `log` (يتسجل ومش يتبعت).

قبل أي rebuild للحاويات، كود الإرسال لازم يكون موجود جوه الصورة أو يتنسخ تاني على:

- `science-street-backend`
- `science-street-queue`

الـ queue لوحدها كانت قديمة ومش فيها listener تأكيد الطلب. الاتنين لازم يكونوا على نفس الكود.

## إيه اللي التطبيق بيبعت

### تأكيد الطلب

يتبعت **بعد الدفع الناجح فقط**، وبعد ما التسجيل في الكورس يتسجل.

```text
دفع ناجح → OrderPaid → تسجيل في الكورس → إيميل تأكيد → QR لكل كورس
```

- إنشاء الطلب أو فشل الدفع لا يبعت إيميل.
- المنتجات اللي مش كورس مش ليها QR.
- كل كورس له QR واحد. الرابط: `{FRONTEND_URL}/verify/enrollment/{token}`.
- `confirmation_email_sent_at` يتكتب **بعد** ما Brevo يقبل الرسالة. لو الإرسال فشل، الحقل يفضل فاضي والجob يعاد (3 محاولات). الطلب نفسه ما يتبعتش مرتين.
- لو المحاولة وقفت في النص، القفل `confirmation_email_claimed_at` يتحرر بعد 15 دقيقة عشان المحاولة التانية تقدر تشتغل.

صفحة المسح العامة `{FRONTEND_URL}/verify/enrollment/{token}` مش جزء من إرسال الميل. الإيميل يوصل حتى لو الصفحة لسه مش متعملة في الفرونت، لكن العميل لما يمسح الـ QR مش هيلاقي الصفحة. التحقق الرسمي من الـ API:

`GET /api/v1/enrollment-verification/{token}`

### تفعيل الحساب وإعادة كلمة المرور

الاتنين على نفس الـ mailer ونفس الـ queue. لو Brevo وقف الإرسال، التسجيل وتسجيل الدخول بالإيميل المفعّل والـ reset كمان مش هيوصلوا.

## اختبار بعد ما Brevo يتفتح

1. تأكد إن الخطوات 1 و 2 خلصت. الخطوة 3 مهمة للوصول الفعلي، مش لقبول Brevo.
2. من داخل الحاوية، من غير ما تطبع المفتاح:

```bash
docker exec science-street-backend php artisan tinker --execute="echo config('mail.default').PHP_EOL; echo config('mail.from.address').PHP_EOL; echo (config('services.brevo.key') ? 'key_set' : 'key_missing').PHP_EOL;"
```

المتوقع:

```text
brevo
noreply@sciencestreetlab.com
key_set
```

3. ابعت رسالة تجريبية لصندوق بتقدر تفتحه (غيّر العنوان):

```bash
docker exec science-street-backend php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('Science Street Lab mail test', function (\$m) { \$m->to('you@example.com')->subject('SSL mail test'); }); echo 'sent'.PHP_EOL;"
```

- لو Brevo لسه رافض الـ IP، هتظهر `401` و `unrecognised IP address`.
- لو الـ IP مفتوح والمرسل مش مفعّل، هتظهر رسالة إن الـ sender غير مسموح.
- لو الرسالة نجحت، هتوصل من `noreply@sciencestreetlab.com`. لو في السبام، ارجع للخطوة 3.

4. اعمل طلب تجريبي مدفوع بحساب له إيميل حقيقي. بعد ما الـ queue يخلّص الـ job، راجع:

- الإيميل وصلك وفيه بيانات الطلب
- فيه QR لكل كورس اتسجل، ومفيش QR لمنتج مش كورس
- `orders.confirmation_email_sent_at` اتملأ
- مفيش صف جديد فاشل في `failed_jobs` متعلق بـ `SendOrderConfirmationEmail`

## لو الإيميل متبعتش

| العرض | السبب المحتمل | الإجراء |
|---|---|---|
| `401` و `unrecognised IP address` | IP السيرفر مش في Brevo | الخطوة 1 |
| sender غير مسموح | `noreply@sciencestreetlab.com` مش Verified | الخطوة 2 |
| الرسالة في السبام | الدومين مش موثّق | الخطوة 3 |
| الدفع نجح ومفيش إيميل، والـ job موجود في `jobs` | الـ queue واقف | `docker start science-street-queue` |
| job في `failed_jobs` | شوف `exception` في الصف. متعملش retry قبل ما تصلح السبب | بعد الإصلاح: `php artisan queue:retry {id}` |
| `confirmation_email_sent_at` فاضي و `confirmation_email_claimed_at` مليان | محاولة معلقة | استنى 15 دقيقة أو امسح `confirmation_email_claimed_at` بعد ما تتأكد إن الإرسال فشل، ثم أعد الـ job |
| اللوج بيقول اتبعت ومفيش حاجة وصلت | `MAIL_MAILER=log` والمفتاح مش مقروء | تأكد من ملف المفتاح أو `BREVO_API_KEY`، ثم أعد تشغيل الحاويتين |

متنسخ المفتاح في الشات أو في اللوج أو في ملف متتبع. لو المفتاح اتسرب، اعمل دوران له من Brevo وحدّث `storage/app/private/brevo.key` و`BREVO_API_KEY`، ثم أعد تشغيل الحاويتين.
