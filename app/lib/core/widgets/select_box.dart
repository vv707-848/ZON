// Drop-down field and the platform option picker it opens.

import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';

// Drop-down field ("المدينة", "النوع"…) and the platform option picker it opens.

/// One option of a [SelectBox]: the stored [value] and the shown [label].
class Opt {
  const Opt(this.value, this.label, {this.disabled = false});

  final String value;
  final String label;
  final bool disabled;
}

/// Options whose value and label are the same text.
List<Opt> optsOf(Iterable<String> values) => values.map((v) => Opt(v, v)).toList();

/// Shows the platform option picker (wheel sheet on iOS, list dialog on
/// Android) and resolves to the chosen value (null when dismissed).
Future<String?> showSelectPicker(BuildContext context, List<Opt> options, String value) async {
  if (options.isEmpty) return null;
  final platform = Theme.of(context).platform;
  if (platform == TargetPlatform.iOS || platform == TargetPlatform.macOS) {
    var index = options.indexWhere((o) => o.value == value);
    if (index < 0) index = 0;
    var selected = index;
    return showCupertinoModalPopup<String>(
      context: context,
      builder: (ctx) {
        return Container(
          height: 280,
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
                    onPressed: () {
                      final o = options[selected];
                      Navigator.of(ctx).pop(o.disabled ? null : o.value);
                    },
                    child: const Text('تم', style: TextStyle(fontWeight: FontWeight.w600)),
                  ),
                ),
                Expanded(
                  child: CupertinoPicker(
                    itemExtent: 36,
                    scrollController: FixedExtentScrollController(initialItem: index),
                    onSelectedItemChanged: (i) => selected = i,
                    children: <Widget>[
                      for (final o in options)
                        Center(
                          child: Text(
                            o.label,
                            style: TextStyle(fontSize: 20, color: o.disabled ? CupertinoColors.inactiveGray : CupertinoColors.label.resolveFrom(ctx)),
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
  return showDialog<String>(
    context: context,
    builder: (ctx) {
      return Dialog(
        insetPadding: const EdgeInsets.symmetric(horizontal: 40, vertical: 60),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxHeight: 460),
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.symmetric(vertical: 8),
            children: <Widget>[
              for (final o in options)
                InkWell(
                  onTap: o.disabled ? null : () => Navigator.of(ctx).pop(o.value),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                    child: Row(
                      children: <Widget>[
                        Expanded(
                          child: Text(o.label, style: TextStyle(fontSize: 16, color: o.disabled ? const Color(0x61000000) : const Color(0xDE000000))),
                        ),
                        Container(
                          width: 20,
                          height: 20,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            border: Border.all(color: o.value == value ? AppColors.forest : const Color(0x8A000000), width: 2),
                          ),
                          alignment: Alignment.center,
                          child: o.value == value
                              ? Container(
                                  width: 10,
                                  height: 10,
                                  decoration: const BoxDecoration(color: AppColors.forest, shape: BoxShape.circle),
                                )
                              : null,
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      );
    },
  );
}

/// Whether the field shows the down chevron.
enum SelectArrow { native, none }

/// A tappable field showing the selected option's label; opens
/// [showSelectPicker].
class SelectBox extends StatelessWidget {
  const SelectBox({
    super.key,
    required this.value,
    required this.options,
    required this.onChanged,
    required this.style,
    this.height = 40,
    this.padding = const EdgeInsets.symmetric(horizontal: 12),
    this.decoration,
    this.arrow = SelectArrow.native,
    this.arrowColor,
    this.enabled = true,
    this.textAlign,
    this.arrowGap = 0,
  });

  final String value;
  final List<Opt> options;
  final ValueChanged<String> onChanged;
  final TextStyle style;
  final double? height;
  final EdgeInsetsGeometry padding;
  final BoxDecoration? decoration;
  final SelectArrow arrow;
  final Color? arrowColor;
  final bool enabled;
  final TextAlign? textAlign;
  final double arrowGap;

  String get _label {
    for (final o in options) {
      if (o.value == value) return o.label;
    }
    return '';
  }

  @override
  Widget build(BuildContext context) {
    final color = style.color ?? AppColors.ink;
    final text = Tx(_label, style: style, maxLines: 1, overflow: TextOverflow.ellipsis, softWrap: false, align: textAlign);
    final Widget box = Container(
      height: height,
      decoration: decoration,
      padding: padding,
      child: Row(
        children: <Widget>[
          Expanded(child: text),
          if (arrow == SelectArrow.native) ...<Widget>[
            SizedBox(width: 6 + arrowGap),
            LucideIcon(Lu.chevronDown, size: 15, strokeWidth: 2.4, color: arrowColor ?? color),
          ],
        ],
      ),
    );
    return Semantics(
      button: true,
      enabled: enabled,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: !enabled
            ? null
            : () async {
                FocusScope.of(context).unfocus();
                final v = await showSelectPicker(context, options, value);
                if (v != null && v != value) onChanged(v);
              },
        child: box,
      ),
    );
  }
}
