# DEC-026 — ملكية المكتبة لآليات UNIQUE-safe Ordering B1

- **Decision ID:** `DEC-026`
- **Status:** `ACTIVE`
- **Date:** 2026-11-24
- **Decision Authority:** مالك المشروع في [#39](https://github.com/Maatify-ltd/php-persistence/issues/39#issuecomment-7132127673)
- **Scope / Concern:** عقد Ordering B1 الاختياري ضمن WU-C3I [#89](https://github.com/Maatify-ltd/php-persistence/issues/89)
- **Supersedes:** [DEC-023](./DEC-023-unique-ordering-release-domain-ownership.md)
- **Canonical Contract / Current Owner:** [Package Reference §5.4.1](../../PHP_PERSISTENCE_PACKAGE_REFERENCE.md#541-protected-unique-safe-ordering-b1)
- **Superseded By:** —

## السياق

أثبتت WU-C1 أن الإزاحة الوسيطة يمكن أن تصطدم بقيد UNIQUE. اعتمد المالك بعد إعادة المراجعة الفنية عقدًا اختياريًا لمشاركة تخصيص موضع الإدراج والحركة والحذف مع إعادة التكثيف ضمن مخطط كثيف مؤهل.

## القرار

تملك Ordering آليات التخصيص والحركة وإعادة التكثيف على `PdoProtectedTransactionCheckout` مع حجز واحد وقفل anchor مشترك لجميع الكتّاب، وsavepoint محلي للحركة والحذف. ينفذ الحذف خطة مقيدة ومسجلة يختارها الـHost. يملك الـHost صلاحية العملية والـpayload والمخطط وشهادة النشر وإنهاء المعاملة. يطبق الحد على Global وScoped غير nullable مع InnoDB وقيود UNIQUE وFK المحددة في مرجع الحزمة. يحافظ العقد على الطرق المنشورة وملكية الحيازة والتسوية المعتمدة.

## النتائج

تعتمد السلامة على شهادة صحيحة والتزام الكتّاب ببروتوكول الـanchor. حالات المخطط ودورة الحياة خارج هذا النطاق تحتاج تأهيلًا مستقلًا. يُسجل الاستبدال مع التنفيذ في Draft PR واحدة، ويسري على `main` عند الدمج بتفويض المالك. يتوقف التسليم عند المراجعة الفنية المستقلة للتنفيذ.
