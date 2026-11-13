// `/login` — sign in, or sign up: role → details → phone code (OTP) →
// wish (for seekers).
//
// Accounts and passwords: on the server when the app is built with one
// (ZON_API_URL — `ServerAuth`: the code arrives by SMS and creates the
// account), else `LocalAuth` (data/auth), the device's simulation with a
// fixed test code. `Auth` chooses.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/net/api_error.dart';
import 'package:zon/core/net/operation_key.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/utils/input.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/auth/auth.dart';
import 'package:zon/data/auth/local_auth.dart';
import 'package:zon/data/auth/server_auth.dart';
import 'package:zon/data/catalog/catalog.dart';
import 'package:zon/data/server/server_wishes.dart';
import 'package:zon/data/store.dart';
import 'package:zon/shared/widgets/barq_card.dart';

const List<String> _roles = <String>['seeker', 'broker', 'owner', 'developer'];
const Map<String, String> _ico = <String, String>{'seeker': Lu.search, 'broker': Lu.briefcase, 'owner': Lu.home, 'developer': Lu.building2};
const Map<String, String> _hint = <String, String>{
  'seeker': 'حدد رغبتك ثم تصفّح',
  'broker': 'رخصة فال — عروض وعملاء',
  'owner': 'انشر عقارك بتوثيق',
  'developer': 'مشاريع ووحدات للبيع',
};

/// `/login` — the gate, sign in and sign up.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  String view = 'gate';

  @override
  Widget build(BuildContext context) {
    final user = store.readSession();
    if (user != null && view == 'gate') return const RedirectTo('/');
    if (view == 'in') return _SignIn(onBack: () => setState(() => view = 'gate'));
    if (view == 'up') return _SignUp(onBack: () => setState(() => view = 'gate'));
    return GateChrome(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          const BarqCard(name: 'بطاقتك في انتظارك', locked: true),
          const SizedBox(height: 18),
          const GateHero(title: 'أهلاً بك', sub: 'منصة العرض والطلب — ادخل أو أنشئ حسابك'),
          const SizedBox(height: 18),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: _cardDecoration(22),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                GateCta(label: 'تسجيل الدخول', icon: Lu.logIn, onTap: () => setState(() => view = 'in')),
                const SizedBox(height: 10),
                GateCta(label: 'حساب جديد', icon: Lu.userPlus, line: true, onTap: () => setState(() => view = 'up')),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

BoxDecoration _cardDecoration(double r) => BoxDecoration(
  color: AppColors.white,
  borderRadius: BorderRadius.circular(r),
  boxShadow: const <BoxShadow>[
    BoxShadow(color: AppColors.mistLine, spreadRadius: 1),
    ...AppShadows.card,
  ],
);

/// Background mark, back button and brand of the sign-in pages.
class GateChrome extends StatelessWidget {
  const GateChrome({super.key, this.back, required this.child});

  final VoidCallback? back;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    return Scaffold(
      backgroundColor: AppColors.offerPaper,
      resizeToAvoidBottomInset: true,
      body: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.dark,
        child: LayoutBuilder(
          builder: (context, box) {
            final w = box.maxWidth;
            final top = mq.padding.top + 18;
            final bottom = 22 + mq.padding.bottom;
            // the middle block is centred in the free space
            final free = box.maxHeight - top - 44 - 16 - bottom;
            return SingleChildScrollView(
              child: Stack(
                children: <Widget>[
                  // large faded logo mark behind the content
                  Positioned(
                    top: mq.padding.top - 40,
                    left: w / 2 - 160,
                    child: const IgnorePointer(child: ZonLogo.mark(size: 320, color: AppColors.mistLine)),
                  ),
                  Padding(
                    padding: EdgeInsets.fromLTRB(20, top, 20, bottom),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        SizedBox(
                          height: 44,
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: <Widget>[
                              if (back != null) BackLink(onTap: back!) else const SizedBox.shrink(),
                              // brand: the logo tile and the Latin wordmark
                              Directionality(
                                textDirection: TextDirection.ltr,
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: <Widget>[
                                    const ZonLogo(size: 36),
                                    const SizedBox(width: 10),
                                    Tx('ZON', style: ts(20, w: 700, lh: 28, c: AppColors.deep)),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 16),
                        ConstrainedBox(
                          constraints: BoxConstraints(minHeight: free > 0 ? free : 0),
                          child: Center(child: child),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}

/// Big title with a sub line.
class GateHero extends StatelessWidget {
  const GateHero({super.key, required this.title, required this.sub, this.tight = false});

  final String title;
  final String sub;
  final bool tight;

  @override
  Widget build(BuildContext context) {
    const size = 32.0;
    final align = tight ? TextAlign.start : TextAlign.center;
    return Padding(
      padding: tight ? const EdgeInsets.fromLTRB(4, 8, 4, 4) : const EdgeInsets.fromLTRB(4, 4, 4, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Tx(
            title,
            style: ts(size, w: 700, lh: 40, c: AppColors.deep),
            align: align,
          ),
          const SizedBox(height: 10),
          Tx(
            sub,
            style: ts(16, w: 500, lh: 25.5, c: AppColors.stone),
            align: align,
          ),
        ],
      ),
    );
  }
}

/// 54 px button: icon | label | chevron.
class GateCta extends StatelessWidget {
  const GateCta({super.key, required this.label, this.icon, this.line = false, this.onTap, this.disabled = false});

  final String label;
  final String? icon;
  final bool line;
  final VoidCallback? onTap;
  final bool disabled;

  @override
  Widget build(BuildContext context) {
    final fg = line ? AppColors.forest : AppColors.white;
    final box = Container(
      height: 54,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(color: line ? AppColors.white : AppColors.forest, borderRadius: BorderRadius.circular(14)),
      foregroundDecoration: line
          ? BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.forest, width: 1.5),
            )
          : null,
      child: Row(
        children: <Widget>[
          SizedBox(
            width: 24,
            child: icon == null
                ? null
                : Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: LucideIcon(icon!, size: 20, color: fg),
                  ),
          ),
          Expanded(
            child: Tx(
              label,
              style: ts(16, w: 700, lh: 24, c: fg),
              align: TextAlign.center,
            ),
          ),
          SizedBox(
            width: 24,
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: LucideIcon(Lu.chevronLeft, size: 18, strokeWidth: 2.4, color: fg),
            ),
          ),
        ],
      ),
    );
    return Tap(
      onTap: disabled ? null : onTap,
      child: disabled ? Opacity(opacity: 0.4, child: box) : box,
    );
  }
}

/// The white form card.
class _GateForm extends StatelessWidget {
  const _GateForm({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: _cardDecoration(22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (var i = 0; i < children.length; i++) ...<Widget>[if (i > 0) const SizedBox(height: 8), children[i]],
        ],
      ),
    );
  }
}

Widget _label(String t) => Padding(
  padding: const EdgeInsets.only(top: 4),
  child: Tx(t, style: ts(13, w: 700, lh: 19.5, c: AppColors.stone)),
);

final BoxDecoration _fieldDeco = BoxDecoration(
  color: AppColors.offerPaper,
  borderRadius: BorderRadius.circular(14),
  border: Border.all(color: AppColors.mistLine),
);

Widget _input({
  TextEditingController? controller,
  String? placeholder,
  TextDirection? dir,
  bool obscure = false,
  TextInputType? keyboard,
  ValueChanged<String>? onChanged,
  ValueChanged<String>? onSubmitted,
  FocusNode? focusNode,
}) {
  return FieldBox(
    controller: controller,
    focusNode: focusNode,
    placeholder: placeholder,
    style: ts(16, lh: 24, c: AppColors.deep),
    height: 48,
    padding: const EdgeInsets.symmetric(horizontal: 14),
    decoration: _fieldDeco,
    dir: dir,
    obscure: obscure,
    keyboardType: keyboard,
    onChanged: onChanged,
    onSubmitted: onSubmitted,
  );
}

Widget _select({required String value, required List<Opt> options, required ValueChanged<String> onChanged}) {
  return SelectBox(
    value: value,
    options: options,
    onChanged: onChanged,
    style: ts(16, lh: 24, c: AppColors.deep),
    height: 48,
    padding: const EdgeInsets.symmetric(horizontal: 14),
    decoration: _fieldDeco,
  );
}

class _SignIn extends StatefulWidget {
  const _SignIn({required this.onBack});

  final VoidCallback onBack;

  @override
  State<_SignIn> createState() => _SignInState();
}

class _SignInState extends State<_SignIn> {
  final TextEditingController _user = TextEditingController();
  final TextEditingController _pass = TextEditingController();
  final FocusNode _userFocus = FocusNode();
  final FocusNode _passFocus = FocusNode();
  String err = '';
  bool _busy = false;

  @override
  void dispose() {
    _user.dispose();
    _pass.dispose();
    _userFocus.dispose();
    _passFocus.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    // validation: both fields required, password at least 8 characters
    if (_user.text.trim().isEmpty) {
      _userFocus.requestFocus();
      setState(() => err = 'اكتب اسم المستخدم');
      return;
    }
    if (_pass.text.length < 8) {
      _passFocus.requestFocus();
      setState(() => err = 'كلمة السر 8 أحرف على الأقل');
      return;
    }
    // The server, or the device's registry (salted hash) — signing out does
    // not delete the account, so the same details work again (QA AUTH-02).
    setState(() => _busy = true);
    final result = await Auth.signIn(_user.text, _pass.text);
    if (!mounted) return;
    setState(() => _busy = false);
    if (!result.ok) {
      setState(() => err = result.error!);
      return;
    }
    unawaited(Nav.replace(context, '/'));
  }

  @override
  Widget build(BuildContext context) {
    return GateChrome(
      back: widget.onBack,
      child: _GateForm(
        children: <Widget>[
          const GateHero(title: 'تسجيل الدخول', sub: 'اسم المستخدم وكلمة السر', tight: true),
          _label('اسم المستخدم'),
          _input(controller: _user, focusNode: _userFocus, dir: TextDirection.ltr, onSubmitted: (_) => _submit()),
          _label('كلمة السر'),
          _input(controller: _pass, focusNode: _passFocus, dir: TextDirection.ltr, obscure: true, onSubmitted: (_) => _submit()),
          if (err.isNotEmpty) FormError(err),
          GateCta(label: _busy ? 'جارٍ الدخول…' : 'دخول', disabled: _busy, onTap: _submit),
        ],
      ),
    );
  }
}

class _SignUp extends StatefulWidget {
  const _SignUp({required this.onBack});

  final VoidCallback onBack;

  @override
  State<_SignUp> createState() => _SignUpState();
}

class _SignUpState extends State<_SignUp> {
  int step = 0;
  String role = 'seeker';
  final TextEditingController _name = TextEditingController();
  final TextEditingController _phone = TextEditingController();
  final TextEditingController _user = TextEditingController();
  final TextEditingController _pass = TextEditingController();
  final TextEditingController _fal = TextEditingController();
  String city = 'الرياض';
  String err = '';
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    for (final c in <TextEditingController>[_name, _phone, _user, _pass]) {
      c.addListener(() => setState(() {}));
    }
  }

  /// Checks the details before the SMS code step (QA AUTH-03: the phone
  /// format is checked here). With a server, it checks them too (a taken
  /// username or number) and sends the code.
  Future<void> _toCode() async {
    if (_busy) return;
    final error = Auth.server
        ? LocalAuth.nameError(_name.text) ??
              LocalAuth.phoneError(_phone.text) ??
              LocalAuth.usernameError(_user.text) ??
              LocalAuth.passwordError(_pass.text)
        : LocalAuth.validateSignUp(name: _name.text, phone: _phone.text, username: _user.text, password: _pass.text);
    setState(() => err = error ?? '');
    if (error != null) return;
    if (!Auth.server) {
      setState(() => step = 2);
      return;
    }
    setState(() => _busy = true);
    final failed = await ServerAuth.register(
      role: role,
      name: _name.text,
      phone: _phone.text,
      city: city,
      username: _user.text,
      password: _pass.text,
      fal: role == 'broker' ? _fal.text : null,
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      err = failed ?? '';
      if (failed == null) step = 2;
    });
  }

  /// The server's check of the SMS code: the account is created and signed in.
  final OperationKey _wishOperation = OperationKey('wish');

  /// The seeker's first wish, on the server (POST /preferences): the wish saved, or the line to show.
  Future<(Wish?, String?)> _saveWish(Wish wish) async {
    try {
      final saved = await ServerWishes.save(wish, operation: _wishOperation.forBody(ServerWishes.form(wish)));
      _wishOperation.done();
      return (saved, null);
    } on ApiError catch (e) {
      return (null, e.forUser);
    }
  }

  Future<String?> _verify(String code) async {
    final result = await ServerAuth.verify(normalizeSaudiMobile(_phone.text) ?? _phone.text, code);
    return result.ok ? null : result.error;
  }

  @override
  void dispose() {
    for (final c in <TextEditingController>[_name, _phone, _user, _pass, _fal]) {
      c.dispose();
    }
    super.dispose();
  }

  /// Creates the account (and saves the seeker's [wish] in it). With a server
  /// the account already exists (the code created it), and the wish is saved
  /// there before this (_WishSetup.save): the server asks a seeker for it first.
  void _finish({Wish? wish}) {
    if (Auth.server) {
      if (wish != null) store.saveWish(wish);
      Nav.replace(context, role == 'seeker' ? '/account/matching' : '/');
      return;
    }
    final result = LocalAuth.signUp(
      name: _name.text,
      phone: _phone.text,
      username: _user.text,
      password: _pass.text,
      role: role,
      city: city,
      fal: _fal.text,
    );
    if (!result.ok) {
      setState(() {
        err = result.error!;
        step = 1;
      });
      return;
    }
    if (wish != null) store.saveWish(wish);
    if (role == 'seeker') {
      Nav.replace(context, '/account/matching');
    } else {
      Nav.replace(context, '/');
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget body;
    if (step == 0) {
      body = Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          const GateHero(title: 'ما دورك في زون؟', sub: 'اختر مرة واحدة — يضبط خدماتك', tight: true),
          const SizedBox(height: 18),
          for (var i = 0; i < _roles.length; i++) ...<Widget>[
            if (i > 0) const SizedBox(height: 10),
            _RoleCard(role: _roles[i], on: role == _roles[i], onTap: () => setState(() => role = _roles[i])),
          ],
          const SizedBox(height: 18),
          GateCta(label: 'متابعة', onTap: () => setState(() => step = 1)),
        ],
      );
    } else if (step == 1) {
      final ok = _name.text.isNotEmpty && _phone.text.isNotEmpty && _user.text.isNotEmpty && _pass.text.length >= 8;
      body = _GateForm(
        children: <Widget>[
          const GateHero(title: 'بياناتك', sub: 'الاسم والجوال واسم المستخدم', tight: true),
          _label('الاسم أو الشركة'),
          _input(controller: _name),
          _label('الجوال'),
          _input(controller: _phone, dir: TextDirection.ltr, placeholder: '05XXXXXXXX', keyboard: TextInputType.phone),
          _label('المدينة'),
          _select(value: city, options: optsOf(kCities.keys), onChanged: (v) => setState(() => city = v)),
          if (role == 'broker') ...<Widget>[_label('رخصة فال'), _input(controller: _fal, dir: TextDirection.ltr, placeholder: 'رقم الرخصة')],
          _label('اسم المستخدم'),
          _input(controller: _user, dir: TextDirection.ltr),
          _label('كلمة السر'),
          _input(controller: _pass, dir: TextDirection.ltr, obscure: true),
          if (err.isNotEmpty) FormError(err),
          GateCta(label: _busy ? 'جارٍ الإرسال…' : 'تحقق من الجوال', disabled: !ok || _busy, onTap: _toCode),
        ],
      );
    } else if (step == 2) {
      final phone = normalizeSaudiMobile(_phone.text) ?? _phone.text;
      body = _OtpStep(
        phone: phone,
        verify: Auth.server ? _verify : null,
        resend: Auth.server ? () => ServerAuth.resend(phone) : null,
        onOk: () {
          if (role == 'seeker') {
            setState(() => step = 3);
          } else {
            _finish();
          }
        },
      );
    } else {
      body = _WishSetup(
        city: city,
        save: Auth.server ? _saveWish : null,
        onDone: (w) => _finish(wish: w),
      );
    }
    // With a server the code created the account: the wish step has no way back to the code.
    final back = step == 0 ? widget.onBack : (Auth.server && step == 3 ? null : () => setState(() => step -= 1));
    return GateChrome(back: back, child: body);
  }
}

class _RoleCard extends StatelessWidget {
  const _RoleCard({required this.role, required this.on, required this.onTap});

  final String role;
  final bool on;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tap(
      onTap: onTap,
      child: Container(
        constraints: const BoxConstraints(minHeight: 76),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: AppColors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: on ? AppColors.forest : AppColors.mistLine),
        ),
        child: Row(
          children: <Widget>[
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(color: AppColors.mist, borderRadius: BorderRadius.circular(14)),
              alignment: Alignment.center,
              child: LucideIcon(_ico[role]!, size: 20, strokeWidth: 1.8, color: AppColors.forest),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Tx(kRoleLabel[role] ?? '', style: ts(16, w: 700, lh: 24, c: AppColors.deep)),
                  const SizedBox(height: 3),
                  Tx(_hint[role]!, style: ts(13, w: 500, lh: 19.5, c: AppColors.stone)),
                ],
              ),
            ),
            const SizedBox(width: 12),
            SizedBox(
              width: 22,
              child: Align(
                alignment: AlignmentDirectional.centerEnd,
                child: Container(
                  width: 20,
                  height: 20,
                  decoration: BoxDecoration(
                    color: on ? AppColors.white : null,
                    shape: BoxShape.circle,
                    border: Border.all(color: on ? AppColors.forest : const Color(0xFFD5DDD8), width: on ? 6 : 2),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WishSetup extends StatefulWidget {
  const _WishSetup({required this.city, required this.onDone, this.save});

  final String city;
  final ValueChanged<Wish> onDone;

  /// With a server: saves the wish there first — the wish saved, or the line to show.
  final Future<(Wish?, String?)> Function(Wish wish)? save;

  @override
  State<_WishSetup> createState() => _WishSetupState();
}

class _WishSetupState extends State<_WishSetup> {
  late String c = widget.city.isNotEmpty ? widget.city : 'الرياض';
  late String hood = () {
    final list = kCities[widget.city.isNotEmpty ? widget.city : 'الرياض'] ?? const <String>[];
    return list.isNotEmpty ? list.first : '';
  }();
  String type = 'all';
  final TextEditingController _text = TextEditingController();
  bool _busy = false;
  String _err = '';

  Future<void> _done(Wish wish) async {
    final save = widget.save;
    if (save == null) return widget.onDone(wish);
    setState(() {
      _busy = true;
      _err = '';
    });
    final (saved, error) = await save(wish);
    if (!mounted) return;
    setState(() {
      _busy = false;
      _err = error ?? '';
    });
    if (saved != null) widget.onDone(saved);
  }

  @override
  void initState() {
    super.initState();
    _text.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final hoodOpts = kCities[c] ?? const <String>[];
    return _GateForm(
      children: <Widget>[
        const GateHero(title: 'رغبتك في زون', sub: 'مدينة وحي ونوع — ثم وصف يغذي مساعد المطابقة', tight: true),
        _label('المدينة'),
        _select(
          value: c,
          options: optsOf(kCities.keys),
          onChanged: (v) => setState(() {
            c = v;
            final l = kCities[v] ?? const <String>[];
            hood = l.isNotEmpty ? l.first : '';
          }),
        ),
        _label('الحي'),
        _select(value: hood, options: optsOf(hoodOpts), onChanged: (v) => setState(() => hood = v)),
        _label('نوع العقار'),
        _select(
          value: type,
          options: <Opt>[const Opt('all', 'كل الأنواع'), for (final t in kPropertyTypes) Opt(t, kTypeLabel[t]!)],
          onChanged: (v) => setState(() => type = v),
        ),
        _label('وصف الرغبة'),
        FieldBox(
          controller: _text,
          placeholder: 'فيلا عوائل شمال الرياض قرب مدارس…',
          style: ts(16, lh: 24, c: AppColors.deep),
          height: 96,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: _fieldDeco,
          multiline: true,
        ),
        if (_err.isNotEmpty) FormError(_err),
        GateCta(
          label: _busy ? 'جارٍ الحفظ…' : 'حفظ الرغبة والدخول',
          disabled: _text.text.trim().isEmpty || _busy,
          onTap: () => _done(
            Wish(
              city: c,
              hoods: hood.isNotEmpty ? <String>[hood] : const <String>[],
              types: type == 'all' ? const <String>[] : <String>[type],
              budget: '',
              text: _text.text.trim(),
            ),
          ),
        ),
      ],
    );
  }
}

class _OtpStep extends StatefulWidget {
  const _OtpStep({required this.phone, required this.onOk, this.verify, this.resend});

  final String phone;
  final VoidCallback onOk;

  /// The server's check of the code: null when right, else the line to show.
  /// Null in the test build, which accepts its fixed code [kTestOtp].
  final Future<String?> Function(String code)? verify;

  /// Sends the code again (the server's); null when sent, else the error.
  final Future<String?> Function()? resend;

  @override
  State<_OtpStep> createState() => _OtpStepState();
}

/// Zero-width placeholder kept in empty boxes so a backspace on an empty box
/// can be seen (it moves the focus back to the previous box).
const String _zw = '​';

class _OtpStepState extends State<_OtpStep> {
  final List<String> digits = List<String>.filled(6, '');
  bool _checking = false;
  String _error = '';
  String _resent = '';
  late final List<TextEditingController> _ctl = List<TextEditingController>.generate(6, (_) => TextEditingController(text: _zw));
  late final List<FocusNode> _focus = List<FocusNode>.generate(6, (_) => FocusNode());

  @override
  void dispose() {
    for (final c in _ctl) {
      c.dispose();
    }
    for (final f in _focus) {
      f.dispose();
    }
    super.dispose();
  }

  void _set(int i, String shown) {
    _ctl[i].value = TextEditingValue(
      text: shown,
      selection: TextSelection.collapsed(offset: shown.length),
    );
  }

  void _changed(int i, String raw) {
    final text = raw.replaceAll(_zw, '');
    if (raw.isEmpty && digits[i].isEmpty) {
      // backspace on an empty box
      _set(i, _zw);
      if (i > 0) _focus[i - 1].requestFocus();
      return;
    }
    // maxLength=1: a full box ignores further input
    if (digits[i].isNotEmpty && text.length > 1) {
      _set(i, _zw + digits[i]);
      return;
    }
    final only = text.replaceAll(RegExp(r'\D'), '');
    final v = only.isEmpty ? '' : only.substring(only.length - 1);
    setState(() => digits[i] = v);
    _set(i, _zw + v);
    if (v.isNotEmpty && i < 5) _focus[i + 1].requestFocus();
    if (v.isNotEmpty && i == 5) {
      if (widget.verify != null) {
        unawaited(_check());
      } else if (digits.join() == kTestOtp) {
        widget.onOk();
      }
    }
  }

  /// The server checks the six digits.
  Future<void> _check() async {
    final verify = widget.verify;
    final code = digits.join();
    if (verify == null || code.length < 6 || _checking) return;
    setState(() {
      _checking = true;
      _error = '';
    });
    final error = await verify(code);
    if (!mounted) return;
    setState(() {
      _checking = false;
      _error = error ?? '';
    });
    if (error == null) widget.onOk();
  }

  Future<void> _resend() async {
    final resend = widget.resend;
    if (resend == null) return;
    final error = await resend();
    if (mounted) setState(() => _resent = error ?? 'أرسلنا رمزاً جديداً.');
  }

  @override
  Widget build(BuildContext context) {
    final code = digits.join();
    final server = widget.verify != null;
    final ok = server ? code.length == 6 && !_checking : code == kTestOtp;
    final to = widget.phone.isNotEmpty ? widget.phone : 'جوالك';
    return _GateForm(
      children: <Widget>[
        GateHero(
          title: 'أدخل رمز التحقق',
          sub: server ? 'أرسلنا رمزاً إلى $to' : 'أرسلنا رمزاً إلى $to — رمز النسخة التجريبية $kTestOtp',
          tight: true,
        ),
        Directionality(
          textDirection: TextDirection.ltr,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              for (var i = 0; i < 6; i++) ...<Widget>[
                if (i > 0) const SizedBox(width: 8),
                Container(
                  width: 44,
                  height: 52,
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  decoration: BoxDecoration(
                    color: AppColors.white,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFD2D2D2)),
                  ),
                  alignment: Alignment.center,
                  child: TextField(
                    controller: _ctl[i],
                    focusNode: _focus[i],
                    keyboardType: TextInputType.number,
                    textAlign: TextAlign.center,
                    style: ts(20, w: 700, lh: 30, c: AppColors.deep),
                    cursorColor: AppColors.deep,
                    decoration: const InputDecoration.collapsed(hintText: null),
                    onChanged: (v) => _changed(i, v),
                  ),
                ),
              ],
            ],
          ),
        ),
        if (_error.isNotEmpty) FormError(_error),
        if (server)
          GateCta(label: _checking ? 'جارٍ التحقق…' : 'تأكيد', disabled: !ok, onTap: _check)
        else
          GateCta(label: ok ? 'تم التحقق' : 'تأكيد', disabled: !ok, onTap: widget.onOk),
        if (widget.resend != null)
          Tap(
            onTap: _resend,
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Tx(
                _resent.isEmpty ? 'لم يصلك الرمز؟ أعد الإرسال' : _resent,
                style: ts(14, w: 600, lh: 21, c: AppColors.teal),
                align: TextAlign.center,
              ),
            ),
          ),
      ],
    );
  }
}
