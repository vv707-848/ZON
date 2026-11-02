// Advertising licence card: number input, verify button and the QR preview.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';
import 'package:zon/features/add/widgets/form_inputs.dart';
import 'package:zon/features/details/widgets/detail_widgets.dart' show LicenseQr;

/// The advertising licence number with its verify button.
///
/// Once verified ([ok]) the QR that will appear on the listing is previewed.
/// [plain] is the large card of the full-page licence step; otherwise the
/// compact card inside a form.
class LicCard extends StatelessWidget {
  const LicCard({
    super.key,
    required this.value,
    required this.onChanged,
    required this.ok,
    required this.onVerify,
    required this.qrBackground,
    this.plain = false,
  });

  final String value;
  final ValueChanged<String> onChanged;
  final bool ok;
  final VoidCallback onVerify;
  final Color qrBackground;
  final bool plain;

  @override
  Widget build(BuildContext context) {
    final input = plain
        ? CtlField(
            value: value,
            onChanged: onChanged,
            style: ts(22, w: 700, lh: 32, c: AppColors.deep),
            placeholder: '72001122',
            placeholderColor: kAddPh,
            height: 36,
            padding: EdgeInsets.zero,
            dir: TextDirection.ltr,
            textAlign: TextAlign.right,
            keyboardType: TextInputType.number,
          )
        : CtlField(
            value: value,
            onChanged: onChanged,
            style: kProjText,
            placeholder: '72001122',
            placeholderColor: kAddPh,
            placeholderWeight: 500,
            height: 48,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: kProjBox,
            dir: TextDirection.ltr,
            textAlign: TextAlign.right,
            keyboardType: TextInputType.number,
          );
    final copy = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Tx('رقم الترخيص الإعلاني', style: ts(14, w: 600, lh: 21, c: AppColors.deep), softWrap: false),
        const SizedBox(height: 2),
        input,
      ],
    );
    final top = Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: kStoneBg, borderRadius: BorderRadius.circular(plain ? 22 : 18)),
      child: Row(
        children: <Widget>[
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: AppColors.forest, borderRadius: BorderRadius.circular(14)),
            alignment: Alignment.center,
            child: const LucideIcon(Lu.shieldCheck, size: 20, strokeWidth: 1.75, color: AppColors.white),
          ),
          const SizedBox(width: 12),
          Expanded(child: copy),
        ],
      ),
    );
    final disabled = value.length < 4;
    final Widget status = ok
        ? Row(
            children: <Widget>[
              LicenseQr(value: value, size: 56, background: qrBackground, padding: 4, radius: 6),
              const SizedBox(width: 10),
              Expanded(
                child: Tx('تم التحقق — سيظهر الرمز على العرض', style: ts(14, w: 600, lh: 21, c: AppColors.deep)),
              ),
            ],
          )
        : Tx('أدخل الرقم ثم تحقق ليظهر كرمز QR على العرض.', style: ts(14, w: 500, lh: 20, c: kAddIc));
    final gap = plain ? 12.0 : 10.0;
    return Container(
      padding: EdgeInsets.all(plain ? 16 : 12),
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: BorderRadius.circular(plain ? 28 : 20),
        border: Border.all(color: plain ? AppColors.mistLine : kAddBoxLine),
        boxShadow: plain ? AppShadows.card : null,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          top,
          SizedBox(height: gap),
          status,
          SizedBox(height: gap),
          AppButton(
            label: ok ? 'تم التحقق' : 'تحقق',
            tone: ok ? ButtonTone.tonal : ButtonTone.primary,
            icon: ok ? Lu.check : null,
            onTap: disabled ? null : onVerify,
          ),
        ],
      ),
    );
  }
}
