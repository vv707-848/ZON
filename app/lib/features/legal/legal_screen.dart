// `/legal/:slug` — policy pages (payments, privacy, IP, terms, REGA).

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';

const Map<String, List<String>> _copy = <String, List<String>>{
  'payments': <String>[
    'سياسة الدفع',
    'الباقات تُدفع سنوياً أو شهرياً داخل المنصة. لا عمولة بيع أو تأجير على الإعلان. المبالغ غير شاملة ضريبة القيمة المضافة إن وُجدت لاحقاً.',
  ],
  'privacy': <String>[
    'سياسة الخصوصية',
    'زون تجمع بيانات الحساب والعقار لإتمام الوساطة والإعلان وفق نظام الهيئة العامة للعقار. لا تُشارك بيانات المالك الخاصة خارج إدارة الملاك لدى الوسيط.',
  ],
  'ip': <String>[
    'سياسة حقوق الملكية والفكرية',
    'الصور والوصف ملك المعلن. زون تحتفظ بترخيص عرضها داخل المنصة. يُمنع نسخ بيانات الباحثين أو الوسطاء لأغراض خارج التطبيق.',
  ],
  'terms': <String>[
    'أحكام وشروط استخدام المنصة',
    'استخدام زون يعني الالتزام بأن بيانات العرض والطلب صحيحة، وأن المعلن مرخّص أو يصدر ترخيصاً إعلانياً عبر المنصة عند الحاجة.',
  ],
  'rega': <String>[
    'ضوابط الإعلانات العقارية',
    'كل إعلان فردي يعرض رمز QR للتحقق لدى الهيئة العامة للعقار. مشاريع المساهمة قد تُستثنى من رقم الترخيص الإعلاني وفق النظام.',
  ],
};

/// `/legal/$slug` — policy pages.
class LegalScreen extends StatelessWidget {
  const LegalScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) {
    final row = _copy[slug] ?? const <String>['سياسة', '—'];
    return AppShell(
      title: row[0],
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            BackLink(label: 'العودة للإعدادات', onTap: () => Nav.go(context, '/account/settings')),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(color: AppColors.surface, borderRadius: BorderRadius.circular(24), boxShadow: AppShadows.hairline),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Tx(row[0], style: ts(20, w: 700, lh: 28, c: AppColors.ink)),
                  const SizedBox(height: 12),
                  Tx(row[1], style: ts(15, lh: 30, c: AppColors.ink)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
