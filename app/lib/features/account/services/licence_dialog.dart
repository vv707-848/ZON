// «تحديث الرخصة» of his listing: he renewed its advertising licence at REGA — the same number, or a new one.
// The box may stay empty (the same number: the server asks REGA about the one it holds); a number typed is
// checked here as the server checks it (6 to 12 digits), and whether REGA knows it is the server's to say.

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/utils/input.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/widgets/form_inputs.dart';

/// The licence number to ask REGA about: '' for the one the listing has, null when he cancels.
Future<String?> askLicenceNumber(BuildContext context) =>
    showAppFrame<String>(context, label: 'تحديث الرخصة', builder: (ctx) => const _LicenceCard());

/// [text] as a licence number ('' when left empty), or why it is not one.
({String? number, String? error}) licenceNumberOf(String text) {
  final t = toAsciiDigits(text.trim()).replaceAll(' ', '');
  if (t.isEmpty) return (number: '', error: null);
  if (!RegExp(r'^\d{6,12}$').hasMatch(t)) return (number: null, error: 'رقم الترخيص من 6 إلى 12 رقماً');
  return (number: t, error: null);
}

class _LicenceCard extends StatefulWidget {
  const _LicenceCard();

  @override
  State<_LicenceCard> createState() => _LicenceCardState();
}

class _LicenceCardState extends State<_LicenceCard> {
  String _text = '';
  String? _error;

  void _confirm() {
    final r = licenceNumberOf(_text);
    if (r.error != null) {
      setState(() => _error = r.error);
      return;
    }
    Navigator.of(context).pop(r.number);
  }

  @override
  Widget build(BuildContext context) => AppDialogCard(
    icon: Lu.circleAlert,
    title: 'تحديث الرخصة',
    message: 'جدّد الترخيص الإعلاني لدى الهيئة أولاً. إن تغيّر رقمه فاكتب الجديد، وإن بقي كما هو فاترك الخانة فارغة. تسأل زون الهيئة عن تاريخ انتهائه.',
    extra: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        projInput(
          value: _text,
          dir: TextDirection.ltr,
          keyboard: TextInputType.number,
          placeholder: 'رقم الترخيص الجديد — اختياري',
          onChanged: (v) => setState(() {
            _text = v;
            _error = null;
          }),
        ),
        if (_error case final String e) ...<Widget>[const SizedBox(height: 8), FormError(e)],
      ],
    ),
    buttons: ButtonPair(
      secondary: AppButton(label: 'إلغاء', tone: ButtonTone.tonal, onTap: () => Navigator.of(context).pop()),
      primary: AppButton(label: 'تحديث', onTap: _confirm),
    ),
  );
}
