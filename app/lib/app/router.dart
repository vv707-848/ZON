// Routes of the app and the navigation helpers.

import 'package:flutter/material.dart';

import 'package:zon/core/net/api.dart';
import 'package:zon/core/theme/colors.dart';
import 'package:zon/data/server/server_chats.dart';
import 'package:zon/features/account/account_screen.dart';
import 'package:zon/features/account/service_screen.dart';
import 'package:zon/features/add/add_screen.dart';
import 'package:zon/features/add/listing_edit_screen.dart';
import 'package:zon/features/add/listing_review_screen.dart';
import 'package:zon/features/add/project_review_screen.dart';
import 'package:zon/features/auth/login_screen.dart';
import 'package:zon/features/chats/barq_chat_screen.dart';
import 'package:zon/features/chats/chat_thread_screen.dart';
import 'package:zon/features/chats/chats_screen.dart';
import 'package:zon/features/chats/server_chat_thread.dart';
import 'package:zon/features/details/listing_screen.dart';
import 'package:zon/features/details/project_screen.dart';
import 'package:zon/features/details/unit_screen.dart';
import 'package:zon/features/home/home_screen.dart';
import 'package:zon/features/legal/legal_screen.dart';
import 'package:zon/features/notifications/notifications_screen.dart';
import 'package:zon/features/profile/user_profile_screen.dart';
import 'package:zon/features/requests/request_detail_screen.dart';
import 'package:zon/features/requests/requests_screen.dart';

/// A parsed location such as `/users/فهد?listing=l1`: path segments and
/// query parameters.
class Loc {
  Loc(this.raw) {
    final q = raw.indexOf('?');
    path = q < 0 ? raw : raw.substring(0, q);
    final query = q < 0 ? '' : raw.substring(q + 1);
    for (final part in query.split('&')) {
      if (part.isEmpty) continue;
      final eq = part.indexOf('=');
      final k = eq < 0 ? part : part.substring(0, eq);
      final v = eq < 0 ? '' : part.substring(eq + 1);
      params[_dec(k)] = _dec(v.replaceAll('+', ' '));
    }
    segs = path.split('/').where((s) => s.isNotEmpty).toList();
  }

  final String raw;
  late final String path;
  late final List<String> segs;
  final Map<String, String> params = <String, String>{};

  static String _dec(String s) {
    try {
      return Uri.decodeComponent(s);
    } catch (_) {
      return s;
    }
  }
}

/// The route table: location → screen.
///
/// | location | screen |
/// |----------|--------|
/// | `/` | home feed |
/// | `/login` | sign in / sign up |
/// | `/listings/:id` | listing details |
/// | `/projects/:id` | project details |
/// | `/projects/:id/units/:unitId` | unit details |
/// | `/requests`, `/requests/:id` | search requests |
/// | `/chats`, `/chats/barq`, `/chats/:id` | conversations (with a server `/chats/C<id>`, and `/chats/new?to=&about=` before the first message) |
/// | `/account` | account page |
/// | `/account/:service` | a service (tasks, clients, plans…) |
/// | `/account/listings/:id` | edit my listing / project |
/// | `/account/listings/:id/review` | the wording of my listing (server) |
/// | `/account/projects/:id/review` | the wording of my project and its models (server) |
/// | `/users/:name` | public profile |
/// | `/legal/:slug` | policy pages |
/// | `/add` | add a listing / project / request |
/// | `/notifications` | the notifications list (bell) |
/// A link from outside the app (a shared link, a notification) names a server row by its number
/// (`/listings/12`); with a server it is the app's `L12`, `P12` (ServerListings) or `R12` (ServerRequests).
String _serverId(String id, String prefix) => Api.on && RegExp(r'^\d+$').hasMatch(id) ? '$prefix$id' : id;

Widget screenFor(String location) {
  final loc = Loc(location);
  final s = loc.segs;
  if (s.isEmpty) return const HomeScreen();
  switch (s[0]) {
    case 'login':
      if (s.length == 1) return const LoginScreen();
    case 'listings':
      if (s.length == 2) return ListingScreen(id: _serverId(s[1], 'L'));
    case 'projects':
      if (s.length == 2) return ProjectScreen(id: _serverId(s[1], 'P'));
      if (s.length == 4 && s[2] == 'units') return UnitScreen(id: _serverId(s[1], 'P'), unitId: s[3]);
    case 'requests':
      if (s.length == 1) return const RequestsScreen();
      if (s.length == 2) return RequestDetailScreen(id: _serverId(s[1], 'R'));
    case 'chats':
      if (s.length == 1) return const ChatsScreen();
      if (s.length == 2 && s[1] == 'barq') return const BarqChatScreen();
      if (s.length == 2 && Api.on && (s[1] == 'new' || ServerChats.rawId(_serverId(s[1], 'C')) != null)) {
        final q = loc.params;
        // a notice names the conversation by its number (`/chats/5`), the app by `C5`
        return ServerChatThread(id: ServerChats.rawId(_serverId(s[1], 'C')), to: q['to'], about: q['about'], name: q['name'], role: q['role']);
      }
      if (s.length == 2 && Api.on) return const ChatsScreen(); // the phone's simulated conversations are not shown with a server
      if (s.length == 2) return ChatThreadScreen(id: s[1]);
    case 'account':
      if (s.length == 1) return const AccountScreen();
      if (s.length == 3 && s[1] == 'listings') return ListingEditScreen(id: s[2]);
      if (s.length == 4 && s[1] == 'listings' && s[3] == 'review') return ListingReviewScreen(id: _serverId(s[2], 'L'));
      if (s.length == 4 && s[1] == 'projects' && s[3] == 'review' && int.tryParse(s[2]) != null) return ProjectReviewScreen(id: int.parse(s[2]));
      if (s.length == 2) return ServiceScreen(svc: s[1], query: loc.params);
    case 'users':
      if (s.length == 2) return UserProfileScreen(id: s[1], listing: loc.params['listing']);
    case 'legal':
      if (s.length == 2) return LegalScreen(slug: s[1]);
    case 'add':
      if (s.length == 1) return AddScreen(start: loc.params['type']);
    case 'notifications':
      if (s.length == 1) return const NotificationsScreen();
  }
  return const NotFoundScreen();
}

/// Every navigation pushes a page (so the system back gesture works).
/// `animate: false` is used by the tab bar for instant switches.
Route<void> buildRoute(RouteSettings settings, {bool animate = true}) {
  final name = settings.name ?? '/';
  if (!animate) {
    return PageRouteBuilder<void>(
      settings: settings,
      transitionDuration: Duration.zero,
      reverseTransitionDuration: Duration.zero,
      pageBuilder: (context, animation, secondaryAnimation) => screenFor(name),
    );
  }
  return MaterialPageRoute<void>(settings: settings, builder: (_) => screenFor(name));
}

Route<void>? onGenerateRoute(RouteSettings settings) {
  final args = settings.arguments;
  final animate = args is NavArgs ? args.animate : true;
  return buildRoute(settings, animate: animate);
}

class NavArgs {
  const NavArgs({this.animate = true});

  final bool animate;
}

/// Navigation helpers used by the screens.
abstract final class Nav {
  static final GlobalKey<NavigatorState> key = GlobalKey<NavigatorState>();

  /// Opens [to].
  static Future<void> go(BuildContext context, String to, {bool animate = true}) {
    return Navigator.of(context).pushNamed(to, arguments: NavArgs(animate: animate));
  }

  /// Replaces the current page with [to].
  static Future<void> replace(BuildContext context, String to, {bool animate = false}) {
    return Navigator.of(context).pushReplacementNamed(to, arguments: NavArgs(animate: animate));
  }

  /// Goes back one page (if possible).
  static void back(BuildContext context) {
    Navigator.of(context).maybePop();
  }

  /// Location of the route that hosts [context].
  static String pathOf(BuildContext context) {
    final name = ModalRoute.of(context)?.settings.name ?? '/';
    final q = name.indexOf('?');
    return q < 0 ? name : name.substring(0, q);
  }
}

/// Replaces the current page with [to] right after the first frame (used by
/// screens that need a signed-in user).
class RedirectTo extends StatefulWidget {
  const RedirectTo(this.to, {super.key});

  final String to;

  @override
  State<RedirectTo> createState() => _RedirectToState();
}

class _RedirectToState extends State<RedirectTo> {
  bool _done = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_done) return;
    _done = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      Nav.replace(context, widget.to);
    });
  }

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}

/// Shown for unknown locations.
class NotFoundScreen extends StatelessWidget {
  const NotFoundScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return const ColoredBox(
      color: AppColors.paper,
      child: SafeArea(
        child: Align(
          alignment: AlignmentDirectional.topStart,
          child: Padding(
            padding: EdgeInsets.all(8),
            child: Text('Not Found', style: TextStyle(fontSize: 16, color: AppColors.ink)),
          ),
        ),
      ),
    );
  }
}
