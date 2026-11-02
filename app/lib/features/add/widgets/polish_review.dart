// AI wording review: the last step of the add forms, where the user compares
// their own description with the AI wording and accepts or rejects it.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';
import 'package:zon/features/add/widgets/form_inputs.dart';
import 'package:zon/features/add/widgets/form_layout.dart';

/// Fill of the user's own text block.
const Color _ownFill = Color(0xFFF6F8F7);

/// Fill and ring of the AI text block.
const Color _aiFill = Color(0xFFF1F6F3);
const Color _aiRing = Color(0xFFBFD3C6);

/// Ring of a block that is waiting for a verdict.
const Color _waitRing = Color(0xFFDCE3DE);

/// The review page: back link, heading, optional [summary], the text blocks
/// ([children]) and two [actions] side by side.
class PolishStage extends StatelessWidget {
  const PolishStage({
    super.key,
    required this.onBack,
    required this.title,
    required this.lead,
    required this.children,
    required this.actions,
    this.summary,
  });

  final VoidCallback onBack;
  final String title;
  final String lead;
  final Widget? summary;
  final List<Widget> children;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          BackLink(onTap: onBack),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.fromLTRB(16, 18, 16, 16),
            decoration: BoxDecoration(
              color: AppColors.white,
              borderRadius: BorderRadius.circular(24),
              border: Border.all(color: AppColors.mistLine),
              boxShadow: AppShadows.card,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    const LucideIcon(Lu.sparkles, size: 14, strokeWidth: 2, color: AppColors.success),
                    const SizedBox(width: 6),
                    Tx('مراجعة الذكاء', style: ts(13, w: 600, lh: 19.5, c: AppColors.success)),
                  ],
                ),
                const SizedBox(height: 6),
                Tx(title, style: ts(22, w: 700, lh: 32, c: AppColors.deep)),
                const SizedBox(height: 4),
                Tx(lead, style: ts(14, w: 500, lh: 21, c: AppColors.muted)),
                const SizedBox(height: 14),
                if (summary != null) ...<Widget>[summary!, const SizedBox(height: 12)],
                ...children,
                const SizedBox(height: 18),
                equalRow(actions, columns: 2, gap: 8),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Small label above a text block (with the sparkles icon for the AI block).
Widget _blockLabel(String text, {bool spark = false}) => Row(
  children: <Widget>[
    if (spark) ...<Widget>[const LucideIcon(Lu.sparkles, size: 14, color: AppColors.forest), const SizedBox(width: 6)],
    Flexible(
      child: Tx(text, style: ts(12, w: 700, lh: 18, c: AppColors.stone)),
    ),
  ],
);

/// The paragraph of a text block (nothing when empty).
Widget _blockText(String text) => text.isEmpty ? const SizedBox.shrink() : Tx(text, style: ts(16, w: 500, lh: 28, c: AppColors.deep));

/// One text block of the listing review: the user's text, or the AI wording
/// ([ai] tints it green).
class PolishCol extends StatelessWidget {
  const PolishCol({super.key, required this.label, required this.text, this.ai = false});

  final String label;
  final String text;
  final bool ai;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: ai ? _aiFill : _ownFill,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: ai ? _aiRing : _waitRing),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          _blockLabel(label, spark: ai),
          const SizedBox(height: 8),
          _blockText(text),
        ],
      ),
    );
  }
}

/// Verdict of one text block in the project review.
enum Verdict { pending, ok, no }

/// One block of the project review (the project text or a unit text) with
/// its verdict pill and the موافق / رفض / تعديل buttons.
class PolishUnit extends StatelessWidget {
  const PolishUnit({
    super.key,
    required this.label,
    required this.spark,
    required this.ai,
    required this.verdict,
    required this.editing,
    required this.text,
    required this.editValue,
    required this.onEditChanged,
    required this.onOk,
    required this.onNo,
    required this.onEdit,
  });

  final String label;
  final bool spark;
  final bool ai;
  final Verdict verdict;
  final bool editing;
  final String text;
  final String editValue;
  final ValueChanged<String> onEditChanged;
  final VoidCallback onOk;
  final VoidCallback onNo;
  final VoidCallback onEdit;

  /// Arabic label of a verdict.
  static String labelOf(Verdict v) => switch (v) {
    Verdict.ok => 'موافق',
    Verdict.no => 'مرفوض',
    Verdict.pending => 'بانتظار',
  };

  @override
  Widget build(BuildContext context) {
    final okV = verdict == Verdict.ok;
    final noV = verdict == Verdict.no;
    final pill = Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: okV ? AppColors.forest : (noV ? AppColors.white : AppColors.mist),
        borderRadius: BorderRadius.circular(999),
        border: noV ? Border.all(color: AppColors.forest) : null,
      ),
      child: Tx(labelOf(verdict), style: ts(12, w: 700, lh: 18, c: okV ? AppColors.white : (noV ? AppColors.forest : AppColors.stone))),
    );
    Widget action(String label, {String? icon, required bool filled, required VoidCallback onTap}) => Tap(
      onTap: onTap,
      child: Container(
        height: 40,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        decoration: BoxDecoration(
          color: filled ? AppColors.forest : AppColors.white,
          borderRadius: BorderRadius.circular(12),
          border: filled ? null : Border.all(color: kGhostLine),
        ),
        alignment: Alignment.center,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (icon != null) ...<Widget>[LucideIcon(icon, size: 14, color: filled ? AppColors.white : AppColors.forest), const SizedBox(width: 4)],
            Flexible(
              child: Tx(label, style: ts(13, w: 700, lh: 19.5, c: filled ? AppColors.white : AppColors.forest), softWrap: false),
            ),
          ],
        ),
      ),
    );
    final card = Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: ai ? _aiFill : _ownFill,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: okV ? AppColors.forest : (ai ? _aiRing : _waitRing)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(child: _blockLabel(label, spark: spark)),
              const SizedBox(width: 8),
              pill,
            ],
          ),
          const SizedBox(height: 10),
          if (editing)
            CtlField(
              value: editValue,
              onChanged: onEditChanged,
              style: ts(15, w: 500, lh: 22.5, c: AppColors.ink),
              height: 88,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: AppColors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: _waitRing),
              ),
              multiline: true,
            )
          else
            _blockText(text),
          const SizedBox(height: 14),
          equalRow(
            <Widget>[
              action('موافق', icon: Lu.check, filled: true, onTap: onOk),
              action('رفض', icon: Lu.x, filled: noV, onTap: onNo),
              action(editing ? 'حفظ التعديل' : 'تعديل', filled: false, onTap: onEdit),
            ],
            columns: 3,
            gap: 6,
          ),
        ],
      ),
    );
    return noV ? Opacity(opacity: 0.78, child: card) : card;
  }
}
