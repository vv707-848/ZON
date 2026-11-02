// Colours and box styles shared by the add / edit forms.
//
// They are tuned variants of the app palette (core/theme/colors.dart) for
// form surfaces. Change a value here and every add form follows.

import 'package:flutter/painting.dart';

import 'package:zon/core/theme/theme.dart';

/// Field icons and secondary hints.
const Color kAddIc = AppColors.muted;

/// Fill of the inputs.
const Color kAddBox = Color(0xFFF5F7F6);

/// Border of the inputs and of the cards inside a form.
const Color kAddBoxLine = Color(0xFFE4E9E6);

/// Placeholder text of the inputs.
const Color kAddPh = Color(0xFF959E99);

/// Hint under a text area.
const Color kAddHintTa = Color(0xFF8A918C);

/// Page background behind the long forms (listing, project).
const Color kSheetProj = Color(0xFFF4F6F5);

/// Page background behind the short steps (licence, owner, request).
const Color kSheetIos = Color(0xFFF3F6F4);

/// Fill of the soft blocks (licence number, card fields).
const Color kStoneBg = Color(0xFFF1F4F2);

/// Ring of the outlined buttons and dashed boxes.
const Color kGhostLine = Color(0xFFD5DCD7);

/// Box of an input in the long forms.
final BoxDecoration kProjBox = BoxDecoration(
  color: kAddBox,
  borderRadius: BorderRadius.circular(16),
  border: Border.all(color: kAddBoxLine),
);

/// Box of a card field in the short forms.
final BoxDecoration kStoneBox = BoxDecoration(color: kStoneBg, borderRadius: BorderRadius.circular(22));

/// Text typed in an input of the long forms.
TextStyle get kProjText => ts(16, w: 500, lh: 24, c: AppColors.deep);

/// Text typed in a card field of the short forms.
TextStyle get kIosText => ts(16, w: 600, lh: 24, c: AppColors.deep);
