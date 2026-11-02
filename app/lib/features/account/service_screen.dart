// `/account/:service` — hosts one account service (tasks, clients, plans…)
// with its title bar, and checks that the user's role may open it.

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/account/services/clients_view.dart';
import 'package:zon/features/account/services/inventory_view.dart';
import 'package:zon/features/account/services/matching_view.dart';
import 'package:zon/features/account/services/my_listings_view.dart';
import 'package:zon/features/account/services/my_requests_view.dart';
import 'package:zon/features/account/services/owners_view.dart';
import 'package:zon/features/account/services/people_view.dart';
import 'package:zon/features/account/services/plans_view.dart';
import 'package:zon/features/account/services/search_view.dart';
import 'package:zon/features/account/services/settings_view.dart';
import 'package:zon/features/account/services/tasks_view.dart';

const Map<String, String> _titles = <String, String>{
  'matching': 'مساعد المطابقة',
  'search': 'البحث الدلالي',
  'clients': 'إدارة العملاء',
  'owners': 'إدارة الملاك',
  'inventory': 'المخزون',
  'tasks': 'مهامي',
  'requests': 'طلباتي',
  'listings': 'العروض والمشاريع',
  'settings': 'الإعدادات',
  'plans': 'الاشتراكات',
  'people': 'المتابعون',
  'notifications': 'الإشعارات',
};

const Set<String> _full = <String>{
  'listings',
  'inventory',
  'requests',
  'settings',
  'plans',
  'clients',
  'matching',
  'search',
  'tasks',
  'owners',
  'people',
};

/// `/account/$svc` — the account services.
class ServiceScreen extends StatelessWidget {
  const ServiceScreen({super.key, required this.svc, this.query = const <String, String>{}});

  final String svc;
  final Map<String, String> query;

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    final title = _titles[svc] ?? 'حسابي';
    if (user == null) {
      return AppShell(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Center(
            child: Tap(
              onTap: () => Nav.go(context, '/login'),
              child: Tx(
                'سجّل الدخول',
                style: ts(16, lh: 24, c: AppColors.ink),
                align: TextAlign.center,
              ),
            ),
          ),
        ),
      );
    }
    final allowed = kSvcRoles[svc];
    if (allowed != null && !allowed.contains(user.role)) return const RedirectTo('/account');
    final full = _full.contains(svc);

    Widget? content;
    switch (svc) {
      case 'matching':
        content = user.role == 'developer' ? const DeveloperMatchingView() : const SeekerMatchingView();
      case 'search':
        content = SemanticSearchView(role: user.role);
      case 'clients':
        content = const ClientsView();
      case 'owners':
        content = const OwnersView();
      case 'inventory':
        content = const InventoryView();
      case 'listings':
        content = MyListingsView(role: user.role, name: user.name);
      case 'requests':
        content = MyRequestsView(city: user.city, role: user.role);
      case 'tasks':
        content = const TasksView();
      case 'settings':
        content = const SettingsView();
      case 'plans':
        content = const PlansView();
      case 'people':
        content = PeopleView(kind: query['kind']);
      case 'notifications':
        return const RedirectTo('/notifications');
    }

    final showTitle = const <String>['matching', 'search', 'inventory', 'tasks'].contains(svc);
    return AppShell(
      title: showTitle ? title : null,
      tone: (svc == 'search' || svc == 'matching') ? 'forest' : null,
      child: Padding(
        padding: full ? const EdgeInsets.only(bottom: 32) : const EdgeInsets.fromLTRB(20, 20, 20, 32),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (!full) ...<Widget>[
              BackLink(onTap: () => Nav.go(context, '/account')),
              const SizedBox(height: 12),
              Tx(title, style: ts(20, w: 700, lh: 28, c: AppColors.ink)),
              const SizedBox(height: 16),
            ],
            ?content,
          ],
        ),
      ),
    );
  }
}
