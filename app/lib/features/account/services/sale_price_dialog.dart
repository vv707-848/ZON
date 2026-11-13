// «تم البيع» for an owner's listing: he says the price it sold for, and Zon's commission (his contract's percent)
// is reckoned on it. The listed price is offered; what he types is checked here as the server checks it — a
// number above zero with two decimals at most — and never read as 0.
//
// A broker's listing or a developer's units ([optional], decision of 13 November): the price is asked, not
// required — some would type any number — and starts empty, so no listed price is sent as if it were said. It
// helps Zon read the market (the market report «أسعار البيع الفعلية»).

import 'package:flutter/material.dart';

import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/features/add/add_helpers.dart';
import 'package:zon/features/add/widgets/form_inputs.dart';

/// The price his listing sold for, or null when he cancels. [listed] is offered first. With [optional] it starts
/// empty and may stay so: then '' — sold, with no price said.
Future<String?> askSalePrice(BuildContext context, {required double listed, bool optional = false, String? message}) => showAppFrame<String>(
  context,
  label: 'سعر البيع',
  builder: (ctx) => _SalePriceCard(listed: listed, optional: optional, message: message),
);

/// [text] as the server reads a sale price ("1850000", "1850000.5"), or why it is not one.
({String? price, String? error}) salePriceOf(String text) {
  final t = toAsciiDigits(text.trim()).replaceAll(',', '').replaceAll('٬', '');
  if (t.isEmpty) return (price: null, error: 'اكتب سعر البيع');
  if (!RegExp(r'^\d+(\.\d{1,2})?$').hasMatch(t)) return (price: null, error: 'سعر البيع رقم بخانتين عشريتين على الأكثر');
  final n = double.parse(t);
  if (n <= 0) return (price: null, error: 'سعر البيع أكبر من صفر');
  return (price: t.replaceFirst(RegExp(r'^0+(?=\d)'), ''), error: null);
}

class _SalePriceCard extends StatefulWidget {
  const _SalePriceCard({required this.listed, required this.optional, this.message});

  final double listed;
  final bool optional;
  final String? message;

  @override
  State<_SalePriceCard> createState() => _SalePriceCardState();
}

class _SalePriceCardState extends State<_SalePriceCard> {
  late String _text = widget.listed > 0 && !widget.optional ? editableNumber(widget.listed) : '';
  String? _error;

  void _confirm() {
    if (widget.optional && _text.trim().isEmpty) {
      Navigator.of(context).pop('');
      return;
    }
    final r = salePriceOf(_text);
    if (r.error != null) {
      setState(() => _error = r.error);
      return;
    }
    Navigator.of(context).pop(r.price);
  }

  @override
  Widget build(BuildContext context) => AppDialogCard(
    icon: Lu.circleAlert,
    title: 'تأكيد البيع',
    message: widget.message ?? 'يُسجَّل العرض مباعاً ويخرج من البحث نهائياً. اكتب سعر البيع الفعلي: عليه يُحسب سعي زون المتفق عليه في عقد الوساطة.',
    extra: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        projInput(
          value: _text,
          dir: TextDirection.ltr,
          keyboard: const TextInputType.numberWithOptions(decimal: true),
          placeholder: widget.optional ? 'سعر البيع (ريال) — اختياري' : 'سعر البيع (ريال)',
          onChanged: (v) => setState(() {
            _text = v;
            _error = null;
          }),
        ),
        if (widget.optional) ...<Widget>[const SizedBox(height: 6), Tx('يساعدنا على فهم السوق.', style: ts(13, w: 500, lh: 19.5, c: AppColors.sage))],
        if (_error case final String e) ...<Widget>[const SizedBox(height: 8), FormError(e)],
      ],
    ),
    buttons: ButtonPair(
      secondary: AppButton(label: 'إلغاء', tone: ButtonTone.tonal, onTap: () => Navigator.of(context).pop()),
      primary: AppButton(label: 'تم البيع', onTap: _confirm),
    ),
  );
}
