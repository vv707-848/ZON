// `/requests` — الطلبات: filters by role / city / type and the request list.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/utils/sort.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/ask_card.dart';
import 'package:zon/shared/widgets/lens.dart';

const List<String> _tabs = <String>['all', 'broker', 'developer', 'owner', 'seeker'];
const Map<String, String> _tabLabel = <String, String>{'all': 'الكل', 'broker': 'وسيط', 'developer': 'مطوّر', 'owner': 'مالك', 'seeker': 'باحث'};

/// `/requests` — الطلبات
class RequestsScreen extends StatefulWidget {
  const RequestsScreen({super.key});

  @override
  State<RequestsScreen> createState() => _RequestsScreenState();
}

class _RequestsScreenState extends State<RequestsScreen> {
  String tab = 'all';
  String purpose = 'all';
  String city = 'all';
  String hood = 'all';
  String ptype = 'all';
  String sort = 'new';

  List<RequestItem> _items() {
    final rows = store.allRequests().where((r) {
      if (tab != 'all' && r.role != tab) return false;
      if (purpose != 'all' && r.purpose != purpose) return false;
      if (city != 'all' && r.city != city) return false;
      if (hood != 'all' && !r.neighborhoods.contains(hood)) return false;
      if (ptype != 'all' && !r.types.contains(ptype)) return false;
      if (sort == 'urgent' && r.urgent != true) return false;
      return true;
    }).toList();
    int cmp(RequestItem a, RequestItem b) {
      if (sort != 'cheap') {
        final u = (b.urgent == true ? 1 : 0) - (a.urgent == true ? 1 : 0);
        if (u != 0) return u;
      }
      if (sort == 'cheap') {
        final d = a.priceMin - b.priceMin;
        return d.isNaN ? 0 : d.sign.toInt();
      }
      if (sort == 'old') return compareIds(a.id, b.id);
      return compareIds(b.id, a.id);
    }

    return stableSort(rows, cmp);
  }

  @override
  Widget build(BuildContext context) {
    final hoods = city == 'all' ? const <String>[] : (kCities[city] ?? const <String>[]);
    return AppShell(
      child: ListenableBuilder(
        listenable: store,
        builder: (context, _) {
          final items = _items();
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 10),
                child: Lens(
                  mode: LensMode(
                    roles: true,
                    buttons: <LensModeButton>[
                      for (final t in _tabs) LensModeButton(label: _tabLabel[t]!, on: tab == t, onTap: () => setState(() => tab = t)),
                    ],
                  ),
                  fields: <LensField>[
                    LensField(
                      label: 'المدينة',
                      value: city,
                      options: <Opt>[const Opt('all', 'الكل'), ...optsOf(kCities.keys)],
                      onChanged: (v) => setState(() {
                        city = v;
                        hood = 'all';
                      }),
                    ),
                    LensField(
                      label: 'الحي',
                      value: hood,
                      options: <Opt>[const Opt('all', 'الكل'), ...optsOf(hoods)],
                      onChanged: (v) => setState(() => hood = v),
                    ),
                    LensField(
                      label: 'النوع',
                      value: ptype,
                      options: <Opt>[const Opt('all', 'الكل'), for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
                      onChanged: (v) => setState(() => ptype = v),
                    ),
                    LensField(
                      label: 'الغرض',
                      value: purpose,
                      options: const <Opt>[Opt('all', 'الكل'), Opt('sale', 'شراء'), Opt('rent', 'إيجار')],
                      onChanged: (v) => setState(() => purpose = v),
                    ),
                  ],
                  sort: LensSort(
                    buttons: <LensSortButton>[
                      LensSortButton(label: 'الأحدث', on: sort == 'new', onTap: () => setState(() => sort = 'new')),
                      LensSortButton(label: 'عاجلة', icon: Lu.zap, on: sort == 'urgent', onTap: () => setState(() => sort = 'urgent')),
                      LensSortButton(label: 'الأقدم', on: sort == 'old', onTap: () => setState(() => sort = 'old')),
                      LensSortButton(label: 'أقل سعر', on: sort == 'cheap', onTap: () => setState(() => sort = 'cheap')),
                    ],
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    if (items.isNotEmpty)
                      for (var i = 0; i < items.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 18), AskCard(item: items[i])]
                    else
                      EmptyBox(
                        title: 'لا طلبات في هذا النطاق',
                        text: 'الطلبات المنشورة من الجميع تظهر هنا. طلباتك أنت في حسابي.',
                        textStyle: emptyTextStyle(w: 700),
                        action: 'أضف طلباً',
                        onAction: () => Nav.go(context, '/add'),
                      ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
