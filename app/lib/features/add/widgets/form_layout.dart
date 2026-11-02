// Layout helpers of the add / edit forms: the two-column grid, equal rows and
// the stretch-rows box used by the option cards.

import 'package:flutter/rendering.dart';
import 'package:flutter/widgets.dart';

// ------------------------------------------------------------------ grid

/// One item of a two-column form grid ([span] takes both columns).
class GCell {
  const GCell(this.child, {this.span = false});

  final Widget child;
  final bool span;
}

/// Two equal columns filled row by row, in order.
///
/// Half-width cells pair up; a spanning cell takes a full row (a lone half
/// cell before it keeps its row with an empty second column).
Widget formGrid(List<GCell> cells, {double colGap = 12, double rowGap = 12}) {
  final rows = <Widget>[];
  Widget? pending;
  Widget pair(Widget a, Widget? b) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: <Widget>[
      Expanded(child: a),
      SizedBox(width: colGap),
      Expanded(child: b ?? const SizedBox.shrink()),
    ],
  );
  for (final c in cells) {
    if (c.span) {
      if (pending != null) {
        rows.add(pair(pending, null));
        pending = null;
      }
      rows.add(c.child);
    } else if (pending == null) {
      pending = c.child;
    } else {
      rows.add(pair(pending, c.child));
      pending = null;
    }
  }
  if (pending != null) rows.add(pair(pending, null));
  return Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: <Widget>[
      for (var i = 0; i < rows.length; i++) ...<Widget>[if (i > 0) SizedBox(height: rowGap), rows[i]],
    ],
  );
}

/// [columns] equal columns on one row (missing items leave an empty column).
Widget equalRow(List<Widget> items, {required int columns, double gap = 10, CrossAxisAlignment align = CrossAxisAlignment.start}) {
  return Row(
    crossAxisAlignment: align,
    children: <Widget>[
      for (var i = 0; i < columns; i++) ...<Widget>[
        if (i > 0) SizedBox(width: gap),
        Expanded(child: i < items.length ? items[i] : const SizedBox.shrink()),
      ],
    ],
  );
}

/// Minimum height of the first add steps (screen height − 108), so a short
/// step still fills the screen above the tab bar.
double addStageMinHeight(BuildContext context) {
  final mq = MediaQuery.of(context);
  return mq.size.height - mq.padding.top - 108;
}

// ------------------------------------------------------------------ stretch rows

/// Rows of natural height inside a box with a minimum height: the free space
/// is shared equally between the rows and each child is centred in its row.
/// Children get the full width. Used for the title + subtitle of the option
/// cards so one-line and two-line subtitles sit at the same height.
class StretchRows extends MultiChildRenderObjectWidget {
  const StretchRows({super.key, required super.children, this.gap = 0, this.minHeight = 0});

  final double gap;
  final double minHeight;

  @override
  RenderObject createRenderObject(BuildContext context) => RenderStretchRows(gap, minHeight);

  @override
  void updateRenderObject(BuildContext context, RenderStretchRows renderObject) {
    renderObject
      ..gap = gap
      ..minHeight = minHeight;
  }
}

class _StretchParentData extends ContainerBoxParentData<RenderBox> {}

/// Render object of [StretchRows].
class RenderStretchRows extends RenderBox
    with ContainerRenderObjectMixin<RenderBox, _StretchParentData>, RenderBoxContainerDefaultsMixin<RenderBox, _StretchParentData> {
  RenderStretchRows(this._gap, this._minHeight);

  double _gap;
  double _minHeight;

  set gap(double v) {
    if (v == _gap) return;
    _gap = v;
    markNeedsLayout();
  }

  set minHeight(double v) {
    if (v == _minHeight) return;
    _minHeight = v;
    markNeedsLayout();
  }

  @override
  void setupParentData(RenderBox child) {
    if (child.parentData is! _StretchParentData) child.parentData = _StretchParentData();
  }

  double _content(double Function(RenderBox) h) {
    var sum = 0.0;
    var n = 0;
    var child = firstChild;
    while (child != null) {
      sum += h(child);
      n++;
      child = childAfter(child);
    }
    return sum + (n > 1 ? _gap * (n - 1) : 0);
  }

  @override
  double computeMinIntrinsicHeight(double width) => _content((c) => c.getMinIntrinsicHeight(width)).clamp(_minHeight, double.infinity);

  @override
  double computeMaxIntrinsicHeight(double width) => _content((c) => c.getMaxIntrinsicHeight(width)).clamp(_minHeight, double.infinity);

  double _widest(double Function(RenderBox) w) {
    var out = 0.0;
    var child = firstChild;
    while (child != null) {
      final cw = w(child);
      if (cw > out) out = cw;
      child = childAfter(child);
    }
    return out;
  }

  @override
  double computeMinIntrinsicWidth(double height) => _widest((c) => c.getMinIntrinsicWidth(double.infinity));

  @override
  double computeMaxIntrinsicWidth(double height) => _widest((c) => c.getMaxIntrinsicWidth(double.infinity));

  @override
  void performLayout() {
    final w = constraints.maxWidth;
    final heights = <double>[];
    var child = firstChild;
    while (child != null) {
      child.layout(BoxConstraints.tightFor(width: w), parentUsesSize: true);
      heights.add(child.size.height);
      child = childAfter(child);
    }
    final n = heights.length;
    final total = heights.fold<double>(0, (a, b) => a + b) + (n > 1 ? _gap * (n - 1) : 0);
    final extra = n == 0 || total >= _minHeight ? 0.0 : (_minHeight - total) / n;
    var y = 0.0;
    var i = 0;
    child = firstChild;
    while (child != null) {
      final row = heights[i] + extra;
      (child.parentData! as _StretchParentData).offset = Offset(0, y + (row - heights[i]) / 2);
      y += row + _gap;
      i++;
      child = childAfter(child);
    }
    size = constraints.constrain(Size(w, total > _minHeight ? total : _minHeight));
  }

  @override
  bool hitTestChildren(BoxHitTestResult result, {required Offset position}) => defaultHitTestChildren(result, position: position);

  @override
  void paint(PaintingContext context, Offset offset) => defaultPaint(context, offset);
}
