// The advertising licence number of ONE project unit (brief L01).
//
// Shown under each unit of a ready project (hidden for off-plan projects).
// Every unit keeps its own number and its own verification state; changing
// one unit's number never changes another unit.

import 'package:flutter/widgets.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';
import 'package:zon/features/add/widgets/form_inputs.dart';

/// Licence number + verify button + state of one unit.
class UnitLicenceField extends StatelessWidget {
  const UnitLicenceField({
    super.key,
    required this.value,
    required this.verified,
    required this.onChanged,
    required this.onVerify,
    this.missing = false,
  });

  final String value;
  final bool verified;

  /// Receives the digits only (Arabic digits converted).
  final ValueChanged<String> onChanged;
  final VoidCallback onVerify;

  /// Highlights the field after a publish attempt without a verified number.
  final bool missing;

  @override
  Widget build(BuildContext context) {
    final canVerify = licenceFormatOk(value);
    final Widget state = verified
        ? Row(
            children: <Widget>[
              const LucideIcon(Lu.badgeCheck, size: 16, strokeWidth: 2, color: AppColors.forest),
              const SizedBox(width: 6),
              Expanded(
                child: Tx('تم التحقق من رقم هذه الوحدة', style: ts(13, w: 600, lh: 19.5, c: AppColors.forest)),
              ),
            ],
          )
        : Tx(
            missing ? 'مطلوب: أدخل رقم ترخيص هذه الوحدة ثم تحقق منه' : 'لكل وحدة رقمها — أدخله ثم تحقق',
            style: ts(13, w: 600, lh: 19.5, c: missing ? AppColors.danger : kAddIc),
          );
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: kStoneBg,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: missing && !verified ? AppColors.danger : const Color(0x00000000), width: 1.2),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              const LucideIcon(Lu.shieldCheck, size: 18, strokeWidth: 1.8, color: AppColors.forest),
              const SizedBox(width: 6),
              Expanded(
                child: Tx('رقم الترخيص الإعلاني للوحدة', style: ts(14, w: 600, lh: 21, c: AppColors.deep)),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: <Widget>[
              Expanded(
                child: projInput(
                  value: value,
                  placeholder: '72001122',
                  dir: TextDirection.ltr,
                  keyboard: TextInputType.number,
                  onChanged: (v) => onChanged(digitsOnly(v)),
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(
                width: 96,
                child: AppButton(
                  label: verified ? 'تم' : 'تحقق',
                  tone: verified ? ButtonTone.tonal : ButtonTone.primary,
                  icon: verified ? Lu.check : null,
                  onTap: verified || !canVerify ? null : onVerify,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          state,
        ],
      ),
    );
  }
}
