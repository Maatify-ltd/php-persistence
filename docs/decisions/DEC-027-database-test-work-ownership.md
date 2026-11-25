# DEC-027 — ملكية العمل والتنظيف في اختبارات قاعدة البيانات

- **Decision ID:** `DEC-027`
- **Status:** `ACTIVE`
- **Date:** 2026-11-25
- **Decision Authority:** مالك المشروع في [#93](https://github.com/Maatify-ltd/php-persistence/issues/93#issuecomment-7158157917)
- **Scope / Concern:** أعمال Integration وExamples وConsumer verification التي تستلم بيانات اتصال الاختبار، والـfixtures التي تملك معاملات أو تقرأ cache المعاملات عمدًا.
- **Canonical Contract / Current Owner:** [CI_AND_INTEGRATION.md §2](../architecture/CI_AND_INTEGRATION.md#database-worker-ownership-and-fixture-cleanup)
- **Supersedes:** —
- **Superseded By:** —

## السياق

أعادت التجارب المعزولة إنتاج قراءات InnoDB قديمة بعد إنهاء المعاملة، ورصدت عاملًا تابعًا استمر بعد خروج العملية المباشرة. أعاد المالك تحديد النطاق بعد أن تعذر تأهيل بوابة انتظار مشتركة لكل قراءات الـrunner. [أدلة التأهيل](https://github.com/Maatify-ltd/php-persistence/issues/93#issuecomment-7158000201) · [قرار القيادة](https://github.com/Maatify-ltd/php-persistence/issues/93#issuecomment-7158090027)

## القرار

يشرف الـharness على مجموعة عمليات مملوكة لكل عمل يستلم بيانات اتصال قاعدة الاختبار. يلزم خروج العمل المباشر واختفاء العمال المملوكين قبل اعتبار العمل ناجحًا. يسجل أي فشل قبل التنظيف الاضطراري ويحافظ على النتيجة الأصلية. تفترض هذه الحدود بقاء السكربتات والعمال الموثوقين في المجموعة التي خصصها المشرف.

تثبت الـfixture نهاية العمل الذي تملكه بأدلة مناسبة: إقرار END، أو قفل وقراءة مستقلة للصفوف التي يتناولها السيناريو. تنتظر الـfixture التي قرأت cache عمدًا مدة هدوء ثابتة لا تقل عن 200ms على ساعة monotonic بعد تنظيف الجلسات، ضمن [الإصدارات السبعة المؤهلة من المصدر](https://github.com/Maatify-ltd/php-persistence/issues/93#issuecomment-7157606579).

## النتائج

العينة الفورية الأصلية تبقى حاكمة. وجود جدول أو معاملة يفشل الاختبار، وتبقى Shadow للتشخيص. العقد يخص أدوات الاختبار وأعمالها المملوكة على Linux وmacOS، ولا يمنح نطاق دعم جديدًا للحزمة أو يغير ملكية المعاملات في الـruntime.
