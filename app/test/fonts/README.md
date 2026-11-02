# بدائل الخط في الاختبارات فقط

خط التطبيق هو خط النظام: **San Francisco** على iPhone (SF Pro للحروف اللاتينية والأرقام، وSF Arabic للعربية). محرك الاختبار وأداة الصور لا يملكان خط النظام، ورخصة آبل لا تسمح بنسخ SF، لذلك تُقاس النصوص في `flutter test` وفي أداة الصور ببدائل مرخّصة متقاربة في المقاس:

| الملف | بديل عن | الرخصة |
|---|---|---|
| `Inter-{Regular,Medium,SemiBold,Bold}.otf` | SF Pro (اللاتيني والأرقام) — نسخة مقلّصة إلى الحروف اللاتينية وعلامات الترقيم | SIL OFL 1.1 — `OFL-Inter.txt` |
| `NotoSansArabic-{Regular,Medium,SemiBold,Bold}.ttf` | SF Arabic — نسخ ثابتة الوزن من الخط المتغير | SIL OFL 1.1 — `OFL-NotoSansArabic.txt` |

يسجّلها `test/flutter_test_config.dart` تحت اسمَي خط التطبيق (`CupertinoSystemText` و`CupertinoSystemDisplay`) ويضيف العربي بعدها. **ليست جزءًا من التطبيق** ولا تُضمَّن في نسخة المتجر؛ الشكل الحقيقي يُراجع على iPhone.
