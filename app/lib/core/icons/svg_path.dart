// SVG path parser used by the icon and logo painters.

import 'dart:ui';

/// Minimal SVG path-data parser (M, L, H, V, C, S, Q, T, A, Z and their
/// relative forms). Turns the icon, riyal and logo outlines into [Path]s.
Path parseSvgPath(String d) {
  final p = _PathParser(d);
  return p.parse();
}

class _PathParser {
  _PathParser(this.src);

  final String src;
  int i = 0;
  final Path path = Path();

  double cx = 0, cy = 0; // current point
  double sx = 0, sy = 0; // subpath start
  double lcx = 0, lcy = 0; // last control point (for S/T)
  String lastCmd = '';

  bool _isCmd(String c) => 'MmLlHhVvCcSsQqTtAaZz'.contains(c);

  void _skipSep() {
    while (i < src.length) {
      final c = src[i];
      if (c == ' ' || c == ',' || c == '\n' || c == '\t' || c == '\r') {
        i++;
      } else {
        break;
      }
    }
  }

  bool _hasNumber() {
    _skipSep();
    if (i >= src.length) return false;
    final c = src[i];
    return (c.codeUnitAt(0) >= 48 && c.codeUnitAt(0) <= 57) || c == '-' || c == '+' || c == '.';
  }

  double _num() {
    _skipSep();
    final start = i;
    if (i < src.length && (src[i] == '-' || src[i] == '+')) i++;
    bool dot = false;
    while (i < src.length) {
      final c = src[i];
      final code = c.codeUnitAt(0);
      if (code >= 48 && code <= 57) {
        i++;
      } else if (c == '.' && !dot) {
        dot = true;
        i++;
      } else if (c == 'e' || c == 'E') {
        i++;
        if (i < src.length && (src[i] == '-' || src[i] == '+')) i++;
      } else {
        break;
      }
    }
    return double.parse(src.substring(start, i));
  }

  bool _flag() {
    _skipSep();
    final c = src[i];
    i++;
    return c == '1';
  }

  Path parse() {
    while (true) {
      _skipSep();
      if (i >= src.length) break;
      String cmd;
      if (_isCmd(src[i])) {
        cmd = src[i];
        i++;
      } else {
        // implicit repetition of previous command
        cmd = lastCmd == 'M' ? 'L' : (lastCmd == 'm' ? 'l' : lastCmd);
      }
      _exec(cmd);
    }
    return path;
  }

  void _exec(String cmd) {
    final rel = cmd == cmd.toLowerCase();
    switch (cmd.toUpperCase()) {
      case 'M':
        var x = _num(), y = _num();
        if (rel) {
          x += cx;
          y += cy;
        }
        path.moveTo(x, y);
        cx = sx = x;
        cy = sy = y;
        lcx = cx;
        lcy = cy;
        lastCmd = cmd;
        while (_hasNumber()) {
          var lx = _num(), ly = _num();
          if (rel) {
            lx += cx;
            ly += cy;
          }
          path.lineTo(lx, ly);
          cx = lx;
          cy = ly;
          lcx = cx;
          lcy = cy;
        }
        lastCmd = rel ? 'l' : 'L';
        return;
      case 'L':
        do {
          var x = _num(), y = _num();
          if (rel) {
            x += cx;
            y += cy;
          }
          path.lineTo(x, y);
          cx = x;
          cy = y;
        } while (_hasNumber());
        lcx = cx;
        lcy = cy;
        break;
      case 'H':
        do {
          var x = _num();
          if (rel) x += cx;
          path.lineTo(x, cy);
          cx = x;
        } while (_hasNumber());
        lcx = cx;
        lcy = cy;
        break;
      case 'V':
        do {
          var y = _num();
          if (rel) y += cy;
          path.lineTo(cx, y);
          cy = y;
        } while (_hasNumber());
        lcx = cx;
        lcy = cy;
        break;
      case 'C':
        do {
          var x1 = _num(), y1 = _num(), x2 = _num(), y2 = _num(), x = _num(), y = _num();
          if (rel) {
            x1 += cx;
            y1 += cy;
            x2 += cx;
            y2 += cy;
            x += cx;
            y += cy;
          }
          path.cubicTo(x1, y1, x2, y2, x, y);
          lcx = x2;
          lcy = y2;
          cx = x;
          cy = y;
        } while (_hasNumber());
        break;
      case 'S':
        do {
          double x1, y1;
          final prev = lastCmd.toUpperCase();
          if (prev == 'C' || prev == 'S') {
            x1 = 2 * cx - lcx;
            y1 = 2 * cy - lcy;
          } else {
            x1 = cx;
            y1 = cy;
          }
          var x2 = _num(), y2 = _num(), x = _num(), y = _num();
          if (rel) {
            x2 += cx;
            y2 += cy;
            x += cx;
            y += cy;
          }
          path.cubicTo(x1, y1, x2, y2, x, y);
          lcx = x2;
          lcy = y2;
          cx = x;
          cy = y;
          lastCmd = cmd;
        } while (_hasNumber());
        break;
      case 'Q':
        do {
          var x1 = _num(), y1 = _num(), x = _num(), y = _num();
          if (rel) {
            x1 += cx;
            y1 += cy;
            x += cx;
            y += cy;
          }
          path.quadraticBezierTo(x1, y1, x, y);
          lcx = x1;
          lcy = y1;
          cx = x;
          cy = y;
        } while (_hasNumber());
        break;
      case 'T':
        do {
          double x1, y1;
          final prev = lastCmd.toUpperCase();
          if (prev == 'Q' || prev == 'T') {
            x1 = 2 * cx - lcx;
            y1 = 2 * cy - lcy;
          } else {
            x1 = cx;
            y1 = cy;
          }
          var x = _num(), y = _num();
          if (rel) {
            x += cx;
            y += cy;
          }
          path.quadraticBezierTo(x1, y1, x, y);
          lcx = x1;
          lcy = y1;
          cx = x;
          cy = y;
          lastCmd = cmd;
        } while (_hasNumber());
        break;
      case 'A':
        do {
          final rx = _num(), ry = _num(), rot = _num();
          final large = _flag();
          final sweep = _flag();
          var x = _num(), y = _num();
          if (rel) {
            x += cx;
            y += cy;
          }
          if (rx == 0 || ry == 0) {
            path.lineTo(x, y);
          } else {
            path.arcToPoint(Offset(x, y), radius: Radius.elliptical(rx.abs(), ry.abs()), rotation: rot, largeArc: large, clockwise: sweep);
          }
          cx = x;
          cy = y;
        } while (_hasNumber());
        lcx = cx;
        lcy = cy;
        break;
      case 'Z':
        path.close();
        cx = sx;
        cy = sy;
        lcx = cx;
        lcy = cy;
        break;
    }
    lastCmd = cmd;
  }
}
