// Something is on its way from the server: a small spinner and a line saying what.

import 'package:flutter/material.dart';

import 'package:zon/core/theme/theme.dart';

/// A spinner with [label] under it, centred in its own padding.
class LoadingBox extends StatelessWidget {
  const LoadingBox({super.key, this.label = 'جارٍ التحميل…'});

  final String label;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 40),
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        const SizedBox(width: 26, height: 26, child: CircularProgressIndicator(strokeWidth: 2.4, color: AppColors.forest)),
        const SizedBox(height: 12),
        Tx(label, style: ts(14, w: 500, lh: 20, c: AppColors.muted)),
      ],
    ),
  );
}
