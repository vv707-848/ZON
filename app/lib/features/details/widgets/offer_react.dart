// Badge on the gallery photo and the user's two actions in the top bar:
// إعجاب (like) and بلاغ (report).

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/store.dart';

/// Pill pinned on the gallery photo: "للبيع" / "للإيجار", the unit type, or
/// the red "تم البيع" stamp when [sold].
class DetailSeal extends StatelessWidget {
  const DetailSeal(this.text, {super.key, this.sold = false});

  final String text;
  final bool sold;

  @override
  Widget build(BuildContext context) {
    const red = Color(0xFFD92D20);
    return Container(
      height: 28,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: sold ? AppColors.white : AppColors.forest,
        borderRadius: BorderRadius.circular(999),
        border: sold ? Border.all(color: red, width: 1.5) : null,
        boxShadow: AppShadows.raised,
      ),
      alignment: Alignment.center,
      child: Tx(text, style: ts(13, w: 600, lh: 19.5, c: sold ? red : AppColors.white)),
    );
  }
}

/// Like + report buttons for the detail top bar.
///
/// * Like toggles the offer in the saved likes (persisted in the store).
/// * Report opens a dialog asking for the reason; after sending, the flag is
///   replaced by a small "وصل البلاغ" confirmation.
class OfferReact extends StatefulWidget {
  const OfferReact({super.key, required this.id});

  /// Listing / project / unit id.
  final String id;

  @override
  State<OfferReact> createState() => _OfferReactState();
}

class _OfferReactState extends State<OfferReact> {
  late bool _liked = store.likedSet().contains(widget.id);
  bool _reported = false;

  void _toggleLike() {
    final next = store.likedSet();
    if (!next.remove(widget.id)) next.add(widget.id);
    store.persistLikes(next);
    setState(() => _liked = next.contains(widget.id));
  }

  Future<void> _report() async {
    final sent = await showReportDialog(context);
    if (sent && mounted) setState(() => _reported = true);
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (_reported)
          Container(
            height: 30,
            padding: const EdgeInsets.symmetric(horizontal: 10),
            decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(999)),
            alignment: Alignment.center,
            child: Tx('وصل البلاغ', style: ts(13, w: 600, lh: 19.5, c: AppColors.success)),
          )
        else
          BarIconButton(icon: Lu.flag, label: 'بلاغ', onTap: _report),
        const SizedBox(width: 4),
        BarIconButton(
          icon: Lu.heart,
          label: 'إعجاب',
          color: _liked ? AppColors.liked : AppColors.deep,
          fill: _liked ? AppColors.liked : null,
          onTap: _toggleLike,
        ),
      ],
    );
  }
}

/// A 44×44 icon button for the top bar.
class BarIconButton extends StatelessWidget {
  const BarIconButton({super.key, required this.icon, required this.label, required this.onTap, this.color = AppColors.deep, this.fill});

  final String icon;

  /// Accessibility label (read by screen readers).
  final String label;
  final VoidCallback onTap;
  final Color color;
  final Color? fill;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      child: Tap(
        onTap: onTap,
        child: SizedBox(
          width: 44,
          height: 44,
          child: Center(
            child: LucideIcon(icon, size: 22, strokeWidth: 1.9, color: color, fill: fill),
          ),
        ),
      ),
    );
  }
}

/// Asks for the report reason. Resolves to `true` when a report was sent.
Future<bool> showReportDialog(BuildContext context) async {
  final sent = await showGeneralDialog<bool>(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'بلاغ',
    barrierColor: const Color(0x661C2B24),
    transitionDuration: const Duration(milliseconds: 160),
    transitionBuilder: (ctx, anim, _, child) => FadeTransition(opacity: anim, child: child),
    pageBuilder: (ctx, a, b) => const _ReportDialog(),
  );
  return sent ?? false;
}

class _ReportDialog extends StatefulWidget {
  const _ReportDialog();

  @override
  State<_ReportDialog> createState() => _ReportDialogState();
}

class _ReportDialogState extends State<_ReportDialog> {
  final TextEditingController _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  bool get _ready => _reason.text.trim().isNotEmpty;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 340),
            child: Material(
              type: MaterialType.transparency,
              child: DefaultTextStyle(
                style: baseTextStyle(),
                child: Container(
                  padding: const EdgeInsets.fromLTRB(16, 18, 16, 16),
                  decoration: BoxDecoration(color: AppColors.white, borderRadius: BorderRadius.circular(22), boxShadow: AppShadows.modal),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: <Widget>[
                      Row(
                        children: <Widget>[
                          Container(
                            width: 36,
                            height: 36,
                            decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(12)),
                            alignment: Alignment.center,
                            child: const LucideIcon(Lu.flag, size: 18, strokeWidth: 2, color: AppColors.forest),
                          ),
                          const SizedBox(width: 10),
                          Tx('الإبلاغ عن العرض', style: ts(16, w: 600, lh: 24, c: AppColors.deep)),
                        ],
                      ),
                      const SizedBox(height: 14),
                      Container(
                        height: 104,
                        decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(16)),
                        child: TextField(
                          controller: _reason,
                          autofocus: true,
                          maxLines: null,
                          expands: true,
                          textAlignVertical: TextAlignVertical.top,
                          onChanged: (_) => setState(() {}),
                          style: ts(15, lh: 22.5, c: AppColors.deep),
                          decoration: InputDecoration(
                            isCollapsed: true,
                            border: InputBorder.none,
                            contentPadding: const EdgeInsets.all(12),
                            hintText: 'اكتب سبب الإبلاغ',
                            hintStyle: ts(15, lh: 22.5, c: const Color(0xFF8F9892)),
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Row(
                        children: <Widget>[
                          Expanded(child: _ReportButton('إلغاء', AppColors.tint, AppColors.deep, () => Navigator.of(context).pop(false))),
                          const SizedBox(width: 8),
                          Expanded(
                            child: _ReportButton(
                              'إرسال البلاغ',
                              _ready ? AppColors.forest : AppColors.forest.withValues(alpha: 0.4),
                              AppColors.white,
                              () {
                                if (_ready) Navigator.of(context).pop(true);
                              },
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ReportButton extends StatelessWidget {
  const _ReportButton(this.label, this.bg, this.fg, this.onTap);

  final String label;
  final Color bg;
  final Color fg;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        height: 44,
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(14)),
        alignment: Alignment.center,
        child: Tx(label, style: ts(15, w: 600, lh: 22.5, c: fg)),
      ),
    );
  }
}
