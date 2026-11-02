// Floor picker of a unit inside a building: الأرضي / ملحق / رقم.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/add_tokens.dart';
import 'package:zon/features/add/widgets/form_inputs.dart';

/// Three-way segmented picker; choosing "رقم" shows a number input under it.
///
/// [value] is a `FloorLevel` ("ground" | "annex" | anything else = a number)
/// and [number] the typed floor number.
class FloorPick extends StatelessWidget {
  const FloorPick({super.key, required this.value, required this.number, required this.onLevel, required this.onNumber});

  final String value;
  final String number;
  final ValueChanged<String> onLevel;
  final ValueChanged<String> onNumber;

  @override
  Widget build(BuildContext context) {
    final mode = value == 'annex' ? 'annex' : (value == 'ground' ? 'ground' : 'number');
    Widget segment(String label, String key) {
      final on = mode == key;
      return Expanded(
        child: Tap(
          onTap: () => onLevel(key),
          child: Container(
            height: 40,
            decoration: BoxDecoration(color: on ? AppColors.forest : null, borderRadius: BorderRadius.circular(12)),
            alignment: Alignment.center,
            child: Tx(
              label,
              style: ts(14, w: 600, lh: 21, c: on ? AppColors.white : kAddIc),
              align: TextAlign.center,
            ),
          ),
        ),
      );
    }

    final segments = Container(
      height: 50,
      padding: const EdgeInsets.all(4),
      decoration: kProjBox,
      child: Row(
        children: <Widget>[
          segment('الأرضي', 'ground'),
          const SizedBox(width: 4),
          segment('ملحق', 'annex'),
          const SizedBox(width: 4),
          segment('رقم', 'number'),
        ],
      ),
    );
    final control = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        segments,
        if (mode == 'number') ...<Widget>[
          const SizedBox(height: 8),
          projInput(
            value: number,
            onChanged: (v) => onNumber(digitsOnly(v)),
            placeholder: 'اكتب رقم الدور',
            dir: TextDirection.ltr,
            keyboard: TextInputType.number,
          ),
        ],
      ],
    );
    return ProjField(icon: Lu.layers, label: 'الطابق', child: control);
  }
}
