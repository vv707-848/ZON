// The live «n/300» under a listing's free text (add_helpers.dart: kListingDescMax).

import 'package:flutter/material.dart';

import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/add_helpers.dart';

/// The letters of [controller]'s text against the limit, in the danger colour when over it.
class DescCounter extends StatefulWidget {
  const DescCounter(this.controller, {super.key});

  final TextEditingController controller;

  @override
  State<DescCounter> createState() => _DescCounterState();
}

class _DescCounterState extends State<DescCounter> {
  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_changed);
  }

  @override
  void dispose() {
    widget.controller.removeListener(_changed);
    super.dispose();
  }

  void _changed() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final text = widget.controller.text;
    final over = descTooLong(text) != null;
    final style = over ? ts(12, w: 700, lh: 18, c: AppColors.danger) : ts(12, w: 500, lh: 18, c: AppColors.muted);
    return Align(alignment: AlignmentDirectional.centerEnd, child: Tx(descCount(text), style: style));
  }
}
