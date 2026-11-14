// `/legal/:slug` — policy pages (payments, privacy, IP, terms, REGA). With a server (ServerLegal) the published
// text from «صفحات السياسات» in the panel, the same as on zon.sa; a draft is not shown. Without one, the copy below.

import 'dart:async';

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/net/api.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/dates.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/server/server_legal.dart';

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

/// Where «السياسات والأحكام» are.
const String kLegalHome = '/account/settings?tab=legal';

/// `/legal/$slug` — policy pages: the server's published text when the app has one, else the device's copy.
class LegalScreen extends StatelessWidget {
  const LegalScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) {
    if (Api.on) return ServerLegalScreen(slug: slug);
    final row = _copy[slug] ?? const <String>['سياسة', '—'];
    return _LegalFrame(
      title: row[0],
      children: <Widget>[Tx(row[1], style: ts(15, lh: 30, c: AppColors.ink))],
    );
  }
}

/// A policy page as the server publishes it — the same text as zon.sa/legal/<slug>, with its version and date.
class ServerLegalScreen extends StatefulWidget {
  const ServerLegalScreen({super.key, required this.slug});

  final String slug;

  @override
  State<ServerLegalScreen> createState() => _ServerLegalScreenState();
}

class _ServerLegalScreenState extends State<ServerLegalScreen> {
  LegalPage? _page;
  String? _error;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    try {
      final page = await ServerLegal.page(widget.slug);
      if (mounted) setState(() => _page = page);
    } on ApiError catch (e) {
      if (mounted) setState(() => _error = e.status == 404 ? 'هذه الصفحة غير منشورة.' : e.forUser);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    if (page == null) {
      return _LegalFrame(
        title: 'السياسات والأحكام',
        children: <Widget>[if (_error case final String e) FormError(e) else const LoadingBox()],
      );
    }
    final at = page.publishedAt;
    return _LegalFrame(
      title: page.title,
      meta: <String>[if (page.version != null) 'الإصدار ${page.version}', if (at != null) 'نُشر ${arDate(at, year: true)}'].join(' · '),
      children: <Widget>[
        for (final b in legalBlocks(page.body))
          switch (b.kind) {
            'h' => Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Tx(b.text, style: ts(17, w: 700, lh: 26, c: AppColors.ink)),
            ),
            'li' => Tx(RegExp(r'^\d').hasMatch(b.text) ? b.text : '•  ${b.text}', style: ts(15, lh: 28, c: AppColors.ink)),
            _ => Tx(b.text, style: ts(15, lh: 30, c: AppColors.ink)),
          },
      ],
    );
  }
}

class _LegalFrame extends StatelessWidget {
  const _LegalFrame({required this.title, required this.children, this.meta = ''});

  final String title;
  final String meta;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return AppShell(
      title: title,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            BackLink(label: 'العودة للإعدادات', onTap: () => Nav.go(context, kLegalHome)),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(color: AppColors.surface, borderRadius: BorderRadius.circular(24), boxShadow: AppShadows.hairline),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Tx(title, style: ts(20, w: 700, lh: 28, c: AppColors.ink)),
                  if (meta.isNotEmpty) ...<Widget>[const SizedBox(height: 4), Tx(meta, style: ts(12, lh: 18, c: AppColors.muted))],
                  const SizedBox(height: 12),
                  for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 10), children[i]],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
