// Date input of the forms (e.g. the owner's birth date in the licence step).

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';

/// Shows the picked date as `dd/mm/yyyy` (or [hint]) and opens the platform
/// date picker on tap: a Cupertino wheel on iOS, the Material calendar on
/// Android. The value is stored as `yyyy-mm-dd` (empty when not picked).
class DateField extends StatelessWidget {
  const DateField({
    super.key,
    required this.value,
    required this.onChanged,
    required this.decoration,
    required this.style,
    this.height = 44,
    this.hint = 'اختر التاريخ',
  });

  /// `yyyy-mm-dd` or empty.
  final String value;
  final ValueChanged<String> onChanged;
  final BoxDecoration decoration;
  final TextStyle style;
  final double height;
  final String hint;

  static String _two(int n) => n.toString().padLeft(2, '0');

  Future<void> _pick(BuildContext context) async {
    final current = DateTime.tryParse(value);
    final platform = Theme.of(context).platform;
    DateTime? picked;
    if (platform == TargetPlatform.iOS || platform == TargetPlatform.macOS) {
      var temp = current ?? DateTime.now();
      final done = await showCupertinoModalPopup<bool>(
        context: context,
        builder: (ctx) => Container(
          height: 300,
          color: CupertinoColors.systemBackground.resolveFrom(ctx),
          child: SafeArea(
            top: false,
            child: Column(
              children: <Widget>[
                Container(
                  height: 44,
                  color: CupertinoColors.secondarySystemBackground.resolveFrom(ctx),
                  alignment: AlignmentDirectional.centerEnd,
                  child: CupertinoButton(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    onPressed: () => Navigator.of(ctx).pop(true),
                    child: Text('تم', style: ts(16, w: 600, c: AppColors.forest)),
                  ),
                ),
                Expanded(
                  child: CupertinoDatePicker(
                    mode: CupertinoDatePickerMode.date,
                    initialDateTime: temp,
                    minimumYear: 1900,
                    maximumYear: 2100,
                    onDateTimeChanged: (d) => temp = d,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
      if (done == true) picked = temp;
    } else {
      picked = await showDatePicker(context: context, initialDate: current ?? DateTime.now(), firstDate: DateTime(1900), lastDate: DateTime(2100));
    }
    if (picked != null) onChanged('${picked.year.toString().padLeft(4, '0')}-${_two(picked.month)}-${_two(picked.day)}');
  }

  @override
  Widget build(BuildContext context) {
    final d = DateTime.tryParse(value);
    final text = d == null
        ? Tx(hint, style: style.copyWith(color: kAddPh), maxLines: 1, softWrap: false)
        : Tx(
            '${_two(d.day)}/${_two(d.month)}/${d.year.toString().padLeft(4, '0')}',
            style: style,
            dir: TextDirection.ltr,
            align: TextAlign.right,
            maxLines: 1,
            softWrap: false,
          );
    return Tap(
      onTap: () => _pick(context),
      child: Container(
        height: height,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        decoration: decoration,
        child: Row(
          children: <Widget>[
            Expanded(child: text),
            const SizedBox(width: 8),
            const LucideIcon(Lu.calendar, size: 18, strokeWidth: 1.8, color: kAddIc),
          ],
        ),
      ),
    );
  }
}
