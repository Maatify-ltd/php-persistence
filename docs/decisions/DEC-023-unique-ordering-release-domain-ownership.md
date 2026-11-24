# DEC-023 — ملكية المجال لتحريك المواضع تحت UNIQUE في الإصدار المخطط

- **Decision ID:** DEC-023
- **Status:** SUPERSEDED
- **Date:** 2026-11-23
- **اللغة:** العربية
- **Decision Authority:** مالك المشروع، وفق [الاعتماد الصريح](https://github.com/Maatify-ltd/php-persistence/issues/77#issuecomment-7096558971) بعد [المراجعة الفنية المستقلة](https://github.com/Maatify-ltd/php-persistence/issues/77#issuecomment-7095501146).
- **Scope / Concern:** حدود ملكية تحريك المواضع الفريدة ضمن الإصدار المقبل الذي تتناوله [خطة الإصدار #65](https://github.com/Maatify-ltd/php-persistence/issues/65) عند اعتماد القرار في 23 نوفمبر 2026.
- **Canonical Contract / Current Owner:** [Package Reference §5.4](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#54-scopedorderingmanager) لعقد `ScopedOrderingManager`، و[خطة الإصدار #65](https://github.com/Maatify-ltd/php-persistence/issues/65) لتحديد نطاق الإصدار.
- **Supersedes:** —
- **Superseded By:** [DEC-026](./DEC-026-protected-unique-ordering-b1.md)

## السياق

يمكن أن ترفض قاعدة البيانات الإزاحة الوسيطة في `ScopedOrderingManager::moveWithinScope()` عند وجود قيد `UNIQUE`، حتى عندما يقبل القيد الترتيب النهائي. يوثّق عقد Ordering الحالي هذا الحد. أثبتت [WU-C1 #75](https://github.com/Maatify-ltd/php-persistence/issues/75#issuecomment-7090568614) و[PR #76](https://github.com/Maatify-ltd/php-persistence/pull/76) التصادم، وأثبتتا نجاح التموضع المؤقت ثم الإزاحة الاتجاهية في مخططات وشروط قفل محددة. بقيت الهارنس الاختيارية لهذه الحالات غير مثبتة على MySQL 8.0.16. [المصالحة النهائية لـWU-C1](https://github.com/Maatify-ltd/php-persistence/issues/75#issuecomment-7093563787)

## القرار

1. اختيار **D / Domain-owned / NO-NEW-API** في نطاق هذا الإصدار فقط. تستبقي `maatify/php-persistence` عقد Ordering الحالي وحدوده الموثقة. لا تدخل خوارزمية B أو واجهة عامة جديدة لتحريك المواضع تحت `UNIQUE` في نطاق الإصدار.
2. إذا احتاج مستهلك إلى ذلك الضمان، يتحمل المجال مسؤولية مخططه وقيوده الفعلية، بما فيها `NULL` والحذف المنطقي والفهارس الإضافية و`CHECK` والمفاتيح الخارجية والـtriggers، وصلاحية الخانة المؤقتة إن استُخدمت، وتنسيق الـanchor والأقفال لجميع الكتّاب، وSQL الحركة وملكية المعاملة.
3. يبقى `ScopedOrderingManager` وعقده الحالي على حالهما. تظل القرارات المعمارية السابقة نافذة في نطاقاتها.

## البديل والمبررات

درس [WU-C2 #77](https://github.com/Maatify-ltd/php-persistence/issues/77#issuecomment-7095039193) البديل B، وهو مسار Dense اختياري داخل Ordering يجمع الخانة المؤقتة والإزاحة الاتجاهية. يمكن إعادة استخدام هذا التسلسل تحت شروط أهلية محددة. يظل إثبات صلاحية الخانة والأقفال والقيود من مسؤولية المجال، وتتحمل المكتبة تكلفة ضمان أهلية المخطط وسلامة حيازة المعاملة والتوافق والاختبار. لم تثبت الأدلة المفحوصة منفعة مشتركة تبرر تلك التكلفة في هذا الإصدار.

## النتائج وإعادة النظر

- تتطلب أي معالجة في مستودع مستهلك حاجة مثبتة وتفويضًا مستقلًا وأدلة اختبار لمخططه ومسارات كتابته.
- يمكن إعادة دراسة B مستقبلًا عند توثيق حاجة مشتركة للمستهلكين، وتحديد مخطط أهلية قابل للتحقق يشمل DDL والأقفال والتزام جميع الكتّاب، وإثبات منفعة عملية تفوق كلفة الصيانة والسلامة.
- يتطلب أي عقد عام مستقبلي موافقة معمارية مستقلة من المالك، وإثباتًا قابلًا للإعادة على MySQL وMariaDB يشمل حالات الهارنس الاختيارية على MySQL 8.0.16، واختبارات أهلية المخطط وسلامة المعاملة والتوافق.
