# معيار اعتماد وتوزيع معايير Maatify

## بيانات المعيار

- **Standard ID:** `std-standards-adoption`
- **Standard Version:** `5.0.2`
- **Standard Version Format:** `MAJOR.MINOR.PATCH`
- **اللغة المعتمدة:** العربية.
- **النطاق:** آلية اختيار المعايير وتثبيتها وتوزيعها وتفعيلها داخل المشاريع التابعة لمنظومة Maatify.
- **الهدف:** استبدال نسخ المستودع الكامل باعتماد انتقائي مثبت وقابل للتتبع، مع الحفاظ على المصدر المركزي وسلامة الروابط والترقية القابلة للمراجعة.

هذا الملف هو **المصدر الوحيد للحقيقة لآلية Adoption**. لا يملك قواعد هندسية تخص PHP أو Composer أو Modules أو CI أو Testing؛ تلك القواعد تظل مملوكة للـ Standards المشار إليها في ملفات Profiles.

## 1. المصدر المركزي وحدود الملكية

المستودع:

```text
Maatify-ltd/php-engineering-standards
```

هو المصدر المركزي الوحيد لتأليف المعايير وProfiles. المشروع التابع يحتفظ بنسخ محلية لأغراض الاعتماد والتثبيت، لكنه لا يجعل النسخة المحلية أو ملف الـ Manifest مصدرًا منافسًا للقواعد الأصلية.

يجب أن يملك كل موضوع معياري ملفًا واحدًا. المعنى الرسمي لـ`Profile` هو:

```text
Profile
=
versioned composition manifest
under standards/profiles/
```

يحدد Profile ما يجتمع وما ينطبق، ولا يعيد كتابة القواعد المملوكة للـ Standard. الملفات الموجودة تحت `standards/modules/*.md` هي Standards وليست Profiles. يجوز استخدام `profile` بحروف صغيرة لوصف configuration أو backend shape وصفيًا فقط عندما يكون السياق غير ملتبس ولا يمكن فهمه كـ Profile رسمي.

## 2. منع Full Repository Snapshot

يجب ألا يعتمد أي مشروع على نسخ مجلد:

```text
standards/
```

بالكامل كإجراء افتراضي أو احتياطي. لا يجوز استخدام نمط `all standards just in case`، ولا يجعل وجود Standard في المستودع المركزي تلك الـ Standard منطبقة على كل مشروع.

يجب أن يحتوي المشروع فقط على **Pinned Adoption Files**: الـ Pinned Adoption Control Set الإلزامية، والـ Pinned Applicable Standards Set الناتجة عن Profiles المفعلة وdependencies الخاصة بها، إضافة إلى Additional Standards المصرح بها صراحةً، و**Pinned Operational Assets Set** فقط عندما يختار المشروع اعتماد Operational Assets. غياب هذه المجموعة يعني أن المشروع لم يعتمد Operational Assets ويظل Adoption صحيحًا إذا استوفى بقية العقد. لا تدخل ملفات أخرى لمجرد وجودها في المستودع المركزي.

## 3. طبقات الاعتماد: Control Plane وApplicable Engineering Standards وOperational Assets

يستخدم كل consuming repository يعتمد هذا النظام طبقتين إلزاميتين من الملفات المنسوخة من upstream، وطبقة ثالثة اختيارية/مشروطة للـOperational Assets، وسجلًا محليًا للنتيجة. لا تجعل أي من هذه الطبقات Adoption Standard جزءًا من Required Standards الهندسية لأي Profile، ولا تحول Operational Assets إلى مصدر قواعد.

### A. Pinned Adoption Control Set

هذا الـ Control Set **إلزامي** لكل consuming repository يستخدم نظام Selective Adoption. يجب أن يحتوي، من upstream pinned commit، على:

```text
standards/STANDARDS_ADOPTION_STANDARD_AR.md
active Profile manifests
all inherited Profile manifests required to resolve those active Profiles
```

يجب ألا يحتوي Control Set على Profile غير مستخدمة أو غير موروثة من Profile مفعلة.

إذا كان الـ Active Profile هو:

```text
project-aware-slim-module
```

فإن Profile manifests المحلية المطلوبة هي:

```text
PROJECT_AWARE_SLIM_MODULE_PROFILE.md
SLIM_MODULE_PROFILE.md
BASE_MODULE_PROFILE.md
COMPOSER_PACKAGE_PROFILE.md
```

ولا يلزم نسخ:

```text
REPOSITORY_GOVERNANCE_PROFILE.md
```

إلا إذا كان مفعّلًا أيضًا أو دخل في inheritance مطلوبة.

وجود Adoption Standard وProfile manifests في Control Set لا يجعل Adoption Standard capability هندسية، ولا يضيفها إلى `Required Standards` لأي Profile.

### B. Pinned Applicable Standards Set

هو المجموعة النهائية من الـ canonical Engineering Standards التي تنطبق على Activation/Scope وفق applicability المملوكة لكل Standard. يجوز أن تتضمن هذه المجموعة Standard هندسية applicable من `standards/governance/`، مثل Documentation Lifecycle Standard، عندما تنطبق على الـartifact والـScope. لا تساوي هذه المجموعة مجرد union للـ Required Standards المشار إليها في Profiles. ينتجها الحل على مرحلتين إلزاميتين موضحتين في §7:

```text
Structural / Transitive Resolution
→ Candidate Standard References
→ Canonical Standard Applicability
→ Final Resolved Applicable Standards Set
```

تشمل مجموعة المرشحين المراجع المباشرة والموروثة من Profiles وأي `Explicit Additional Standards` صالحة بنيويًا. ولا تصبح أي Standard جزءًا من هذه المجموعة النهائية إلا بعد تقييم Activation Scope وحقائق الـartifact مقابل canonical Applicability وConditional Applicability المملوكة لتلك Standard. هذه المجموعة النهائية وحدها تحدد القواعد الهندسية المطلوب قراءتها وتطبيقها على Scope المهمة.

أما `STANDARD_VERSIONING_POLICY_AR.md` فهي Central Governance Policy version-managed وليست Required Engineering Standard لمجرد وجودها في `governance/`؛ لا تدخل Adoption Set لمجرد Adoption. لا تدخل أي Governance artifact أخرى إلا إذا كانت canonical applicability الخاصة بها ومرجعها المعلن يثبتان انطباقها.

### C. Pinned Operational Assets Set

هذه مجموعة **اختيارية/مشروطة** من الملفات التشغيلية غير المعيارية التي تساعد Agent أو tooling على تنفيذ workflow قائم أصلًا. غياب المجموعة بالكامل يعني:

```text
no operational assets adopted
```

ولا يجعل Adoption تاريخية أو حالية كانت صحيحة بدون Operational Assets غير صالحة.

Operational Assets ليست Engineering Standards ولا Profiles ولا Decisions، ولا تدخل أبدًا في:

```text
Candidate Standard References
Final Resolved Applicable Standards Set
```

ولا تؤثر في Stage 1 أو Stage 2 ولا تنشئ Profile composition أو governance authority جديدة.

الـKind المسموح به لكل ملف operational مثبت محصور في:

```text
skill
deterministic-helper
provider-adapter
```

تأتي الملفات افتراضيًا من **نفس exact upstream Adoption Commit** المستخدم لبقية Adoption snapshot. ويكون التحقق الميكانيكي لهوية كل ملف من:

```text
authoritative exact source identity
+
recorded canonical Upstream Path
+
recorded Local Path
+
raw-content SHA-256 equality
```

حيث تكون `authoritative exact source identity`:
- عند عدم وجود استثناء: هي exact recorded `Adoption Commit` افتراضيًا وإلزاميًا.
- عند وجود استثناء معتمد وموثق صراحةً لمزيج التثبيت (`APPROVED_AND_DOCUMENTED` mixed-pinning exception): هي exact alternate canonical commit/source identity التي يصرح بها سجل الاستثناء نفسه.
- أي حالة أخرى: `INVALID`.

يأتي مصدر الاستثناء البديل من سجل الاستثناء المعتمد والموثق canonical، ولا تضاف حقول جديدة إلى inventory داخل `STANDARDS_MANIFEST.md`. يظل التحقق من تطابق raw-content SHA-256 إلزاميًا وfail-closed في جميع الحالات؛ فالاستثناء يحدد الـ source snapshot المصرح بها للتحقق فقط، ولا يعطل أو يخفف فحص سلامة المحتوى (integrity checking).

إذا كان Skill يحتاج عدة ملفات، يسجل كل ملف مطلوب على حدة. لا يعرّف هذا العقد directory hashing أو asset registry أو package manager أو global IDs أو capability graph، ولا يسمح بـ silent mixed pinning أو floating `main` أو قناة توزيع ثانية.

### D. Local Resolver Record

```text
STANDARDS_MANIFEST.md
```

هو ملف محلي يولده المشروع ويسجل نتيجة adoption والعلاقة بين Control Set وApplicable Standards Set، ويسجل inventory هوية الـOperational Assets فقط عندما تكون معتمدة. ليس ملفًا منسوخًا من upstream، وليس Standard أو Profile أو workflow owner، ولا يدخل في Required Standards أو في Pinned Adoption Control Set.

يسجل Manifest المجموعة النهائية فقط بوصفها `Resolved Applicable Standards Set`؛ ولا يسجل Candidate Standard غير منطبقة على أنها Applicable Standard. تظل Profile references اللازمة للحل البنيوي ظاهرة في ملفات Profiles المثبتة داخل Control Set، وتظل مدخلات ونتائج resolution قابلة للمراجعة وفق الحقول القائمة.

وعليه:

```text
Pinned Adoption Files
=
Pinned Adoption Control Set
+
Pinned Applicable Standards Set
+
Pinned Operational Assets Set   # only when adopted

Local metadata
=
STANDARDS_MANIFEST.md
```

غياب `Pinned Operational Assets Set` من Manifest يعني عدم اعتماد Operational Assets، ولا يمثل schema failure أو migration requirement على المستهلكين الحاليين.

## 4. Selective Pinned Adoption

عند إنشاء أو تحديث Adoption Set:

1. تكون Adoption Standard وProfile manifests والـ Applicable Engineering Standards نسخًا محلية ومثبتة على Adoption Commit محدد.
2. تأتي جميع الملفات المنسوخة من upstream افتراضيًا من **نفس exact upstream commit** حفاظًا على consistency بين Control Plane والروابط والقواعد.
3. يمنع الاعتماد على floating `main` أو أي مرجع متحرك بدل commit محدد.
4. يسجل المشروع في Manifest المستودع upstream والـ commit والـ Profiles والـ Scopes والـ Control Set والـ Applicable Standards وإصداراتها المرتبطة بها.
5. تتم الترقية عبر تغيير reviewed يعيد حل Profiles المستخدمة فقط.
6. لا تنسخ الترقية Standards غير المنطبقة على Scopes المشروع.

لا يجوز جمع Standards من commits مختلفة إلا بقرار صريح موثق من مالك المشروع، ويجب تسجيل هذا الاستثناء في Manifest مع سببه ونطاقه. كما لا يجوز خروج Operational Assets عن exact Adoption Commit الافتراضي إلا عبر استثناء معتمد وموثق صراحةً (`APPROVED_AND_DOCUMENTED`) يحدد exact alternate commit/source identity المصرح بها ويُسجل في Manifest مع سببه ونطاقه.

## 5. Profile Composition Manifests

كل Profile مستقل ويبدأ افتراضيًا بالإصدار `1.0.0`. يجب أن يوضح ملف Profile، على الأقل:

- `Profile ID` فريدًا.
- `Profile Version` منفصلًا عن إصدارات Standards.
- `Purpose / Applicability`.
- `Extends`، أو `None` إذا لم يرث Profile آخر.
- `Required Standards` المباشرة التي يضيفها Profile.
- `Conditional Applicability`، مع الإحالة إلى Standard المالكة للشرط.
- `Resolved dependency behavior`.
- `Scope notes`.
- `Precedence notes`.

Profiles لا تنقل القواعد الهندسية إلى ملفاتها ولا تنشئ مصدرًا موازيًا للحقيقة. كل Profile يعلن الاعتمادات المباشرة فقط؛ أما الاعتمادات الموروثة فتدخل في الحل عبر Transitive Resolution.

### 5.1 Profile Versioning

يُفصل إصدار Profile عن إصدار كل Standard:

- **Patch:** تغيير صياغة أو metadata لا يغير composition.
- **Minor:** إضافة قدرة اختيارية أو شرطية متوافقة لا تغير الالتزامات القائمة.
- **Major:** تغيير Required Standards أو inheritance أو composition بما يغير عقد الاعتماد.

ملف Profile المفعّل، وكل ملف Profile موروث لازم لحل inheritance، جزء إلزامي من `Pinned Adoption Control Set` ويجب تثبيته محليًا. Profiles غير المفعلة وغير الموروثة لا تُنسخ. لا يعني ذلك إضافة Adoption Standard إلى `Required Standards`؛ فـ Required Standards تظل خاصة بالـ engineering applicability.

### 5.1.1 Frozen Profile Version Baseline وMutable Profile Candidate

بالنسبة إلى Profile قابلة للـconsumer pinning، لا تصبح Profile Version:

```text
Frozen Version Baseline
```

إلا عند وجود `Completed VALID Adoption` أو `Completed VALID Upgrade` يثبت Profile manifest/content نفسها على `exact Adoption Commit` محدد. ترتبط Profile Version المجمدة من تلك اللحظة بالـProfile manifest/content التي ثُبتت عند أول Adoption أو Upgrade مكتملة وصالحة. لا يكفي تعديل manifest أو إنشاء Commit أو Merge إلى `main` أو وجود Profile Version metadata وحده لتجميدها. يظل الـexact Adoption Commit هوية الـsnapshot الدقيقة للـProfile manifest والـcomposition المثبتة بها.

يجوز لـAdoption أو Upgrade لاحقة استخدام `Adoption Commit` مختلفة لنفس Profile Version المجمدة فقط إذا كانت Profile artifact نفسها، أي manifest/content، مطابقة تمامًا للـFrozen Profile snapshot. اختلاف repository commit وحده لا يثبت اختلاف Profile snapshot؛ تكون المقارنة مع Profile artifact نفسها، دون بقية محتوى المستودع. إذا كان هناك known frozen-version/content mismatch، فلا يجوز أن تكتمل Adoption أو Upgrade بحالة `VALID`.

تظل Profile Version الحالية غير المثبتة `Mutable Candidate Version`. بعد وجود Frozen Profile Version Baseline، تجمع Version Finalization كل التغييرات ذات الصلة منذ آخر Baseline مجمدة، ولا تستخدم Candidate سابقة كأساس لحساب Candidate لاحقة. لذلك لا تحدث chained bumps على Profile Version غير المثبتة. وأي تغيير في Profile manifest/content بعد الـFreeze يدخل Candidate Version جديدة محسوبة من الـFrozen Profile Baseline قبل أن يصبح صالحًا لـAdoption جديدة؛ لا يجوز تقديم المحتوى المختلف تحت Profile Version المجمدة نفسها.

إذا لم توجد Frozen Profile Version Baseline سابقة، وكانت Profile Version الحالية Initial أو Bootstrap أو Candidate، يجوز تطوير نفس Version قبل أول Freeze دون Bump تلقائي لمجرد تعدد التغييرات. لا تعني Version Finalization وحدها التجميد؛ يحدث الـFreeze فقط عند `Completed VALID Adoption` أو `Completed VALID Upgrade` الذي يثبت الـmanifest على exact Adoption Commit.

إذا تعذر إثبات حالة Frozen Profile Version Baseline أو عدمها، تكون النتيجة:

```text
OWNER DECISION REQUIRED — VERSION BASELINE STATUS
```

ولا يجوز حسمها بالتخمين أو بمجرد وجود Version في Profile manifest أو Git history.

يملك هذا القسم Profile Versioning حصريًا. أما `Standard ID` و`Standard Version` و`Standard Version Format` وانتقالات إصدارات Standards فتخضع للسياسة المركزية `STANDARD_VERSIONING_POLICY_AR.md`. لا تدخل هذه السياسة في `Pinned Adoption Control Set` أو `Resolved Applicable Standards Set`، ولا يحتاج Consumer إلى نسخها لمجرد Adoption.

### 5.2 No-Cascade Profile Version Rule

تغيير `Required Standards` المباشرة في Profile هو **Major Profile transition** لذلك الـProfile فقط. لا تُطبّق Version bump تلقائية على Profiles الأبناء أو الموروثة لمجرد تغير parent Profile؛ فـ`Profile Version` تمثل manifest الخاصة بذلك Profile، لا snapshot للمحتوى الموروث. يعمل Profile descendant انتقالًا فقط إذا تغيرت manifest أو `Extends` أو `Required Standards` أو `Conditional Applicability` أو contract الخاصة به مباشرةً. تبقى composition traceable عبر exact Adoption Commit ونسخ Profiles الموروثة وFinal Resolved Applicable Standards Set.

هذه القاعدة canonical هنا وحده، ولا تُعاد كتابتها داخل child Profiles.

## 6. Scope-Aware Profile Activation

كل Profile Activation في المشروع يجب أن تحدد Scope صريحًا. يمكن أن يكون Scope:

```text
/
Modules/*
Modules/*Slim
Modules/SpecificFeature
```

أو قائمة paths محددة. Scope هو نطاق التطبيق، وليس نوعًا حصريًا للمستودع.

### 6.1 عقد تفعيل Project Host

إذا كان `root artifact` في Repository يمثل `deployable Host/Application Project`، يجب تفعيل:

```text
project-host
```

على Scope:

```text
/
```

لا ينطبق هذا الإلزام على `package-only repositories` أو `module-only artifact scopes`. كما أن وجود `project-aware-slim-module` لا يغني عن `project-host` للـHost root، ولا يجعل ذلك الـModule نفسه Host Project.

يكون تفعيل `project-aware-slim-module` على Scope ضيق صالحًا فقط عندما يفعّل المشروع نفسه:

```text
project-host @ /
```

في نفس Repository. غياب `project-host @ /` مع هذا التفعيل يجعل:

```text
Resolution Status = INVALID
```

ولا يُعالج كـ Exception أو `OWNER DECISION REQUIRED` أو fallback.

عند وجود Packages أو Modules داخل Host Repository، يظل `project-host` مفعّلًا على `/`، وتظل Profiles الخاصة بالـPackages أو Modules مفعّلة على Scopesها المستقلة عند انطباقها. لا يغير هذا العقد Manifest schema، ولا يضيف Project Host applicability إلى أي Package أو Module Profile.

يجوز للمشروع تفعيل عدة Profiles في Repository واحدة، كما يجوز أن تنطبق عدة Activations على الملف نفسه. لا يعني Scope الأكثر تحديدًا إلغاء Scope أوسع تلقائيًا؛ بل تُجمع Profiles المنطبقة، ما لم يوجد استثناء مشروع موثق بسلطة مناسبة.

عند تنفيذ مهمة، يجب على الوكيل:

1. تحديد الملفات والمسارات المتأثرة وحقائق الـartifact ذات الصلة، مثل كونه مكتبة مستقلة أو موديولًا قابلًا للاستخراج أو ميزة مرتبطة بـHost.
2. مطابقة المسارات مع Profile Activations وتحديد كل Activation/Scope على حدة.
3. تنفيذ Structural / Transitive Resolution لكل Activation: حل Profiles النشطة والموروثة، وجمع كل مراجع Required Standards المباشرة والموروثة وأي Explicit Additional Standards، والتحقق من سلامة graph والروابط والـmetadata قبل أي تصفية بسبب applicability.
4. بعد نجاح الحل البنيوي، تقييم كل Candidate Standard Reference مقابل Scope وحقائق الـartifact وcanonical Applicability أو Conditional Applicability التي تملكها تلك Standard.
5. أخذ Union للـStandards المنطبقة فقط لتكوين Final Resolved Applicable Standards Set عبر الـActivations المنطبقة.
6. قراءة وتطبيق Standards الموجودة في المجموعة النهائية فقط.
7. تطبيق التعليمات المحلية والاستثناءات الموثقة وفق precedence المشروع.

إذا عبرت المهمة أكثر من Scope، ينفذ الحل البنيوي وتقييم applicability لكل Activation/Scope بصورة مستقلة، ثم يستخدم Union للـ Final Applicable Standards الناتجة. لا تحذف خصوصية Scope أوسع أو أضيق Profile موروثة من مجموعة المرشحين؛ تظل applicability لكل Standard هي الحاكمة للنتيجة النهائية.

## 7. Transitive Profile Resolution

يجب أن يكون inheritance صريحًا وقابلًا للحل دون دورات. إذا كان:

```text
project-aware-slim-module
    extends slim-module
slim-module
    extends base-module
base-module
    extends composer-package
```

تتكون عملية الحل من مرحلتين منفصلتين:

### Stage 1 — Structural / Transitive Resolution

ينتج الحل البنيوي مجموعة `Candidate Standard References` من Required Standards المباشرة لكل Profile في سلسلة inheritance، بترتيب dependency، ومن Explicit Additional Standards المصرح بها. هذه مجموعة مرشحين مرحلية وليست Final Resolved Applicable Standards Set، ولا تلزم المشروع بإدراج Required Standards الموروثة مكررًا.

يجب التحقق من كل مرجع وكل metadata بنيوي مطلوب في هذه المرحلة، قبل تقييم applicability. يظل missing Standard أو Profile أو inherited Profile أو reference أو mandatory metadata، وكذلك inheritance cycle أو structural Manifest mismatch، `Structural Invalidity` حتى لو كانت applicability الخاصة بالـStandard ستستبعده لاحقًا من المجموعة النهائية. لا يجوز استخدام applicability لإخفاء graph أو reference مكسور.

### Stage 2 — Canonical Standard Applicability

بعد نجاح Stage 1، تقيم كل Candidate Standard مستقلةً مقابل Activation Scope وحقائق الـartifact الفعلية، وفق canonical Applicability وConditional Applicability المملوكة لتلك Standard. لا يملك Profile أو Manifest توسيع applicability الخاصة بالـStandard أو تضييقها. تدخل الـStandard في `Final Resolved Applicable Standards Set` فقط إذا انطبقت عليها قواعدها canonical.

لا يجوز إسقاط Candidate Standard موروثة من المرحلة البنيوية لمجرد أن Profile أكثر تحديدًا مفعّل أو مفضل. ويجوز استبعادها من المجموعة النهائية فقط إذا كانت canonical applicability التي تملكها هي تستبعد Scope/الـartifact الفعلي بصورة deterministic. وبهذا لا تتجاوز عبارات Profile مثل “inherited Standards enter the Resolved Set” مرحلة المرشحين أو applicability المملوكة لكل Standard.

يجب على الـ resolver المفاهيمي أو عملية المراجعة التحقق من:

- وجود كل Profile مذكور في `Extends`.
- عدم وجود inheritance cycle.
- وجود كل Required Standard المشار إليها فعليًا، حتى إذا لم تدخل لاحقًا المجموعة النهائية.
- وجود كل Explicit Additional Standard reference معلنة وصحتها بنيويًا.
- بقاء كل Standard موروثة ضمن مجموعة المرشحين مهما كانت خصوصية Profile مفعّلة.
- تكوين المجموعة النهائية بتطبيق canonical applicability المملوكة لكل Standard على Scope وحقائق الـartifact.

إذا استبعدت applicability المملوكة للـStandard مرشحًا بصورة deterministic، فهذا حكم applicability وليس Exception أو deviation: لا يحتاج إلى Exception، ولا يجعل النتيجة `INVALID` أو `OWNER DECISION REQUIRED` بذاته. أما إذا لم تسمح قواعد الـStandard وحقائق الـScope بحسم applicability، فتطبق Resolution Status القائمة في §16 دون افتراض الانطباق. ولا يغير Profile القاعدة التي تملكها Standard أخرى؛ وأي deviation أو override لا يسمح به عقد الـStandard لا يصبح تطبيقًا صحيحًا بمجرد طلبه أو تسجيله في Profile أو Manifest. تسجل الاستثناءات المعتمدة والموثقة فقط في Manifest بعد اكتمال Adoption؛ أما الطلبات والقرارات غير المحسومة فتبقى review evidence. وتخضع النتائج لقواعد §16.

### 7.1 Local Reference Closure and Final Pinned Set

يجب أن تكون المجموعة المحلية النهائية المثبتة **locally reference-closed**. لا توجد طبقة ثالثة باسم `Reference Support Set`، ولا يجوز نسخ Standard غير منطبقة لمجرد إبقاء رابط من Standard أخرى حيًا.

إذا احتوت Standard منطبقة على رابط نسبي إلى Standard أخرى، فيجب أن يكون الهدف موجودًا في `Pinned Adoption Control Set` أو `Final Resolved Applicable Standards Set`. وإذا كان الرابط مجرد إشارة informative إلى Standard قد لا تنطبق، فيجب إصلاح أو إعادة كتابة المرجع في upstream حتى لا ينشئ dependency محلية على Standard غير منطبقة.

إذا كانت:

```text
A applicable
B excluded
A normatively requires B
```

فإن composition أو applicability design غير صالح، وتكون النتيجة `Resolution Status = INVALID`. لا يجوز إصلاح ذلك بنسخ B كملف support غير منطبق؛ المسار الصحيح هو إصلاح dependency أو applicability في المصدر canonical ثم إعادة الحل.

يُعد فشل final local reference closure `Structural Invalidity`، ويجب اكتشافه fail-closed قبل قبول Adoption أو Upgrade أو Manifest Validation.

### مثال توضيحي عام: Project-Aware Slim

عند تفعيل `project-aware-slim-module`، يظل Stage 1 مطالبًا بحل السلسلة كاملة `project-aware-slim-module → slim-module → base-module → composer-package` والتحقق من كل Profile وRequired Standard reference. بعد ذلك فقط يقيّم Stage 2 كل Standard على حدة. فإذا كان الـScope الفعلي ميزة Host-specific غير قابلة للاستخراج، فإن `COMPOSER_PACKAGE_STANDARD.md §3` لا ينطبق على هذا الـartifact لأنه يقصر نطاقه على مكتبات Composer مستقلة قابلة لإعادة الاستخدام؛ لذلك لا تدخل Composer Package Standard في المجموعة النهائية لذلك الـScope لمجرد inheritance. يظل Composer Profile ومرجعه جزءًا من الحل البنيوي، ولا يتغير inheritance أو أي Profile. هذا تطبيق للقاعدة العامة التي تملكها كل Standard، وليس استثناءً أو special case لاسم Profile.

## 8. Local Directory Layout وسلامة الروابط

عند نسخ Pinned Adoption Files إلى المشروع، يجب الحفاظ على البنية النسبية اللازمة لسلامة الروابط بين الملفات المختارة. المثال النهائي التالي يوضح أن Adoption Standard وProfile manifests المفعلة والموروثة جزء من النسخة المحلية، بينما تبقى المعايير الهندسية انتقائية:

```text
docs/php-engineering-standards/
├── STANDARDS_MANIFEST.md
└── standards/
    ├── STANDARDS_ADOPTION_STANDARD_AR.md
    ├── profiles/
    │   ├── <active profiles>
    │   └── <inherited profiles>
    ├── ai/
    │   └── <if applicable>
    ├── governance/
    │   └── <resolved applicable governance standards>
    ├── modules/
    │   └── <resolved applicable standards>
    ├── packages/
    │   └── <resolved applicable standards>
    └── testing/
        └── <if resolved>
```

تكون `<active profiles>` و`<inherited profiles>` أسماء الملفات المحلية الفعلية، ولا تعني placeholders لنسخ كل محتوى مجلد `profiles/`.

لا تُنسخ:

```text
unused profiles
unused engineering standards
historical Audit / Verification evidence
docs/decisions/
```

لا يشترط الاعتماد نسخ Folders فارغة، ولا تدخل الوثائق التاريخية في Adoption Set. لا يقرر هذا المعيار Retention للوثائق التاريخية في المستودع المركزي؛ يخضع ذلك لـ[Documentation Lifecycle Standard](governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md).

## 9. عقد `STANDARDS_MANIFEST.md`

يجب أن يحتفظ كل مشروع تابع بملف:

```text
docs/php-engineering-standards/STANDARDS_MANIFEST.md
```

أو بالاسم نفسه تحت local standards root المعتمد للمشروع. الـ Manifest هو inventory وresolver record، وليس Standard جديدة. يجب أن يسجل على الأقل:

```text
Upstream Repository
Adoption Commit
Adoption Date أو metadata مناسبة وفق سياسة المشروع
Pinned Adoption Control Set
Active Profiles
Profile Version لكل Profile Activation
Scope لكل Profile Activation
Resolved Applicable Standards Set (final only; no non-applicable candidates)
Version لكل Standard
Explicit Additional Standards إن وجدت (مراجع الإدخال؛ لا تثبت وحدها applicability)
Explicit Exceptions/Overrides إن وجدت
Pinned Operational Assets Set إن كانت Operational Assets معتمدة:
  Kind
  Upstream Path
  Local Path
  SHA-256
```

يجب أن تجعل البيانات المسجلة قابلة لمراجعة العلاقة بين كل Profile Activation وScope والـ Control Set والـ Final Resolved Applicable Standards الناتجة عنها. لا تضع في Manifest القواعد الهندسية الكاملة؛ استخدم روابط إلى الملفات المملوكة لها. لا تسجل Candidate Standard غير منطبقة ضمن `Resolved Applicable Standards Set`؛ تظل سلامة مراجعها البنيوية قابلة للتحقق من ملفات Profiles المثبتة أو resolution evidence، وتظل Explicit Additional Standard مدخلًا معلنًا لا يتجاوز applicability canonical.

يسجل `Explicit Exceptions/Overrides` الاستثناءات المعتمدة والموثقة الداخلة في Adoption مكتملة فقط. لا يمثل هذا الـManifest حالة مقترحة أو غير محسومة؛ تطبق عليه حدود الاكتمال والترقية في §16.

عند اعتماد Operational Assets، يجب أن يظهر inventory canonical بعنوان exact هو `Pinned Operational Assets Set`، وأن يسجل لكل ملف الحقول الأربعة فقط اللازمة لإثبات الهوية: `Kind` و`Upstream Path` و`Local Path` و`SHA-256`. لا تضاف حقول جديدة إلى inventory (مثل Source Commit أو Asset Commit أو Provider أو Registry ID أو Asset ID)؛ وعند وجود استثناء مصرح به لـ mixed pinning، تُستمد exact alternate source identity من سجل الاستثناء المعتمد والموثق المسجل تحت `Explicit Exceptions/Overrides`، بينما يظل inventory محصورًا في الحقول الأربعة. لا تضع الـManifest workflow rules أو Skill bodies أو Decision rationale؛ تظل inventory/resolver record فقط. إذا لم يعتمد المشروع أي Operational Assets، لا يكون هذا الـheading إلزاميًا وتظل Manifest المتوافقة تاريخيًا صالحة دون migration شكلية.

### 9.1 سجل استثناء Operational Asset Mixed-Pinning

الاستثناء من same-commit pinning لا يستخدم إلا للسماح صراحةً بقراءة Operational Asset محدد من Commit مركزي بديل. لا تمنح صيغة هذا السجل صلاحية عامة لأي مستهلك. يجب أن يعتمد المستهلك قرارًا دائمًا خاصًا به يوافق هذا الاستثناء تحديدًا؛ أما `DEC-032` المركزي لمستودع `Maatify-ltd/php-engineering-standards` (سجل `OPERATIONAL_ASSET_MIXED_PINNING_EXCEPTION_RECORD_DECISION.md`، وليس قرارًا محليًا للمستهلك) فيعرّف صيغة السجل والتحقق الميكانيكي فقط ولا يوافق على أي انحراف لمستهلك.

يسجل الاستثناء كسطر واحد داخل `Explicit Exceptions/Overrides` وبالصيغة والترتيب الحرفيين التاليين، بما في ذلك أسماء الحقول وحالة الأحرف وعلامة الفصل `; `:

```text
- Type=OPERATIONAL_ASSET_MIXED_PINNING; State=APPROVED_AND_DOCUMENTED; Decision-ID=DEC-NNN; Authority=Project Owner; Upstream-Path=<exact upstream path>; Local-Path=<exact local path>; Source-Commit=<40 lowercase hexadecimal characters>; Approved-On=YYYY-MM-DD; Reason=<non-empty single-line rationale>
```

`Decision-ID` هو قرار المستهلك الدائم. يجب أن يكون مفهرسًا مرة واحدة بالضبط كـ`ACTIVE` في `docs/decisions/DECISIONS_INDEX.md` لدى المستهلك، وأن يشير إلى سجل موجود يثبت `Decision ID` و`Status: ACTIVE` و`Decision Authority: Project Owner` والحقول الدقيقة `Upstream Path` و`Local Path` و`Source Commit` و`Approved-On` و`Reason` المطابقة للسطر. تُكتب هذه البيانات كسطور metadata ذات اسم حقل bold وقيمة inline code، وتطابق الأسماء والقيم حرفيًا؛ ويكون `Reason` قيمة السجل نفسها. يصف السجل الدائم سلطة الموافقة ونطاق الملف والسبب، ويعتمد المالك الاستثناء بتاريخ `Approved-On`. يجب ألا يستخدم قرار مركزي يعرّف هذه القواعد بدل قرار المستهلك الذي يجيز الحالة الفعلية.

القيم مقيدة كما يلي: `State` و`Authority` و`Type` تطابق القيم أعلاه حرفيًا؛ `Decision-ID` يطابق `DEC-` متبوعًا بثلاثة أرقام؛ المساران نسبيان exact بمقاطع ASCII من الحروف والأرقام و`.` و`_` و`-` مفصولة بعلامة `/` واحدة، ومن دون مسافات أو محارف تحكم أو مقاطع `.` و`..` أو مقطع فارغ؛ `Source-Commit` طولها 40 خانة hex صغيرة؛ والتاريخ تاريخ تقويمي صالح بصيغة `YYYY-MM-DD`. لا يجوز أن يحتوي `Reason` على `;` أو backtick أو محارف تحكم أو newline أو أن يكون فارغًا أو محاطًا بمسافات. لا تقبل مسافات إضافية أو حقولًا مكررة أو إضافية أو مفقودة أو تبديل ترتيب الحقول أو اختلاف الترقيم/الفواصل.

أي سطر يبدأ بحقل `Type=OPERATIONAL_ASSET_` ويعلن نوع هذا الاستثناء لكنه لا يطابق الصيغة كلها، أو تتكرر سجلات الاستثناء للزوج نفسه، أو تتعارض، ينتج `INVALID`. لا تتحول استثناءات معتمدة لأنواع أخرى إلى mixed-pinning لمجرد وجود SHA أو كلمات مشابهة؛ تظل خاضعة لعقودها ولا تغير مصدر أي Operational Asset. السجل الصحيح الذي لا يطابق معًا `Upstream-Path` و`Local-Path` للأصل الجاري فحصه لا يغير مصدره. عند غياب سجل صالح منطبق، يبقى `Adoption Commit` المصدر الافتراضي. لا يغير الاستثناء علاقة الملف ببيانات Adoption الأخرى ولا يسمح بتجاوزها.

يظل التحقق من وجود Commit البديل ونوع Git object `blob` وقراءة المحتوى الخام ومطابقة `SHA-256` إلزاميًا لكل ملف. لا يملك السجل أو القرار تجاوز فشل القراءة أو عدم تطابق المحتوى.

## 10. تكامل `AGENTS.md` في المشروع التابع

يكفي أن يشير `AGENTS.md` في المشروع التابع إلى `STANDARDS_MANIFEST.md` وإلى هذا Adoption Standard، بدل سرد كل Standard يدويًا. عند بدء مهمة، يلتزم الوكيل بمسار resolution المناسب لنوع المهمة.

### 10.1 Normal Engineering Task

في المهمة الهندسية العادية:

1. يقرأ الوكيل `STANDARDS_MANIFEST.md`.
2. يحدد Scope الملفات المتأثرة.
3. يحدد Profile Activations المنطبقة المسجلة محليًا.
4. يستخدم الـ Applicable Standards Set المسجلة في Manifest.
5. يمكنه التحقق من composition عبر Profile manifests المحلية المثبتة.
6. يقرأ Applicable Standards فقط.
7. إذا كانت `Pinned Operational Assets Set` موجودة، يستخدم فقط الـOperational Assets المحلية المثبتة والمسجلة فيها حسب الحاجة للعملية التشغيلية القائمة.

في هذا المسار يستخدم الوكيل Final Resolved Applicable Standards المحلية فقط؛ لا يعيد Stage 1 أو Stage 2 ولا يحتاج Candidate Standards غير المنطبقة أو الاتصال بـ upstream. كما أن Governance Context Reconciliation أثناء المهمة العادية في Scope يستخدم Selective Adoption لا يعد Adoption re-resolution ولا يغير Manifest أو Adoption Commit. لا يعاد بناء adoption من upstream في كل Task، ولا يحتاج الوكيل الاتصال بـ upstream لإتمام مهمة عادية.

### 10.2 Adoption / Upgrade / Manifest Validation

في Adoption أو Upgrade أو Manifest Validation، تظل Profile manifests المحلية المثبتة هي مدخلات Active Profile Activation وinheritance وControl Set. ومنها، مع Explicit Additional Standard references المعلنة، يُبنى Structural / Transitive Resolution ومجموعة Candidate Standard References. أما تعريفات الـStandards المرشحة اللازمة للتحقق البنيوي في Stage 1 وcanonical applicability في Stage 2، فتُقرأ من exact upstream Adoption Commit، ولا يلزم نسخ Candidate Standard غير منطبقة إلى المشروع المحلي.

يُختار مصدر الـCommit الدقيق كالتالي:

- **Adoption:** exact upstream Adoption Commit المقترح الذي سيسجل في Manifest بعد اكتمال Adoption.
- **Manifest Validation:** exact `Adoption Commit` المسجل في Manifest لإعادة التحقق من كل Candidate Standard reference وتعريفه وcanonical applicability الخاصة به، ثم مقارنة Final Resolved Applicable Standards Set الناتجة بالـManifest.
- **Upgrade:** exact upstream Adoption Commit الجديد المقترح، مع إعادة حل Profile Activations المسجلة ومقارنة النتيجة المقترحة بالـManifest السابقة دون استبدالها قبل اكتمال Upgrade.

يجب أن تتطابق Profile manifests المحلية المثبتة مع Control inputs للـAdoption Commit المستخدم وفق قاعدة §4. لا يضيف هذا الإجراء أي Manifest fields؛ يستخدم `Upstream Repository` و`Adoption Commit` الموجودين. إذا تعذر الوصول إلى exact Commit المطلوب لتنفيذ Structural / Transitive Resolution أو Canonical Standard Applicability، فتكون النتيجة حتمًا `Resolution Status = INVALID` وفق §16.3، ولا تنشأ عن عدم توفره Exception؛ ومع عدم وجود deviation مستقلة تكون `Exception State = NONE`. ينطبق ذلك سواء كان الإجراء Adoption أو Upgrade أو Manifest Validation. لا يستخدم floating `main` أو أي مرجع متحرك، ولا يُخمن أي تعريف، ولا ينشأ fallback source. عدم توفر الـCommit ليس `OWNER DECISION REQUIRED` أو Exception أو deviation قابلة للموافقة، ولا يثبت أن Standard مفقودة أو أن محتوى الـCommit broken؛ بل يعني أن required pinned verification input غير متاح، ولذلك لا يمكن إثبات resolution المطلوبة ولا يجوز للمالك تحويل النتيجة إلى `VALID`.

إذا كانت `Pinned Operational Assets Set` موجودة في Adoption المكتملة أو مقترحة ضمن Adoption/Upgrade، يتحقق المسار نفسه من schema المسموح بها ومن أن `Upstream Path` لكل ملف موجود في exact authoritative source identity المصرح بها (وهي exact recorded Adoption Commit افتراضيًا، أو exact alternate canonical commit/source identity المصرح بها صراحةً في استثناء معتمد وموثق `APPROVED_AND_DOCUMENTED` وفق §16.4)، وأن `Local Path` يطابق الملف المحلي المقصود، وأن SHA-256 للمحتوى الخام مطابق للنسخة في تلك الـsource snapshot المصرح بها. غياب المجموعة يعني عدم وجود Operational Assets مطلوب التحقق منها ولا يعد خطأ.

في Manifest Validation أو Upgrade، تبقى أي Manifest سابقة صحيحة كما هي؛ ولا تنشأ أو تعدل canonical Manifest قبل نجاح إعادة التحقق. مسار الاستعادة الوحيد هو إتاحة exact Commit ثم إعادة تنفيذ Resolution / Validation وإعادة تقييم الحالة. يظل حظر floating refs وحدود Manifest في §16.5 قائمين.

## 11. Additional Standards

يجوز للمشروع إضافة Standard مستقلة خارج Profiles كمرشح عندما تنطبق على Scope محدد. يجب أن يكون مرجعها canonical وصحيحًا بنيويًا، وأن تسجل تحت `Explicit Additional Standards` في Manifest كمدخل معلن، وتظل مثبتة إلى نفس Adoption Commit افتراضيًا. بعد ذلك تخضع للمرحلة الثانية نفسها: لا تجعل الإضافة applicability الخاصة بالـStandard أوسع، ولا تدخل المجموعة النهائية إذا كانت canonical applicability تستبعد الـScope أو الـartifact الفعلي.

لا يجوز استخدام Additional Standards كطريقة غير مباشرة للعودة إلى نسخ كل محتوى `standards/`. يجب أن يكون لكل إضافة سبب applicability ومسار أو Scope واضح.

## 12. الترقية والمراجعة والتتبع

تتم ترقية الاعتماد عبر reviewed change تسجل:

- الـ Adoption Commit الجديد.
- Profiles والإصدارات المستخدمة بعد الترقية.
- إعادة حل Scopes والـ Control Set والـ Applicable Standards Set.
- أي Standard أضيفت أو أزيلت ولماذا.
- أثر الروابط النسبية والاستثناءات.
- أي تغيير في `Pinned Operational Assets Set` عند وجودها: الملفات/المسارات/الـKind/الـSHA-256، مع إثبات exact same-commit pinning افتراضيًا أو exact alternate source identity المصرح بها في استثناء canonical معتمد وموثق (`APPROVED_AND_DOCUMENTED`).

الترقية لا تعني Refresh شاملًا للمستودع. يعاد Resolve للـ Profiles المفعلة فقط، وتبقى Standards غير المنطبقة خارج النسخة المحلية.

## 13. Adoption Precedence

هذا المعيار يحدد applicability وcomposition، ولا يلغي هرم الأولوية العام في `AI_COLLABORATION_WORKFLOW_AR.md`. عند وجود تداخل:

1. تنتج Profile Activations مع inheritance مجموعة المرشحين البنيوية. ولا يحذف Profile Standard موروثة من هذه المجموعة لمجرد specificity أو preference أو تفعيل Profile أخرى.
2. بعد اكتمال الحل البنيوي، تحدد canonical applicability التي تملكها كل Standard إن كانت تدخل المجموعة النهائية؛ لا يوسع Profile أو Manifest applicability ولا يلغيها.
3. تخضع Explicit Additional Standards للقاعدة نفسها، ولا يحول إدراجها إلى Manifest المرجع غير المنطبق إلى Standard منطبقة.
4. تطبق تعليمات `AGENTS.md` الخاصة بالمشروع وفق أولوية المشروع، وتكون أي deviations أو overrides مسموح بها صراحةً في العقد واضحة ومسجلة.
5. تبقى تعليمات المالك الحالية والقرارات المعتمدة أعلى من عقد الاعتماد وفق هرم المسؤولية المعمول به، لكنها لا تحول reference أو graph مكسورًا إلى Structural Resolution صحيح.

عند تعارض غير محسوم بين مصادر ذات أولوية متقاربة، لا يُحسم بالتخمين؛ يجب تسجيله ورفعه للمالك.

## 14. Invariants

يجب أن تظل الحقائق التالية صحيحة عند اعتماد أي مشروع:

```text
Central canonical source: YES
Adoption Standard present locally: YES
Active Profile manifests pinned locally: YES
Inherited Profile manifests pinned locally: YES
Unused Profile manifests copied: NO
Applicable Standards only: YES
Ordinary task requires upstream network: NO
Manifest independently auditable against pinned local Control inputs and its exact recorded upstream Adoption Commit: YES
Same upstream commit by default: YES
Floating main: NO
Full repository snapshot: NO
Historical audits and decisions copied to consumers: NO
Underlying standards remain source of truth: YES
Operational assets optional: YES
Operational assets create Profile composition: NO
Operational assets create governance authority: NO
Operational asset same Adoption Commit by default: YES
Operational asset alternate source requires approved documented exception: YES
Operational asset hash verification mandatory and fail-closed: YES
```

## 15. Adoption Review Checklist

قبل قبول Adoption أو Upgrade، يجب التحقق من:

- [ ] وجود Upstream Repository وAdoption Commit ثابت.
- [ ] وجود Adoption Standard محليًا ضمن Pinned Adoption Control Set.
- [ ] وجود كل Active Profile manifest محليًا ومثبتًا.
- [ ] وجود كل inherited Profile manifest لازمة للحل محليًا ومثبتة.
- [ ] عدم نسخ Profile غير مستخدمة.
- [ ] عدم استخدام floating `main`.
- [ ] عدم نسخ `standards/` بالكامل افتراضيًا أو احتياطيًا.
- [ ] وجود Profile ID وVersion وScope لكل Activation.
- [ ] وجود `project-host @ /` في نفس Repository لكل `project-aware-slim-module` activation؛ وغيابه ينتج `Resolution Status = INVALID`.
- [ ] سلامة inheritance وعدم وجود cycle.
- [ ] وجود وصحة بنيوية لكل مراجع `Required Standards` المباشرة والموروثة في `Stage 1 / Candidate Standard References`؛ يظل أي مرجع Required Standard مفقود أو مكسور `Structural Invalidity` قبل Stage 2.
- [ ] اقتصار `Pinned / Final Resolved Applicable Standards Set` على Standards التي اجتازت canonical applicability الخاصة بها في `Stage 2`.
- [ ] كون المجموعة المحلية النهائية المثبتة locally reference-closed بعد تكوين Final Set؛ ويظل فشل الإغلاق `Structural Invalidity` fail-closed.
- [ ] عدم إنشاء `Reference Support Set` أو نسخ Standard غير منطبقة لمجرد إبقاء رابط حيًا.
- [ ] عدم اشتراط إدراج Candidate Standard استُبعدت بصورة deterministic وفق canonical applicability المملوكة لها في مجموعة `Final / Pinned Applicable Standards Set`.
- [ ] تطبيق resolution عابر للـ Profiles دون تكرار يدوي.
- [ ] تطابق Control Set وApplicable Standards Set مع Manifest.
- [ ] عدم حاجة المهمة الهندسية العادية إلى upstream network.
- [ ] الحفاظ على relative links والبنية اللازمة لها.
- [ ] تسجيل Additional Standards والاستثناءات صراحةً.
- [ ] عدم إدخال historical Audit/Verification artifacts أو Decision records ضمن Adoption Set لمجرد وجودها في المستودع المركزي.
- [ ] قراءة الوكيل للـ Standards المنطبقة فقط على Scope المهمة.
- [ ] سلامة البنية قبل تقييم Exceptions، وعدم تحويل Structural Invalidity إلى حالة قابلة للـwaive.
- [ ] تسجيل Resolution Status لكل Profile Activation/Scope بصورة مستقلة، دون إخفاء نتيجة غير صحيحة بنتيجة أخرى.
- [ ] تطبيق أولوية التجميع `INVALID` ثم `OWNER DECISION REQUIRED` ثم `VALID`.
- [ ] الفصل بين Resolution Status وException State واستخدام الحالات الثلاث المحددة لكل منهما فقط.
- [ ] عدم تطبيق Requested Exception أو اعتبارها موافقة.
- [ ] عدم إنشاء أو تحديث canonical Manifest لحالة Adoption/Upgrade غير محسومة، مع إبقاء Manifest صالحة سابقة كما هي أثناء Upgrade غير محسومة.
- [ ] عند وجود `Pinned Operational Assets Set`: استخدام الـheading exact، وحصر `Kind` في `skill` أو `deterministic-helper` أو `provider-adapter`، وتسجيل `Upstream Path` و`Local Path` و`SHA-256` لكل ملف، والتحقق من raw-content SHA-256 ومن exact authoritative source identity المصرح بها (سواء كانت exact Adoption Commit افتراضيًا، أو exact alternate canonical commit/source identity المصرح بها صراحةً في استثناء APPROVED_AND_DOCUMENTED).
- [ ] اعتبار غياب `Pinned Operational Assets Set` حالة صحيحة تعني عدم اعتماد Operational Assets، وعدم فرض migration على Consumer بلا Assets.
- [ ] عدم إدخال Operational Assets في Candidate/Final Standards Sets أو Profile composition أو تحويل Manifest إلى workflow/policy owner.

## 16. حسم Adoption وفشلها والاستثناءات

يحدد هذا القسم نتائج Adoption وUpgrade وManifest Validation. لا ينشئ Resolver أو Validator executable، ولا يغير Profile composition أو inheritance أو حقول Manifest القائمة.

### 16.1 تشخيص كل Profile Activation/Scope

كل Profile Activation مع Scope محدد وحدة تشخيص مستقلة. يربط كل تشخيص بـ`Profile ID` والـScope والـpinned inputs المستخدمة، وينتج بصورة deterministic `Resolution Status` خاصًا به، مع تسجيل `Exception State` الخاصة به عند انطباقها.

لا تدمج نتائج Activations مختلفة في تشخيص واحد قبل تسجيل نتيجة كل منها، ولا تسمح نتيجة `VALID` لـActivation بإخفاء نتيجة `INVALID` أو `OWNER DECISION REQUIRED` لـActivation أخرى. وإذا تكرر Profile على Scopes مختلفة، تسجل نتيجة كل Activation/Scope على حدة؛ لا تنشأ `Aggregate Exception State`.

### 16.2 Overall Resolution Status

القيم الوحيدة لـ`Resolution Status`، على مستوى Activation/Scope والنتيجة الإجمالية، هي:

```text
VALID
INVALID
OWNER DECISION REQUIRED
```

تحسب النتيجة الإجمالية لكل Activations/Scopes المنطبقة وكل قرارات Adoption المعلقة وفق الترتيب التالي:

```text
If ANY applicable Activation / Scope is INVALID
→ Overall Resolution Status = INVALID

Else if ANY applicable Activation / Scope
or any unresolved adoption decision
requires Owner Decision
→ Overall Resolution Status = OWNER DECISION REQUIRED

Else
→ Overall Resolution Status = VALID
```

الأولوية الإلزامية هي:

```text
INVALID
>
OWNER DECISION REQUIRED
>
VALID
```

وجود Owner Decision معلقة لا يغيّر `INVALID` الناتجة عن أي Structural Invalidity أو حالة `INVALID` أخرى إلى `OWNER DECISION REQUIRED`.

### 16.3 Structural Invalidity غير قابلة للاستثناء

تشمل Structural Invalidity، على الأقل:

```text
missing required Standard
missing active Profile
missing required inherited Profile
missing or broken Explicit Additional Standard reference
inheritance cycle
broken required reference
broken required relative link / final local reference closure
missing project-host @ / for project-aware activation
missing mandatory structural metadata
structural Manifest mismatch
known frozen-version/content mismatch
malformed Pinned Operational Assets inventory when present
missing pinned operational file when declared
operational-asset raw-content SHA-256 mismatch against authoritative authorized source identity
```

يشمل `structural Manifest mismatch` عدم مطابقة ما يسجله Manifest فعليًا للـresolved Control Set أو Final Resolved Applicable Standards Set الناتجة من المرحلتين أو Profile Activations أو الـpinned structure المطلوبة. ويشمل `final local reference closure` التحقق من أن كل رابط نسبي مطلوب من Standard منطبقة يصل إلى هدف موجود في Control Set أو Final Applicable Set، أو أن المرجع informative أُصلح upstream ولا ينشئ dependency محلية على Standard غير منطبقة. ويشمل `known frozen-version/content mismatch` ثبوت أن Version مجمدة نفسها تشير إلى artifact أو Profile manifest/content مختلفة عن الـFrozen snapshot التي ثبتت عند أول `Completed VALID Adoption` أو `Completed VALID Upgrade`. لا يعد اختلاف repository commit وحده mismatch؛ يجب مقارنة الـartifact نفسها. لا يعد عدم تسجيل Candidate Standard مستبعدة canonical ضمن Final Applicable Set mismatch؛ لكن يجب أن تظل كل مراجع Profile وRequired Standards قابلة للتحقق بنيويًا قبل تقييم applicability. ويشمل `operational-asset raw-content SHA-256 mismatch against authoritative authorized source identity` أي عدم تطابق بين SHA-256 للمحتوى الخام للملف المحلي وبين محتواه في الـsource snapshot المصرح بها (سواء كانت exact Adoption Commit الافتراضية، أو exact alternate canonical commit/source identity المصرح بها صراحةً في استثناء معتمد وموثق `APPROVED_AND_DOCUMENTED`). الاستثناء يغير الـsnapshot المصرح بها فقط ولا يعطل التحقق من الـhash، وأي mismatch فعلي يظل Structural Invalidity غير قابلة للاستثناء وتنتج `Resolution Status = INVALID`. يراعي فحص التطابق فقط deviations التي يسمح هذا العقد باستثنائها صراحةً؛ فلا يصبح deviation موثق ومسموح Structural Invalidity لمجرد أنه exception. لكن لا يجوز لأي exception أن يتجاوز أي بند من قائمة Structural Invalidity أعلاه؛ ويظل missing required Standard أو Profile أو inheritance أو reference أو metadata المطلوبة Structural Invalidity غير قابلة للـwaive.

إذا تعذر إثبات الـFrozen artifact snapshot أو حالة الـBaseline، فلا يُفترض التطابق ولا الاختلاف، وتكون النتيجة `OWNER DECISION REQUIRED — VERSION BASELINE STATUS` بدل التخمين. أما known mismatch المثبت فينتج `Resolution Status = INVALID` ولا يمكن أن تكتمل معه Adoption أو Upgrade بحالة `VALID`.

عدم توفر exact upstream Adoption Commit المطلوب لمراجعة أو تنفيذ Structural / Transitive Resolution أو Canonical Standard Applicability يُصنف كفشل تحقق fail-closed، وتكون نتيجته حتمًا `Resolution Status = INVALID`. لا تنشأ عن هذه الحالة Exception؛ وتكون `Exception State = NONE` ما لم توجد deviation مستقلة أخرى تنطبق عليها دورة §16.4. هذا لا يعني أن Standard نفسها مفقودة أو أن محتوى الـCommit broken؛ يعني فقط أن required pinned verification input غير متاح، فلا يمكن إثبات resolution المطلوبة. هذه الحالة ليست Owner Decision أو deviation أو Exception قابلة للموافقة، ولا يملك المالك تحويلها إلى `VALID`. لا تستخدم لها floating ref أو fallback source أو تعريفات مخمّنة. مسار الاستعادة الوحيد هو إتاحة exact Commit ثم إعادة تنفيذ Resolution / Validation وإعادة تقييم الحالة.

كل Structural Invalidity تنتج `Resolution Status = INVALID`. لا يمكن لـOwner Approval تحويل graph أو structure مكسورة إلى `VALID`، ولا يعد قرار المالك إصلاحًا بنيويًا:

```text
Owner Decision
≠
Structural Repair
```

المسار الوحيد لإزالة Structural Invalidity هو:

```text
Detect Structural Invalidity
→ Repair Graph / Structure
→ Re-resolve
→ Re-evaluate
```

لا تنشئ Structural Invalidity نفسها Exception؛ ويظل `Exception State = NONE` ما لم توجد deviation أخرى مستقلة وقابلة للاستثناء أصلًا.

والاستبعاد deterministic وفق canonical Standard applicability في Stage 2 ليس Structural Invalidity أو deviation أو Exception، ولا يحتاج إلى طلب أو موافقة Exception؛ يظل `Exception State = NONE` ما لم توجد deviation مستقلة أخرى.

### 16.4 Exception State ودورة حياة الاستثناء

القيم الوحيدة لـ`Exception State` لكل deviation/Activation ذات صلة هي:

```text
NONE
REQUESTED
APPROVED_AND_DOCUMENTED
```

هذه الحالة مستقلة عن `Resolution Status` ولا تستبدلها. لا تنشأ قيمة Aggregate أو حالة رابعة عند وجود عدة Activations أو Exceptions؛ تحتفظ كل واحدة بحالتها، ثم تطبق قواعد التجميع في §16.2.

تقتصر دورة الاستثناء على deviation أو override يسمح هذا العقد باستثنائها أصلًا، ولا تشمل Structural Invalidity:

- **Unauthorized deviation:** إذا طُبق deviation قبل استيفاء مسار Request وApproval الصالح، بما في ذلك تطبيق Exception ما زالت `REQUESTED`، تكون النتيجة `Resolution Status = INVALID` و`Exception State = NONE`. لا يحول الاستخدام الفعلي غير المصرح به إلى Exception معتمدة.
- **Requested exception:** يبقى الاستثناء غير المطبق قبل القرار في `Resolution Status = OWNER DECISION REQUIRED` و`Exception State = REQUESTED`. الطلب ليس موافقة؛ لا تطبق الـdeviation ولا تعتبر Adoption مكتملة قبله. والموافقة وحدها دون توثيق السبب والنطاق والسلطة لا تحقق `APPROVED_AND_DOCUMENTED` ولا تسمح بالتطبيق أو الاكتمال؛ إذا لم تطبق الـdeviation بعد، تبقى النتيجة `OWNER DECISION REQUIRED` والحالة `REQUESTED` حتى تكتمل متطلبات الاستثناء. وإذا طُبقت قبل ذلك، تنطبق قاعدة `Unauthorized deviation` أعلاه.
- **Approved exception:** بعد موافقة المالك وتوثيق سبب الاستثناء ونطاقه والسلطة المعتمدة له، تكون `Exception State = APPROVED_AND_DOCUMENTED`. يمكن أن تكون `Resolution Status = VALID` فقط عندما تكون graph سليمة، ولا توجد Structural Invalidity أو Owner Decisions أخرى معلقة، ويسجل الاستثناء في Manifest عند اكتمال Adoption.

إذا كان المطلوب Owner Decision لا يتعلق بطلب Exception، مثل ambiguity غير محسومة، تكون النتيجة `Resolution Status = OWNER DECISION REQUIRED` و`Exception State = NONE`.

### 16.5 حدود canonical Manifest أثناء Adoption وUpgrade

`STANDARDS_MANIFEST.md` هو record لاعتماد مكتمل فقط. لا ينشأ ولا يحدث ليعرض proposed state تكون `Overall Resolution Status` فيها `INVALID` أو `OWNER DECISION REQUIRED`. لا تنشأ Draft Manifest أو أداة/ملف بديل لها؛ تحفظ الحالات المقترحة والقرارات غير المحسومة في review evidence فقط.

إذا كان للمشروع Manifest سابقة تمثل Adoption صحيحة، ثم أصبحت Upgrade مقترحة `INVALID` أو `OWNER DECISION REQUIRED`، تبقى Manifest المعتمدة الحالية كما هي ولا تستبدل بالحالة المقترحة. لا تثبت Manifest السابقة صلاحية الـUpgrade الجديدة. لا تستبدل بها إلا بعد أن تصبح نتيجة الـUpgrade الجديدة `VALID` وتكتمل Adoption.

لا يضيف هذا القسم حقولًا جديدة إلى Manifest؛ يسجل الحقول القائمة النتيجة المكتملة والاستثناءات المعتمدة والموثقة فقط.

### 16.6 Fail-Closed Review Contract

في Adoption أو Upgrade أو Manifest Validation، يسير الفحص بالترتيب التالي:

1. تنفيذ Stage-1 Structural / Transitive Resolution فعليًا من ملفات Profiles المحلية المثبتة، مع حل inheritance وإنتاج Candidate Standard References.
2. التحقق من Stage-1 structural candidate/reference integrity قبل applicability: سلامة Profiles و`Extends`، ووجود Required Standard references upstream، وسلامة Explicit Additional Standard references، وعدم وجود cycle أو broken structural candidate reference.
3. تنفيذ Stage-2 Canonical Standard Applicability لكل Candidate Standard مقابل كل Activation/Scope وحقائق الـartifact، دون أن يوسع Profile أو Additional Standard applicability المملوكة للـStandard.
4. بناء `Final Resolved Applicable Standards Set` من المرشحين التي انطبقت عليها قواعدها canonical بعد اكتمال Stage 2.
5. التحقق من `final local reference closure` بعد تكوين Final Set، مقابل `Pinned Adoption Control Set` و`Final Resolved Applicable Standards Set`. فشل closure هو `Structural Invalidity` و`Resolution Status = INVALID`; لا تُقبل `Reference Support Set` ولا نسخة Standard غير منطبقة كحل.
6. إذا كانت `Pinned Operational Assets Set` موجودة، التحقق fail-closed من الـheading/schema والـKind والمسارات ووجود الملفات ومطابقة raw-content SHA-256 مع canonical upstream path على exact authoritative source identity (الـexact Adoption Commit افتراضيًا، أو exact alternate canonical commit/source identity المصرح بها صراحةً في استثناء معتمد وموثق `APPROVED_AND_DOCUMENTED`). غياب المجموعة يعني عدم اعتماد Operational Assets ولا يعد failure.
7. التحقق من Frozen Version Baseline وartifact snapshot لكل Version مجمدة منطبقة؛ known frozen-version/content mismatch ينتج `Resolution Status = INVALID`، وتعذر إثبات الـsnapshot أو الـBaseline ينتج `OWNER DECISION REQUIRED — VERSION BASELINE STATUS`. لا يعتبر اختلاف repository commit وحده mismatch.
8. تشخيص كل Activation/Scope على حدة، بما في ذلك نتيجة applicability؛ والاستبعاد deterministic لا ينتج حالة Invalid أو Exception.
9. تقييم الاستثناءات المسموح بها وحالتها، دون تطبيق أي Requested Exception.
10. حساب Overall Resolution Status بقواعد §16.2.
11. عدم اعتبار Adoption مكتملة إلا إذا كانت `Overall Resolution Status = VALID`.

`OWNER DECISION REQUIRED` ليست نجاحًا، و`REQUESTED` ليست موافقة، و`APPROVED_AND_DOCUMENTED` لا تصلح Structural Invalidity.


## 17. سجل تغييرات المعيار

> **توضيح مرجعي (Issue #149):** كل معرف `DEC-###` في هذا السجل التاريخي يشير إلى قرار مركزي في `Maatify/php-engineering-standards` وليس إلى قرار محلي للمستهلك. هذا التوضيح لا يعيد كتابة التاريخ ولا يغير نطاق أي إصدار مذكور.

### `4.1.0`

- تطبيق `DEC-031`: إضافة `Pinned Operational Assets Set` كطبقة اختيارية/مشروطة غير معيارية بجانب Control Set وApplicable Standards Set، مع بقاء غيابها حالة صحيحة تعني عدم اعتماد Operational Assets.
- تثبيت inventory file-level بالـheading exact والحقول `Kind` / `Upstream Path` / `Local Path` / `SHA-256`، وحصر الـKind في `skill` و`deterministic-helper` و`provider-adapter`.
- تثبيت same exact Adoption Commit افتراضيًا وإتاحة التحقق الميكانيكي من exact alternate source identity المصرح بها صراحةً في استثناء معتمد وموثق (`APPROVED_AND_DOCUMENTED`)، مع إبقاء التحقق من raw-content SHA-256 إلزاميًا وfail-closed عند أي mismatch فعلي، دون إدخال Operational Assets في Stage 1/Stage 2 أو Profiles أو normative ownership ودون حقول إضافية في الـinventory.
- الحفاظ على `STANDARDS_MANIFEST.md` كـLocal Resolver / Inventory Record فقط، وعلى صلاحية المستهلكين الحاليين الذين لا يعتمدون Operational Assets.
- التصنيف `NORMATIVE / BACKWARD_COMPATIBLE_CONTRACT_CHANGE / Minor` من Frozen Version Baseline `4.0.0` (blob `d84856f7cd50112b32d6843989700a819e6bad98`) إلى Mutable Candidate `4.1.0`.

### `5.0.0`

- اعتماد `DEC-032` وتحديد سجل حتمي قابل للتحقق آليًا لاستثناء Operational Asset mixed-pinning، مع ربط كل سجل بقرار مستهلك نشط ومحدد للزوج الدقيق والمسار والمصدر.
- التصنيف `NORMATIVE / BREAKING_CONTRACT_CHANGE / Major` من Frozen Version Baseline `4.0.0` (blob `d84856f7cd50112b32d6843989700a819e6bad98`) إلى Mutable Candidate `5.0.0`. السجلات الحرة التي لم تحقق العقد السابق بوضوح تحتاج إلى ترحيل قبل أن تقبل كاستثناء آلي؛ لا يفترض هذا التصنيف جردًا شاملًا للمستهلكين الخارجيين.

### `5.0.1`

- Issue #149: توضيح مرجعي فقط لمعرفات `DEC-###` لتشير صراحةً إلى قرارات `Maatify/php-engineering-standards` المركزية لا إلى قرارات المستهلك المحلية (§9.1 وملاحظة السجل التاريخي)، دون تغيير أي Obligation أو Permission ودون اعتماد على `docs/decisions/` داخل المستهلك ودون تعديل سجل `5.0.0` التاريخي.
- التصنيف `CLARIFICATION / NO_CONTRACT_CHANGE / Patch` من Frozen Version Baseline `5.0.0` (blob `05e37ef12bacb8cb7c0b7bfcfe9633462a868c99`) إلى Mutable Candidate `5.0.1`. لا Freeze ولا Version Finalization ولا Adoption.

### `5.0.2`

- ثبتت `5.0.1` كـFrozen Version Baseline في ترقية مكتملة بحالة `VALID`، بالـblob `071b76486a6d44f7828d0b762d2e2d8d8fa2ad2e` عند Adoption Commit `1f560795397f24bddcc3d09b982ff93a77f7154e`. [دليل الترقية المكتملة](https://github.com/Maatify-ltd/php-engineering-standards/pull/210#issuecomment-7114166541)
- صحّح PR #205 ضمن Issue #204 مسار المستودع المركزي في §1 وفي إشارة `DEC-032` في §9.1 إلى `Maatify-ltd/php-engineering-standards`. المسار السابق `Maatify/php-engineering-standards` يحيل إلى المستودع نفسه (repository ID `1304138984`). كان التغيير مسجلًا تحت `5.0.1` في snapshot `6f3cd481f09d6f00aebd4ddedeab8d7ea656e044` (blob `7f4561ebad78804cf89551b214ece891100fcb78`). يصحح هذا القيد إسناده إلى الإصدار المناسب بعد التجميد.
- تصنيف الأثر التراكمي `CLARIFICATION / NO_CONTRACT_CHANGE / Patch`، لأن تصحيح المسار لم يغير هوية المصدر أو عقد الاعتماد أو Manifest schema. الناتج `5.0.2` بوصفه Mutable Candidate محسوبة من `5.0.1` المجمدة. يظل إثبات أي Freeze جديدة مرتبطًا باعتماد أو ترقية مكتملة بحالة `VALID`.
