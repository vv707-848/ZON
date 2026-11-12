// A unit row of the project page and the "تم البيع" stamp.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_listings.dart';

/// "تم البيع" rubber stamp (red outline, rotated −16°).
class SoldStamp extends StatelessWidget {
  const SoldStamp({super.key});

  @override
  Widget build(BuildContext context) {
    const red = Color(0xFFE11D2E);
    return Transform.rotate(
      angle: -0.2793,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: red, width: 2),
        ),
        child: Tx('تم البيع', style: ts(14, w: 700, lh: 14, c: red)),
      ),
    );
  }
}

/// A unit model row inside a project (photo, name, area, rooms, price).
class UnitRow extends StatelessWidget {
  const UnitRow({super.key, required this.u, required this.projectId, required this.propertyType, this.hideZeroFacts = false, this.clickable = true});

  final UnitModel u;
  final String projectId;
  final PType propertyType;

  /// Project-editor variant: facts only when > 0.
  final bool hideZeroFacts;
  final bool clickable;

  @override
  Widget build(BuildContext context) {
    final sold = u.sold;
    final kind = u.propertyType ?? propertyType;
    final flags = specsForUnit(kind);
    const factColor = Color(0xFF3D4A43);
    Widget fact(String icon, String text) => Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Opacity(opacity: 0.7, child: LucideIcon(icon, size: 15, color: AppColors.forest)),
        const SizedBox(width: 5),
        Tx(text, style: ts(13, w: 600, lh: 21.5, c: factColor), softWrap: false),
      ],
    );
    bool has(double? v) => hideZeroFacts ? (v != null && v > 0) : (v != null && v != 0);
    final facts = <Widget>[];
    final landA = u.landArea ?? u.area;
    final builtA = u.builtArea ?? u.area;
    if (flags.land && has(landA)) {
      facts.add(fact(Lu.landPlot, areaLabel(landA)));
    } else if (flags.built && has(builtA)) {
      facts.add(fact(Lu.landPlot, areaLabel(builtA)));
    }
    if (flags.rooms && has(u.rooms)) facts.add(fact(Lu.bedDouble, sar(u.rooms!)));
    if (flags.rooms && has(u.baths)) facts.add(fact(Lu.bath, sar(u.baths!)));

    final name = u.name.startsWith('قطعة') ? u.name : 'نموذج ${hideZeroFacts && u.name.isEmpty ? '—' : u.name}';
    final row = Container(
      decoration: BoxDecoration(color: AppColors.tint, borderRadius: BorderRadius.circular(20)),
      clipBehavior: sold ? Clip.none : Clip.antiAlias,
      child: Stack(
        clipBehavior: Clip.none,
        children: <Widget>[
          Padding(
            padding: EdgeInsets.only(left: sold ? 92 : 0),
            child: Row(
              children: <Widget>[
                SizedBox(
                  width: 88,
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: Container(
                      width: 72,
                      height: 72,
                      margin: const EdgeInsets.only(top: 12, bottom: 12, right: 10),
                      clipBehavior: Clip.antiAlias,
                      decoration: BoxDecoration(borderRadius: BorderRadius.circular(16)),
                      // a model of a server project without a photo shows none, not a sample's
                      child: ServerListings.isServer(projectId)
                          ? AppImage(u.image.isNotEmpty ? u.image : kNoPhoto, fallback: kNoPhoto)
                          : AppImage(u.image.isNotEmpty ? u.image : '/listings/apt-1.jpg', fallback: '/listings/apt-1.jpg'),
                    ),
                  ),
                ),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(8, 10, 12, 10),
                    child: Padding(
                      padding: EdgeInsets.zero,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Tx(
                            name,
                            style: ts(16, w: 700, lh: 20, c: AppColors.deep),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          const SizedBox(height: 6),
                          SizedBox(
                            height: 20,
                            child: Row(
                              children: <Widget>[
                                for (var i = 0; i < facts.length; i++) ...<Widget>[if (i > 0) const SizedBox(width: 12), facts[i]],
                              ],
                            ),
                          ),
                          const SizedBox(height: 6),
                          SizedBox(
                            height: 28,
                            child: Align(
                              alignment: AlignmentDirectional.centerStart,
                              child: SarText(
                                sar(u.price),
                                style: ts(13, w: 700, lh: 21.5, c: AppColors.forest),
                                iconW: 10,
                                iconH: 11,
                                gap: 3,
                                iconColor: AppColors.forest,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          if (sold) const Positioned(top: 12, left: 8, child: SoldStamp()),
        ],
      ),
    );
    if (sold) return Opacity(opacity: 0.92, child: row);
    if (!clickable) return row;
    return Tap(onTap: () => Nav.go(context, '/projects/$projectId/units/${u.id}'), child: row);
  }
}
