# معيار تطور العقود العامة المتوافق: Replacement وDeprecation وRetirement

## بيانات المعيار

- **Standard ID:** `std-public-contract-evolution`
- **Standard Version:** `1.0.0`
- **Standard Version Format:** `MAJOR.MINOR.PATCH`
- **Version Status:** `MUTABLE CANDIDATE` — بدأ `1.0.0` بوصفه `OWNER-APPROVED BOOTSTRAP VERSION` بتاريخ 2026-11-21 وفق `STANDARD_VERSIONING_POLICY_AR.md` §3.2. اعتمد المالك بقاء القيمة في 2026-11-24 بعد تأكيد عدم وجود Freeze لاحقة.
- **Canonical ownership:** `Compatible Public/Consumer Contract Evolution Lifecycle: Compatibility Classification, Replacement Eligibility, Controlled Deprecation, No-New-Legacy-Use, Tracked Retirement Obligation, Package Deprecation Registry, Retirement Gate, and Unsafe-Legacy Exception Path`
- **اللغة المعتمدة:** العربية.

يمنع هذا المعيار طرفي مشكلة واحدة: Breaking Release متكرر يفرض ترحيل المستهلكين دون ضرورة، وLegacy/Deprecated Contracts تتراكم بلا نهاية. القرار الدائم `ACTIVE` بشأن تعايش البديل مع القديم هو القرار المركزي `DEC-054` في `Maatify-ltd/php-engineering-standards` (وليس قرار مستهلك محلي).

## 1. الانطباق (Applicability)

تنطبق هذه Standard على أي Scope يملك **Published Public/Consumer Contract**: عقدًا مدعومًا جرى **نشره أو إتاحته فعلًا** للاستخدام خارج الكود الداخلي للـartifact المالك له، سواء عبر إصدار منشور أو عبر إتاحة حية معروضة أو موثقة للاستهلاك. يشمل ذلك بحسب الواقع الفعلي: مكتبة أو Package، وBase Module Artifact، وHost يعرض HTTP/REST/Schema/CLI contract.

**الأهلية تُحسم بالنشر أو الإتاحة، لا باكتشاف المستهلكين.** يُحمى العقد المنشور أو المتاح والمدعوم حتى لو كانت قائمة المستهلكين المعروفين `none` أو `unknown` أو لم تُكتشف بعد. لا يُشترط إثبات وجود مستهلك لتطبيق المعيار، ولا تُسقط الحمايةَ قدرةُ المالك على إجبار مستهلك بعينه على الترحيل: مستهلك في مستودع آخر تابع للجهة نفسها مستهلك خارجي لهذا الغرض. الاستعمال الداخلي غير المنشور وغير المتاح لا يخلق نشرًا وهميًا.

لا تنطبق عندما يكون الـScope كله بلا Published Public/Consumer Contract: كود داخلي داخل حدود الـartifact لا يُتاح لشيء خارجه، أو Scope غير منشور وغير متاح، أو Governance-only Scope. هذا حكم applicability حتمي في Stage 2 من `STANDARDS_ADOPTION_STANDARD_AR.md` وليس Exception. لا توسع الـProfile activation هذا الانطباق ولا تضيقه (DEC-013).

عند انطباقها على Scope فهي تحكم **العقد المنشور أو المتاح فقط** في ذلك الـScope، لا بقية الكود. المصطلح `Consumer` في هذا المعيار يعني أي كود أو نظام خارج حدود الـartifact يستعمل العقد، وليس شرط أهلية.

## 2. الملكية وحدود المعيار

تملك هذه Standard القواعد المذكورة في Canonical ownership أعلاه. لا تملك، ويجب ألا تكرر:

| الموضوع | المالك |
|---|---|
| متى تكون Major release مبررة ومنع Major لمجرد cleanup/refactor/naming | `std-library-presentation` §14.6 |
| ترحيل source topology والـDeferred Structural Obligation | `std-package-building` (Compatibility and Legacy Placement) |
| Published Pre-Stable Identity (اسم الحزمة/المستودع) | `std-composer-package` §7 |
| دليل Consumer Verification وحماية السلوك الحالي | `std-testing` §3.4 و§4.1 |
| دلالة CHANGELOG وRelease Notes وPackage Reference والنشر والـtag | `std-library-presentation` |
| Standard/Profile Versioning لهذه المعايير نفسها | `STANDARD_VERSIONING_POLICY_AR.md` و`STANDARDS_ADOPTION_STANDARD_AR.md` §5 |
| تسجيل القرارات الدائمة واستبدالها | `std-decision-governance` |
| استثناء مستودع معتمِد من Standard | `STANDARDS_ADOPTION_STANDARD_AR.md` §16.4 (`Explicit Exceptions/Overrides`) |

**Deferred Structural Obligation** نتيجة تلقائية لهجرة topology مثبتة الكسر، و**Retirement Obligation** في هذا المعيار نتيجة Deprecation لعقد منشور. لا يُستخدم أحد المصطلحين مرادفًا للآخر.

الكلمات `MUST` و`MUST NOT` و`SHOULD` و`MAY` تُفسَّر وفق RFC 2119.

## 3. حدود العقد وحالات النشر

### 3.1 ما يدخل في Public/Consumer Contract

يدخل ما يعتمد عليه مستهلك خارجي بحسب نوع الـartifact، ومنه: FQCN والـnamespaces والـimports؛ signatures والـdefaults وأسماء المعاملات (named arguments)؛ constructors والـinterfaces ونقاط الامتداد والوراثة؛ الاستثناءات المرمية وأنواعها؛ DTO/serialization/JSON؛ أنواع الإرجاع وقيمها؛ السلوك الملحوظ والـlifecycle والملكية والترتيب؛ وHTTP/API/schema عند الانطباق.

لا يُحكم بالتوافق أو الكسر من شكل التعديل وحده (مثلًا "معامل اختياري" أو "إعادة تسمية").

### 3.2 حالة النشر والإتاحة

| الحالة | المعنى | أثر هذه Standard |
|---|---|---|
| `PUBLISHED_STABLE` | عقد ورد في إصدار Stable منشور | تنطبق القواعد كاملة، ولو لم يُعرف أي مستهلك له |
| `PUBLISHED_PRE_STABLE` | عقد ورد في RC/Pre-Stable منشور | تنطبق عليه Published Public/Consumer Contract أيضًا دون اشتراط مستهلك معروف: تصنيف التغيير (§4) وتسجيله وتوثيقه. أما **حدود الضمان الفعلي** فهي ما يعلنه مالك الإصدار للـPre-Stable، ولا تُستنتج منه ضمانات Stable ولا يُمنع به كل تغيير |
| `LIVE_UNVERSIONED` | عقد HTTP/CLI/Schema أو غيره **ثبتت إتاحته** لمستهلكين (معروض أو منشور أو موثق كمتاح) دون Tag أو SemVer | يُعامل كمنشور: تنطبق القواعد كاملة؛ ممنوع كسره لغياب إصدار |
| `UNPUBLISHED` | عقد على الفرع الرئيسي أو في Unreleased **ثبت** أنه غير منشور وغير متاح خارج الكود الداخلي | لا ادعاء بوصوله لأي مستهلك؛ يجوز تغييره وفق قواعد الـScope دون Deprecation |
| `EXPOSURE_UNVERIFIED` | لم يُثبت بعد هل العقد متاح للاستخدام الخارجي أم لا | لا يُدّعى `LIVE_UNVERSIONED` ولا `UNPUBLISHED`، ويُعلَّق أي كسر قائم على افتراض عدم الاستهلاك حتى يثبت نطاق الإتاحة |

يُحسم نطاق الإتاحة بما هو معروف فعلًا عن العقد (الإصدار المنشور، مسار معروض في بيئة تشغيل، وثيقة عقد منشورة، تجهيز أو نشر مثبت) لا باختراع مستهلك ولا باستنتاجه من الشك. التحقق من الإتاحة وحده لا يوجب سجل Deprecations؛ شرط السجل في §8.1. `EXPOSURE_UNVERIFIED` تتبع منطق `UNVERIFIED` في §4.1: fail-closed للكسر دون أن تصير ادعاء إتاحة. يجب أن يميز كل سجل (§8) بين هذه الحالات. لا يجوز ادعاء أن Deprecation وصلت للمستهلكين قبل نشرها فعلًا، ولا تغيير عقود المستهلكين التاريخيين بأثر رجعي، ولا يمكن منع تثبيت نسخة منشورة سابقًا.

### 3.3 تمييز عقود المعايير عن عقود الـruntime

قرارات Standard/Profile Version هي عقود حوكمة منفصلة عن SemVer إصدار مكتبة PHP. لا يحول هذا المعيار Standards Upgrade إلى إلزام صامت على مستهلك يملك exact pins، ولا يستخدم Package SemVer بدل Standard/Profile Version.

## 4. تصنيف التوافق (Compatibility Classification)

لكل تغيير يمس Published Public/Consumer Contract يجب تصنيف أثره قبل اختيار أي مسار:

| التصنيف | المعنى |
|---|---|
| `NON_BREAKING` | ثبت بالدليل أن كل استخدام ملتزم سابقًا ما زال يعمل بنفس الضمانات المنشورة |
| `BREAKING` | ثبت بالدليل أن استخدامًا ملتزمًا سابقًا ينكسر أو تتغير ضماناته المنشورة |
| `UNVERIFIED` | لم يُثبت أي من الأمرين |

### 4.1 `UNVERIFIED` fail-closed

`UNVERIFIED` ليست `BREAKING` ولا `NON_BREAKING`، ولا تُحوَّل تلقائيًا إلى أيٍّ منهما:

- `MUST NOT` ادعاء التوافق أو إدماج التغيير على أنه متوافق ما دامت `UNVERIFIED`.
- `MUST NOT` اعتبارها دليلًا على كسر، ولا مبررًا لـDeprecation أو Replacement أو Major.
- `MUST` استكمال الفحص حتى ينتهي إلى `NON_BREAKING` أو `BREAKING`، أو تُسجَّل نقطة blocking يحسمها صاحب السلطة.

### 4.2 قاعدة الدليل

يُثبت التصنيف بفحص ما ينطبق من: named arguments؛ الوراثة وتنفيذ الـinterfaces من المستهلك (إضافة methods أو معاملات مطلوبة على نقطة امتداد يكسر المنفذين)؛ الاستثناءات ونتائج الأخطاء؛ الإرجاع والـserialization؛ السلوك الملحوظ وlifecycle/ownership/ordering؛ وHTTP/schema. تُجمع الأدلة بالاختبار أو static analysis أو قراءة العقد المنشور، وفق `std-testing`.

مثال محايد: إضافة معامل اختياري `?string $label = null` إلى method عامة هي `NON_BREAKING` فقط إذا ثبت أنها لا تكسر named arguments القائمة ولا implementers لـinterface تعلن تلك method ولا الـoverrides ولا السلوك عند `null`. إن لم يثبت ذلك بقيت `UNVERIFIED`.

### 4.3 Bug fix مقابل تغيير ضمان منشور

الإصلاح الذي يعيد الالتزام بضمان منشور فعلي ليس تغيير عقد. أما تغيير سلوك قد يعتمد عليه مستهلك (حتى لو سُمي إصلاحًا) فيُصنَّف كتغيير على العقد. لا يُخلط الاثنان في تغيير واحد دون فصل واضح؛ وحالة السلوك غير الآمن موضع §10.

### 4.4 الضرورة

تصنيف `BREAKING` لا يكفي وحده لبدء Replacement. لا يبدأ Replacement إلا إذا ثبت أيضًا أن تحقيق المطلوب بصورة متوافقة غير ممكن: لا additive option أو capability، ولا توسع متوافق، ولا adapter على العقد نفسه. يسمى ذلك `BREAKING_REQUIRED` في سجلات هذا المعيار.

## 5. أهلية Replacement

بالترتيب:

1. `NON_BREAKING` على العقد نفسه (توسع/خيار إضافي): لا Deprecation ولا Replacement ولا Major.
2. إن كان `BREAKING_REQUIRED` وأمكن التعايش الآمن: يُقدَّم **Contract بديل مستقل additive** (Class أو Interface أو Method أو API بحسب موضع الكسر) دون تغيير توقيع القديم أو سلوكه.
3. **القدرات المستقلة ليست Replacement:** وجود API جديدة بجانب القديمة، أو API بضمان مختلف مشروع، لا يوجب Deprecation؛ وتظل الاثنتان Supported إن كانت لكل منهما ضمانات مشروعة مختلفة.
4. alias أو إعادة تصدير وحدهما ليستا استراتيجية ترحيل.
5. **Unavoidable Breaking Release (fallback):** إذا ثبت بالدليل أن التعايش additive الآمن مستحيل (مثل schema أو protocol لا يحتمل نسختين متزامنتين) فيبقى Replacement-first هو الأصل، لكن يجوز المسار الكاسر عند اجتماع: إثبات استحالة التعايش الآمن واستبعاد البدائل المتوافقة؛ مراجعة أثر على المستهلكين المعروفين؛ مسار انتقال واضح؛ وموافقة المالك. يُحدَّد الإصدار أو حدّ النشر وفق مالكه (`std-library-presentation` §14.6 للحزم، ووثائق عقد الـHost لعقد الـHost). لا Major لمجرد تنظيف Deprecated ولا لمجرد تحسين.
6. لا تُسمَّى API Replacement ولا تُعلَّم القديمة `deprecated` لمجرد تحسين تسمية أو تنظيم أو حداثة تصميم.

## 6. Controlled Deprecation

عند اعتماد بديل لـ`BREAKING_REQUIRED` فقط:

- `MUST` وضع علامة Deprecated بالآلية المناسبة لتقنية الـartifact: `@deprecated` في الـDocBlock لـPHP، وحقل/ترويسة/وثيقة العقد لـHTTP/Schema، مع **مرجع البديل** وسبب الكسر.
- `MUST` تسجيل Retirement Obligation وحالة الإتاحة وإصدار أو حدّ إتاحة البديل الفعلي وفق §8، وإعلان Deprecation بآلية التوثيق المنطبقة على نوع الـartifact (§11.1).
- `MUST` أن يبقى القديم قابلًا للاستدعاء بنفس ضماناته المنشورة طوال فترة الدعم: لا breaking behavior صامت في المسار القديم، ولا نقل Behavior جديد إليه.
- `MUST NOT` فرض ترحيل فوري على المستهلكين.
- `MAY` استمرار إصلاحات السلامة والصحة الضرورية للقديم؛ وإن غيّر إصلاح ضمانًا منشورًا طُبّق §4.3 و§10.
- الموعد المخطَّط للإزالة يفيد في التخطيط. تحدد §9 الاستحقاق من أهلية حد الإصدار للعقد ومن الضمانات الملزمة المثبتة.

## 7. عدم توسيع الاعتماد على القديم

بعد اعتماد البديل يكون **البديل هو Current Design**، ويصبح القديم **Compatibility Surface مؤقتة محدودة وموثقة** وليست نمطًا منافسًا (انظر `DEC-054` وقاعدة `std-decision-governance` §8).

- `MUST NOT` إضافة استخدام جديد للقديم أو قدرات توسّع الاعتماد عليه في العمل الجديد.
- `MAY` استثناء انتقالي محدود ومسبب ومكتوب (ما الذي سيستخدم القديم ولماذا ومتى ينتهي).
- لا يُلزم المعيار بأداة واحدة للإنفاذ؛ static analysis أو lint أو مراجعة مباشرة كلها مقبولة إذا كانت أدق.
- لا تُنشأ قرارات `ACTIVE` متنافسة؛ وجود Legacy compatibility بعد اعتماد البديل لا يعني قرارين `ACTIVE`.

## 8. Retirement Obligation قابلة للتتبع

كل Deprecation تقع ضمن هذا المعيار تنشئ التزام انتقال وإزالة قابلًا للتدقيق. تملك الحزمة أو الـBase Module Artifact سجلها وفق §8.1. أما عقد الـHost غير الحزمي فيظل سجله في موضع الوثائق القائم للعقد أو في CHANGELOG أو ADR أو GitHub Issue عند انطباقه، وفق §11.1. يضم السجل القائم العقدين، وحالة إتاحة البديل وإصدارها أو تاريخها، والكسر واستبعاد البدائل المتوافقة، والمستهلكين المعروفين وحالة ترحيلهم أو عدم معرفتهم، ودليل الترحيل والـintentional semantic deltas وغياب regressions غير المقصودة، وحد المراجعة أو الإزالة.

### 8.1 سجل الحزمة في الـArtifact Root

- `MUST` على كل standalone reusable Composer Package أو extractable Base Module Artifact إنشاء ملف Markdown واحد باسم `DEPRECATIONS.md` في الـ**Artifact Root** المالك للعقد، مع أول Deprecation تقع ضمن هذا المعيار أو عند وجود Retirement Obligations حالية أو تاريخية موثقة. يبقى الملف إذا انتهت الالتزامات المفتوحة وبقي تاريخها. لا يُطلب ملف فارغ من artifact بلا Deprecations أو تاريخ التزامات.
- يملك الـBase Artifact الملف داخل جذره حتى وهو مضمّن في Host، ويصحبه الملف عند الاستخراج. إذا احتوى المستودع أكثر من artifact، يملك كل منها سجله الخاص عند تحقق الشرط. لا يقوم سجل الـHost أو الحزمة الأخرى مقامه.
- `MUST` تحديث السجل في نفس الـbounded change الذي يضيف Deprecation أو يغيّرها أو يسحبها، أو يغيّر حالة الإزالة أو التأجيل أو معلومة إتاحة ذات صلة. تُسجَّل كل هوية عقد مرة واحدة بوضوح ضمن نطاقها (مثل FQCN مع member/signature، أو معرّف عقد مستقر)، ويُحفظ تاريخ انتقالاتها في نفس السجل. وجود `@deprecated` أو CHANGELOG أو Issue وحدها لا يفي بهذا الالتزام. يجب أن يظهر الجرد والحقول الحاكمة في الملف نفسه، ويمكن ربط الأدلة التفصيلية دون نسخها. لا سجل موازٍ لكل class أو إصدار أو داخل `docs/` أو Issue، ولا سجل مركزي لعدة artifacts.

### 8.2 حقول السجل والدليل

تُسجَّل الحقول التالية في `DEPRECATIONS.md` لكل Retirement Obligation في الحزمة أو الـBase Module Artifact.

1. **العقد والنطاق.** معرّف العقد القديم والبديل المعتمد. عند غياب بديل في مسار استثنائي مسموح به أصلًا (§5.5 أو §10)، يُسجَّل المبرر ومرجع قرار المالك. غياب البديل لا يمنح أهلية Deprecation جديدة.
2. **سبب الكسر والهجرة.** أثر الكسر المثبت، ودليل استبعاد البدائل المتوافقة، وطريقة الانتقال أو رابطها. تُذكر الـ**intentional semantic deltas** المعلنة ويُربط الدليل على صحة البديل وغياب regressions غير المقصودة، بحسب ما ينطبق.
3. **بدء الإهمال والإتاحة.** الإصدار أو حد الإتاحة الذي بدأ فيه الإهمال وتاريخه الفعلي حيث ثبت، وحالة إتاحة كل من القديم والبديل وفق §3.2 وإصداره أو حد إتاحته الفعلي. قبل تخصيص تغيير استخدم `Unreleased`، وبعد تخصيصه اذكر الـexact target وحالة عدم نشره. استخدم `Published` فقط بعد إثبات النشر الفعلي للإصدار الذي يخصه الادعاء وفق §11.1. افصل وقائع النشر السابقة عن حالة الهدف الحالي. عند نقص الدليل اذكر ما لم يثبت؛ لا تُختلق إتاحة أو نسخة أو تاريخ أو ضمان.
4. **المستهلكون.** المستهلكون المعروفون وحالة ترحيل كل منهم، بما فيهم مستهلكو المستودعات الأخرى داخل الجهة نفسها. إذا تعذّر نشر الهوية، `MUST` استخدام معرّف محايد ثابت وغير كاشف لكل مستهلك معلوم مع حالة ترحيله. استخدم `unknown` فقط لما يتعذر التحقق منه و`none` عند ثبوت عدم وجود مستهلكين ضمن نطاق الفحص، مع مرجع آمن للدليل أو بيان حدود المعرفة بحسب الحالة. سرية الهوية لا تحوّل مستهلكًا معلومًا إلى `unknown`، و`unknown` لا يثبت عدم الاستخدام.
5. **حالة الالتزام والسلطة.** حالة الالتزام، مرجع القرار/الاعتماد المنطبق وأدلته، وحد المراجعة أو الإزالة المتوقع. عند الإزالة، سجّل الحد الفعلي الذي نُفذت عنده وأدلتها وحالة نشره وفق البند 3. عند التأجيل، سجّل المبرر ومرجع اعتماد المالك وحد المراجعة التالي. حد المراجعة موعد للتقييم، ولا يُجيز الإزالة بذاته.

ينطبق شرط سلامة النشر على كل الحقول والروابط. `MUST NOT` تضمين أسماء عملاء أو مستودعات خاصة أو روابط داخلية أو أسرار أو تفاصيل سرية غير مصرح بنشرها. تُحفظ مطابقة المعرفات للهويات والأدلة الحساسة في مصدر أدلة مقيد الوصول يمكن للمالك أو المراجع المخوَّل التحقق منه. يستخدم الملف مرجع دليل محايدًا غير كاشف يستطيع المراجع مطابقته في ذلك المصدر، أو رابطًا آمنًا إلى ملخص صالح للنشر. تظل الحقول الحاكمة وحالات الترحيل قابلة للقراءة في `DEPRECATIONS.md`؛ مصدر الأدلة لا ينشئ سجل التزامات موازيًا. يُتحقق من ذلك على التوزيع الفعلي وفق §11.2.

### 8.3 حالات الالتزام في سجل الحزمة

| الحالة | الدلالة |
|---|---|
| `DEPRECATED` | التزام مفتوح والعقد القديم باقٍ وفق ضماناته المدعومة. |
| `REMOVAL_APPROVED` | التزام مفتوح بإزالة أجازها المالك عند حد مؤهل؛ تسجَّل نتيجة التنفيذ عند تحققها. |
| `DEFERRED` | التزام مفتوح أُجّل استثنائيًا بقرار مسبب من المالك إلى حد مراجعة محدد. |
| `REMOVED` | الإزالة منفَّذة ومثبتة على الإتاحة الفعلية أو شجرة الهدف المحددة. تبيّن حقول الإتاحة أين تحقق التنفيذ وما إذا كان الهدف قد نُشر. |
| `WITHDRAWN` | سُحب إعلان الإهمال بقرار مسبب ومسجَّل، مع تحديث العلامة ووثائق العقد وحالة دعمه. |

تبقى حالة الالتزام منفصلة عن إتاحة القديم والبديل ونشر هدف الإزالة. تظل `REMOVAL_APPROVED` مفتوحة حتى تُثبت الإزالة أو يعتمد التأجيل. لا يثبت `REMOVED` في candidate tree اختفاء العقد من النسخ المتاحة، وتظل إتاحته السابقة واقعة تاريخية. إذا أُلغي هدف إزالة غير منشور أو استُبدل بهدف لا ينفذ الإزالة، `MUST` إعادة الالتزام إلى حالته المفتوحة المنطبقة في السجل مع حفظ نتيجة الهدف السابق. تُحفظ سجلات `REMOVED` و`WITHDRAWN` والقرارات التي سبقتها. أثناء بقاء العقد تحت الدعم تُطبّق §6 و§7.

### 8.4 مثال محايد مصغّر

المثال افتراضي. الروابط تشير إلى ملخصات صالحة للنشر في الـartifact، والإصدارات المنشورة وقائع مفترضة داخل المثال فقط.

```markdown
# Deprecations

## `Example\Paging\Cursor::resume(int $offset): Page` — DEPRECATED
- Scope: public PHP continuation API. Approved replacement: `resumeSnapshot(SnapshotToken): Page`.
- Requirement: every supported continuation after the transition must resume
  the original query snapshot even when rows change between requests. The
  published offset has no query or snapshot identity. An optional token leaves
  existing calls unable to meet the guarantee. Two snapshots can require
  different next rows for the same offset, so an adapter cannot recover the
  original snapshot. Requiring the token breaks those calls.
- The new entry point can coexist during migration. Offset continuation keeps
  its published behavior during support, then exits as a supported continuation
  path. A separate `countMatches(Query): int` capability answers a different
  question and creates no deprecation.
- Deprecation starts: Unreleased. Old API: PUBLISHED_STABLE at 1.4.0.
  Replacement: UNPUBLISHED, Unreleased.
- Migration: keep the snapshot token returned by the first page. The new path
  resumes the original snapshot where offset continuation could reflect later
  row changes. [Migration and compatibility evidence](docs/snapshot-cursor.md)
- Consumers: unknown; external migration has not been verified.
- [Replacement approval](docs/decisions/snapshot-cursor.md). Review: first eligible
  Owner-approved breaking boundary; no removal target allocated.

## `Example\Reservations\Reservation::confirm(string $id): void` — DEFERRED
- Scope: public PHP confirmation API. Approved replacement: `confirmRevision(string $id, Revision $observed): void`.
- Requirement: after the transition, every supported confirmation must apply
  only to the revision the caller saw. Two callers can supply the same ID
  after seeing different revisions. A default or lookup can supply the
  current revision only. A required revision breaks existing calls.
  Parallel methods support migration, but
  retaining unversioned confirmation as a supported path would allow the
  same operation without the required guarantee.
- Old API: PUBLISHED_STABLE since 1.3.0 and still available in 1.4.0.
  Deprecation and replacement: Published at 1.4.0. [Publication evidence](docs/releases/1.4.0.md)
- Migration: pass the revision returned by the read call. The new API rejects a
  stale revision with `StaleRevision`. The old API retains its published behavior
  during support. [Migration and verification](docs/reservation-revision.md)
- Consumer C-01: known, migration in progress. Evidence EV-01 is an opaque
  reference to the restricted source available to the authorized reviewer.
- Planning target: 3.0.0. Due at the independently justified, Owner-approved
  2.0.0 target (not published), which permits removal of this contract.
- [Deferral approval](docs/decisions/reservation-revision.md): the Owner retained
  the old API in 2.0.0 for a documented staged migration of C-01. Next review:
  first eligible breaking boundary after 2.0.0.
```

لا يجتاز هدف `2.0.0` البوابة إذا بقي العقد لمجرد أن الموعد المسجَّل `3.0.0`. في حالة الإزالة بدل التأجيل يُسجَّل `REMOVED` مع دليل الإزالة من شجرة `2.0.0` المحددة، ويظل الهدف غير منشور. يُحفظ تاريخ إتاحة القديم وبدء Deprecation في `1.4.0`.

### 8.5 مصالحة جرد الحزمة عند Adoption لاحقة

عند Adoption أو Upgrade مخوَّلة لاحقًا ينطبق معها §8.1، يُراجع الـartifact الالتزامات الحالية والتاريخية من العلامات والوثائق وسجل الإصدارات والقرارات المتاحة ويصالحها في الملف. تُصرَّح الحقول ذات الأدلة الناقصة بوصفها غير مثبتة، ويُستكمل ما يلزم قبل اجتياز بوابة تعتمد عليها. لا تُختلق تواريخ أو قرارات، ولا تُنسب إلى النسخ التاريخية compliance بأثر رجعي. يحكم `STANDARDS_ADOPTION_STANDARD_AR.md` سلطة ومسار تلك العملية.

## 9. Retirement Gate

- `MUST NOT` حذف العقد المنشور أو الحيّ أو كسره لمجرد وجود `@deprecated` أو بلوغ إصدار متوقع. لا تجوز الإزالة ضمن Stable Minor أو Patch. يبقى مسار الخطر الأمني أو فساد البيانات في §10 خاضعًا لدليله وقرار المالك.
- عند **كل Owner-approved release boundary مؤهل للكسر**، `MUST` مراجعة **كل** الالتزامات المفتوحة وتصنيف أهلية الحد لكل عقد على حدة. للحزمة المستقرة يكون الحد Major مبررة وفق `std-library-presentation` §14.6، وتتم المراجعة في سجل §8.1. يُستخدم للـHost غير الحزمي حد النشر المعتمد ومسار سجله القائم وفق §8 و§11.1. تُراجع أي إزالة مسجَّلة على هدف غير منشور مقابل الـexact target قيد المراجعة.
- يصبح كل التزام مفتوح **مستحقًا عند أول حد معتمد يسمح فعليًا بإزالة عقده**، وعند كل حد مؤهل تالٍ ما دام مفتوحًا. يجب توثيق الدليل الموضوعي لأي حكم بعدم الأهلية، مثل ضمان توافق ملزم ما زال نافذًا ويمنع إزالة ذلك العقد عند هذا الحد. التاريخ أو الإصدار المخطَّط، ونقص دليل الهجرة، واستمرار استخدام القديم، وحالة `REMOVAL_APPROVED` لا تثبت عدم الأهلية. يُراجع التأجيل السابق عند مراجعته المسجَّلة أيضًا، ويجب أن يغطي اعتماد المالك صراحة أي تأجيل عند الحد المستحق قيد المراجعة. تمر البوابة لكل مستحق بإحدى النتيجتين:
  1. **إزالة مثبتة** عند الحد المؤهل، مع دليل الترحيل والـintentional semantic deltas، والتحقق من التغيير في الهدف نفسه وتسجيل الإزالة وحالة إتاحتها وفق §8 و§11.
  2. **تأجيل استثنائي** بمبرر موثق واعتماد مالك صريح وحد مراجعة تالٍ. يظل الالتزام مفتوحًا والقديم محفوظًا وفق §6 و§7.
- لا تجتاز البوابة إذا غاب التزام مفتوح عن المراجعة، أو تعذَّر حسم أهلية الحد لعقده، أو بقي مآل التزام مستحق غير مثبت، أو اعتمد التأجيل على ترحيل تلقائي إلى حد لاحق. لا Major لمجرد تنظيف Deprecated.
- يُحسم كسر `PUBLISHED_PRE_STABLE` وفق ضمان التجربة المعلن وأهلية الحد ودليل التصنيف (§3–§5)، مع إعلان التغيير صراحة. يظل first-Stable gate المنطبق بمراحله وأدلته لدى `std-library-presentation` §14.1. أما `UNPUBLISHED` المثبت و`EXPOSURE_UNVERIFIED` فتتبِعان §3.2 و§4.1، ويُعامل `LIVE_UNVERSIONED` المثبت كعقد متاح وفق حد النشر الفعلي.

## 10. مسار الاستثناء: خطر أمني أو فساد بيانات

إذا كان الحفاظ على سلوك قديم يعني استمرار خطر أمني أو فساد بيانات لا يمكن احتواؤه بالتوافق:

- لا تُفرض المحافظة على سلوك غير آمن باعتبارها قيمة مطلقة.
- `MUST` توثيق الدليل والتأثير وحدود التغيير الأدنى في سجل قابل للتدقيق، وعرض قرار مسبب على المالك قبل تغيير ضمان منشور.
- يعلن التغيير ويوثق للمستهلكين صراحة بآلية التوثيق المنطبقة على نوع الـartifact (§11.1)، بما فيه من يتأثر وكيف يتحقق.
- لا يصبح هذا المسار سابقة تبيح تغيير ضمانات أخرى دون دليل.

## 11. التوثيق والإصدار والتوزيع بحسب نوع الـartifact

### 11.1 آلية التوثيق والإصدار

الحماية المعيارية للعقد الخارجي واحدة لكل الأنواع (§4–§10). أما آلية تنفيذها فتتبع نوع الـartifact والمعايير المنطبقة عليه فعلًا:

| نوع الـartifact | التوثيق والإعلان | الإصدار/حد النشر |
|---|---|---|
| Composer Package أو Base Module Artifact | `DEPRECATIONS.md` عند تحقق §8.1، مع CHANGELOG وPackage Reference وفق `std-library-presentation` | `std-library-presentation` §14.6 |
| Host يعرض HTTP/CLI/Schema حيًّا | وثيقة العقد المعتمدة للـHost (مثل `std-http-api-endpoint-documentation` عند انطباقها) وسجل التغيير الذي يعتمده الـHost | حدّ نشر يعتمده مالك الـHost؛ لا يُفترض SemVer ولا Composer |

لا تنطبق على Host قواعد CHANGELOG أو Composer أو SemVer الخاصة بالحزم إلا إذا كان الـHost يعتمد تلك المعايير فعلًا.

يبقى CHANGELOG تاريخ الإصدارات، ويظل Package Reference مرجع Public API والسلوك المدعوم، وتبقى `@deprecated` آلية التنبيه في PHP. يملك `DEPRECATIONS.md` جرد الالتزامات. يُكتشف من الوثائق الاستهلاكية القائمة وفق `std-library-presentation` §8.4 عند انطباقه.

### 11.2 سلامة Release والتوزيع

- عندما يكون `DEPRECATIONS.md` مطلوبًا وتُوزَّع حزمته عبر Composer، `MUST` أن يصل من الـArtifact Root مع الـartifact المطابق للـexact target، بما فيه تاريخ الالتزامات. تُفحص نتيجة التوزيع عبر Release Artifact Verification وPublished Artifact Verification المنطبقة تحت `std-ci-workflow`، وفق قيود `std-composer-package` §§19.4 و26. تُراجع حالة الهدف داخل الـartifact في سياق تأهيله. تُسجَّل حقائق النشر التي ثبتت لاحقًا في السجل الحي عند الحاجة، مع حفظ الـtags والـarchives المنشورة.
- `MUST` أن تتحقق بوابات التوزيع نفسها على كل تمثيل استهلاكي منطبق للـexact target من سلامة نشر محتوى السجل وروابطه وفق §8.2. تُفحص الملفات الناتجة للتأكد من أن السجل وأي أدلة مضمَّنة أو مرتبطة داخله صالحة للنشر، وأن تفاصيل الهوية والأدلة المقيدة لم تدخل التوزيع. تُطابق المراجعة المخوَّلة المعرّفات وحالات الترحيل مع مصدر الأدلة المقيد. وجود الملف أو فحص شجرة المصدر وحده لا يكفي.
- عند الالتزام ببقاء السطح القديم: للحزم يُتحقق بأن الـpublic autoload المنتج ما زال يحمله وأن علامات Deprecated ومرجع البديل موجودة في الـartifact المنشور وأن قيود Composer لا تفرض ترحيلًا غير معلن، ودليل ذلك وفق `std-testing` §3.4 وCI المنطبق؛ ولعقد Host حيّ يُتحقق بأن المسار القديم ما زال يخدم بضماناته وأن Deprecation معلنة في وثيقة العقد المنشورة.
- لا يُسحب إصدار أو تشغيل سابق بأثر رجعي.
- الكسر الحقيقي يكون عند ضرورة مثبتة فقط، وفق مالك الإصدار في §11.1.

## 12. مصفوفة الانطباق

| الـScope | عقد خارجي منشور؟ | الأثر |
|---|---|---|
| Composer Package أو Base Module Artifact | نعم (Public API) | تنطبق القواعد على العقد المنشور، ويُطلب سجل الـArtifact Root عند تحقق §8.1 |
| Package/Artifact ثبت أن عقوده غير منشورة وغير متاحة خارج الكود الداخلي | لا | لا تنطبق |
| Package/Artifact منشور أو متاح ومستهلكوه غير معروفين | نعم | تنطبق كاملة (الأهلية بالنشر لا بالمستهلك) |
| إتاحة العقد غير مثبتة بعد | غير محسوم (`EXPOSURE_UNVERIFIED`) | لا ادعاء `UNPUBLISHED`؛ يُعلَّق الكسر حتى يثبت النطاق |
| Host يعرض HTTP/REST/Schema/CLI لمستهلكين خارجيين (حتى دون Tag أو SemVer، وحتى لو المستهلك تابع للجهة نفسها) بعد ثبوت إتاحته | نعم لذلك العقد (`LIVE_UNVERSIONED`) | تنطبق على ذلك العقد فقط، بآلية §11.1 |
| API داخلية بين وحدات artifact واحد | لا | لا تنطبق |
| Host أو مستودع بلا عقد خارجي منشور | لا | لا تنطبق؛ لا يُفرض Package Harness ولا Package-only behavior |
| مستودع Governance-only | لا | لا تنطبق |

لا يفرض هذا المعيار Consumer Verification Harness على Host؛ يبقى ذلك ملكية `std-testing`.

## 13. قائمة مراجعة (Review Checklist)

- [ ] صُنِّف كل تغيير على عقد منشور `NON_BREAKING` أو `BREAKING` أو `UNVERIFIED` بدليل، ولم تُعامل `UNVERIFIED` كتوافق ولا ككسر.
- [ ] لا Deprecation أو Replacement لتغيير `NON_BREAKING` أو لتحسين تسمية/تنظيم أو لمجرد وجود API جديدة.
- [ ] استبعدت البدائل المتوافقة بدليل قبل اعتماد Replacement (`BREAKING_REQUIRED`).
- [ ] القديم محفوظ بضماناته المنشورة، ولا تغيير صامت في مساره.
- [ ] علامة Deprecated + مرجع البديل + حالة Published/Live/Unreleased موثقة بآلية تناسب نوع الـartifact، ولا ادعاء وصول غير مثبت، ولا اعتبار عقد حيّ بلا SemVer غير منشور.
- [ ] لا استخدام جديد للقديم إلا باستثناء انتقالي مكتوب.
- [ ] لكل حزمة أو Base Artifact يحقق §8.1 ملف `DEPRECATIONS.md` واحد في جذره. محتواه مكتمل وفريد ومحدَّث في نفس الـbounded change، ويحفظ التاريخ. يستخدم الـHost غير الحزمي مساره القائم (§8 و§11.1).
- [ ] حالة الالتزام منفصلة عن حالة الإتاحة، و`Published` مؤيدة بدليل، ويُشار إلى الهدف المخصص قبل نشره بوصفه غير منشور.
- [ ] لا إزالة في Stable Minor/Patch خارج §10. روجعت كل الالتزامات المفتوحة عند كل حد مؤهل، وثبتت أسباب عدم الأهلية لكل عقد عند الاقتضاء. صُولح كل مستحق عند أول حد يسمح بإزالته وكل حد مؤهل تالٍ بإزالة مثبتة أو تأجيل صريح من المالك بحد مراجعة تالٍ (§9).
- [ ] السجل قابل للاكتشاف وموجود في التوزيع المطابق للهدف. تحققت البوابات القائمة من سلامة محتواه وروابطه والأدلة المضمَّنة، وتحقق المراجع المخوَّل من حالة ترحيل كل مستهلك معلوم دون كشف هويته الخاصة (§§8.2 و11.2).
- [ ] استثناء الخطر الأمني/الفساد موثق بدليل وقرار المالك.
- [ ] إن استحال التعايش additive فالمسار الكاسر مبرر بدليل ومراجعة أثر وموافقة المالك، دون Major لمجرد cleanup.
- [ ] دليل الترحيل يثبت صحة العقد الجديد والـdeltas المقصودة لا تطابقًا مطلقًا.
- [ ] لم تُنسخ قواعد Major Preservation أو Deferred Structural Obligation أو Consumer Testing؛ أُحيل إلى مالكيها.

## 14. سجل تغييرات المعيار

### `1.0.0`

- Bootstrap معتمد من المالك (2026-11-21): ملكية canonical جديدة لدورة تطور العقود العامة المتوافقة، دون Frozen Baseline سابقة ودون ادعاء Freeze أو Adoption أو Release.
- Issue #204: تصحيح موضع المستودع المركزي في إشارة `DEC-054` إلى `Maatify-ltd/php-engineering-standards`. `CLARIFICATION / NO_CONTRACT_CHANGE`؛ تبقى `1.0.0` Bootstrap بلا Version Transition ولا Freeze.
- Issue #209 (`DEC-056`): سجل الحزمة واشتراط مصالحة كل التزام عند بوابة الإزالة. `NORMATIVE / BREAKING_CONTRACT_CHANGE` (Major class). ثبّت المالك القيمة `1.0.0` بوصفها Mutable Candidate بتاريخ 2026-11-24 بعد حسم عدم وجود Frozen Baseline بعد الـBootstrap، وفق سياسة الإصدار §§3.3–3.4 و6. [اعتماد المالك](https://github.com/Maatify-ltd/php-engineering-standards/pull/210#issuecomment-7127402843)
