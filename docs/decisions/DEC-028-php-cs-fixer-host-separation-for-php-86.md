# DEC-028 — فصل بيئة تشغيل PHP-CS-Fixer عن مصفوفة توافق PHP 8.6

- **Decision ID:** `DEC-028`
- **Status:** `ACTIVE`
- **Date:** 2026-11-25
- **Decision Authority:** مالك المشروع في [#94](https://github.com/Maatify-ltd/php-persistence/issues/94#issuecomment-7167804049)
- **Scope / Concern:** أداة PHP-CS-Fixer وحدها داخل CI لوحدة WU-PHP86: أين يعمل `format:check` الكامل وما الفحص المطلوب على PHP 8.6. لا يشمل أدوات تطوير أخرى ولا مستودعات أخرى.
- **Canonical Contract / Current Owner:** [CI_AND_INTEGRATION.md §3.1](../architecture/CI_AND_INTEGRATION.md#31-code-style-on-php-86) و`.github/workflows/ci.yml`
- **Supersedes:** —
- **Superseded By:** —

## السياق

صدر PHP 8.6.0 Stable في 2026-11-19. أحدث إصدار Stable من PHP-CS-Fixer وقت العمل، `3.95.32`، يرفض التشغيل على PHP 8.6 لأنه يدعم الصياغة حتى 8.5 ما لم يُستعمل خيار `--allow-unsupported-php-version` أو `Config::setUnsupportedPhpVersionAllowed(true)`، وهو خيار يصفه المصنّف نفسه بأنه قد يكون غير مستقر. لم يوجد إصدار أحدث ولا دعم لـ8.6 في فرع التطوير حينها. تأهيل PHP 8.6 مطلوب في [#94](https://github.com/Maatify-ltd/php-persistence/issues/94).

## القرار

1. يبقى `composer format:check` الكامل، بالإعداد والملفات والقواعد نفسها، يعمل في CI على بيئتي PHP 8.4 و8.5 المدعومتين من الأداة، في خلايا Compat بوضعي `latest` و`lowest`.
2. على PHP 8.6 يعمل `php scripts/check-per-cs-3-1.php` فحصًا إلزاميًا مستقلًا. لا يُستدعى PHP-CS-Fixer على 8.6.
3. لا `--allow-unsupported-php-version` ولا `Config::setUnsupportedPhpVersionAllowed(true)` ولا متغير بيئة مكافئ، ولا تحويل الفشل إلى تحذير أو تخطٍّ أو `continue-on-error`.
4. الأمر المحلي `composer format:check` يحتفظ بمعناه الكامل ولا يُقلَّص نطاق الملفات ولا القواعد.

## Rationale

حكم الـstyle يخص نص المصدر لا إصدار PHP الذي يشغّله، لذلك يكفي تشغيل الأداة المدعومة على بيئاتها المدعومة مع بقاء فحص 3.1 المستقل على 8.6. يحافظ هذا على صرامة القواعد بلا تشغيل أداة غير مؤهلة، وبلا انتظار إصدار جديد منها.

## Consequences

- لا يُدّعى أن PHP-CS-Fixer عمل على PHP 8.6. مخالفة style التي لا تخص قواعد 3.1 تكتشفها خلايا 8.4 و8.5 وتُفشل بوابة CI العامة؛ ولا يكتشفها شيء على 8.6 وحده.
- الإثبات: controls على PR مغلق [#96](https://github.com/Maatify-ltd/php-persistence/pull/96) أظهرت فشل `format:check` وفشل فحص `clone` على 8.6 وفشل بوابة CI.
- **شرط المراجعة:** عند صدور إصدار Stable من PHP-CS-Fixer يعمل رسميًا على PHP 8.6 دون override، يُراجَع هذا التوزيع بقرار يحل محل هذا السجل، ويُشغَّل `format:check` الكامل على 8.6.
- ليس إعفاءً من متطلب معياري، ولا تعديلًا للمعايير المثبتة، ولا لـ[DEC-013](./DEC-013-test-only-database-version-boundary.md) إذ لم تتغير خلايا قواعد البيانات المحددة فيه.
