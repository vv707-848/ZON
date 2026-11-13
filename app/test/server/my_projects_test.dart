// A developer's projects with a server (lib/data/server/server_my_projects.dart; project_form.dart,
// project_review_screen.dart, my_listings_view.dart): a ready project's licence asked of REGA per model, the
// project and its models sent once, each model's licence; the wording of each text and his decisions, then
// publishing; a send stopped midway completed by the next; «مشاريعي» with a draft and pause / resume. The server's
// answers are the ones its tests recorded (docs/contracts/fixtures/api).

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zon/app/router.dart';
import 'package:zon/features/add/widgets/add_kit.dart';

import '../support/server_env.dart';
import '../support/test_env.dart';

Map<String, Object?> recorded(String name) =>
    (jsonDecode(File('../docs/contracts/fixtures/api/$name.json').readAsStringSync()) as Map).cast<String, Object?>();

Map<String, Object?> _one(String name) => (recorded(name)['data']! as Map).cast<String, Object?>();

Finder _inField(String label) => find.descendant(
  of: find.ancestor(of: find.text(label), matching: find.byType(ProjField)).first,
  matching: find.byType(EditableText),
);

Finder _byHint(String hint) => find.ancestor(of: find.text(hint), matching: find.byType(TextField));

String _path() {
  String? name;
  Nav.key.currentState!.popUntil((route) {
    name = route.settings.name;
    return true;
  });
  return name ?? '';
}

Future<void> _settle(WidgetTester tester, [int times = 6]) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _enter(WidgetTester tester, Finder f, String text) async {
  await tester.ensureVisible(f);
  await tester.enterText(f, text);
  await tester.pump();
}

void main() {
  final project = _one('project_created')['id']! as int;
  final unit = _one('project_unit_created')['id']! as int;

  Future<FakeServer> asDeveloper(WidgetTester tester, String path, [void Function(FakeServer)? before]) async {
    await freshDevice();
    final server = serverDevice();
    before?.call(server);
    await serverSignIn(
      server,
      account: me(id: 41, role: 'developer', name: 'سنابل العسكر'),
    );
    await pumpApp(tester, path);
    await _settle(tester);
    return server;
  }

  Future<void> fillProject(WidgetTester tester) async {
    await _enter(tester, _inField('العنوان'), 'مشروع الأفق');
    await _enter(tester, _byHint('قريب من الخدمات والمدارس والمسجد…'), 'فلل حديثة بتشطيب فاخر وحدائق خاصة');
    await _enter(tester, _inField('اسم الوحدة'), 'C1');
    await _enter(tester, _inField('مساحة البناء م²'), '380');
    await _enter(tester, _inField('السعر الثابت'), '1950000');
    await _enter(tester, _inField('عدد الوحدات من هذا النموذج'), '6');
    await _enter(tester, _byHint('أدخل وصف الوحدة هنا..'), 'نموذج C1 بخمس غرف ومجلس مستقل');
  }

  testWidgets("a ready project: each model's licence asked of REGA, the project and its model sent once, then its wording", (tester) async {
    final server = await asDeveloper(tester, '/add?type=project');
    expect(find.text('تنسيق الوصف ب AI'), findsNothing, reason: "the phone's simulated wording is not offered with a server");
    await fillProject(tester);

    server.on('POST', 'listings/licence-check', 422, <String, Object?>{
      'success': false,
      'error': <String, Object?>{
        'code': 'VALIDATION_FAILED',
        'message': 'تحقق من الحقول.',
        'details': <String, Object?>{
          'fields': <String, Object?>{
            'licence_number': <String>['رقم الترخيص الإعلاني غير صالح لدى الهيئة.'],
          },
        },
      },
    });
    final licence = find.descendant(of: find.byType(UnitLicenceField), matching: find.byType(EditableText));
    await _enter(tester, licence, '7200120000');
    await tapText(tester, 'تحقق');
    await _settle(tester);
    expect(find.textContaining('غير صالح لدى الهيئة'), findsOneWidget);
    await tapText(tester, 'متابعة');
    await _settle(tester);
    expect(server.sent('POST', 'projects'), isEmpty, reason: 'not before every model of a ready project has its licence verified');

    server.on('POST', 'listings/licence-check', 200, recorded('licence_check'));
    await _enter(tester, licence, '7200123456');
    await tapText(tester, 'تحقق');
    await _settle(tester);
    expect(FakeServer.body(server.sent('POST', 'listings/licence-check').last), <String, Object?>{'licence_number': '7200123456'});

    server.on('POST', 'projects', 201, recorded('project_created'));
    server.on('POST', 'projects/$project/units', 201, recorded('project_unit_created'));
    server.on('POST', 'projects/$project/units/$unit/licence', 200, recorded('project_unit_licence'));
    server.on('GET', 'projects/$project/review', 200, recorded('project_review'));
    await tapText(tester, 'متابعة');
    await _settle(tester, 12);

    final created = server.sent('POST', 'projects').single;
    final body = FakeServer.body(created);
    expect(body['title'], 'مشروع الأفق');
    expect(body['delivery'], 'ready');
    expect(body['total_units'], 6, reason: 'the units of its models');
    expect(created.headers['Idempotency-Key'], isNotNull);
    final model = server.sent('POST', 'projects/$project/units').single;
    expect(FakeServer.body(model), containsPair('available_count', 6));
    expect(FakeServer.body(model), containsPair('price', 1950000));
    expect(FakeServer.body(model), containsPair('model_name', 'C1'));
    expect(model.headers['Idempotency-Key'], isNotNull);
    expect(FakeServer.body(server.sent('POST', 'projects/$project/units/$unit/licence').single), <String, Object?>{'licence_number': '7200123456'});
    expect(_path(), '/account/projects/$project/review');
  });

  testWidgets('a send stopped midway is completed by the next «متابعة», with what he changed, the project not added twice', (tester) async {
    final server = await asDeveloper(tester, '/add?type=project');
    await tapText(tester, 'جاهز'); // an off-plan project: no licence asked
    await tapText(tester, 'على الخارطة', last: true);
    await fillProject(tester);
    server.on('POST', 'projects', 201, recorded('project_created'));
    server.on('POST', 'projects/$project/units', 422, <String, Object?>{
      'success': false,
      'error': <String, Object?>{'code': 'VALIDATION_FAILED', 'message': 'السعر يجب أن يكون أكبر من صفر.', 'details': <String, Object?>{}},
    });
    await tapText(tester, 'متابعة');
    await _settle(tester, 10);
    expect(find.text('السعر يجب أن يكون أكبر من صفر.'), findsOneWidget);
    expect(server.sent('POST', 'projects'), hasLength(1));

    server.on('PATCH', 'projects/$project', 200, recorded('project_created'));
    server.on('POST', 'projects/$project/units', 201, recorded('project_unit_created'));
    server.on('GET', 'projects/$project/review', 200, recorded('project_review'));
    await _enter(tester, _inField('العنوان'), 'مشروع الأفق الجديد');
    await tapText(tester, 'متابعة');
    await _settle(tester, 12);

    expect(server.sent('POST', 'projects'), hasLength(1), reason: 'not added twice');
    expect(FakeServer.body(server.sent('PATCH', 'projects/$project').single)['title'], 'مشروع الأفق الجديد');
    expect(server.sent('POST', 'projects/$project/units'), hasLength(2));
    expect(server.sent('POST', 'projects/$project/units/$unit/licence'), isEmpty, reason: 'off-plan: no licence');
    expect(_path(), '/account/projects/$project/review');
  });

  testWidgets('his decision on each text, then publishing', (tester) async {
    final server = await asDeveloper(tester, '/account/projects/$project/review', (s) => s.on('GET', 'projects/$project/review', 200, recorded('project_review')));
    final review = _one('project_review');
    final units = (review['units']! as List).cast<Map<String, Object?>>();
    expect(find.text((review['project']! as Map)['suggested']! as String), findsOneWidget);
    expect(find.text('نشر المشروع'), findsOneWidget);

    server.on('POST', 'projects/$project/review', 200, recorded('project_published'));
    server.on('POST', 'projects/$project/units/review', 200, <String, Object?>{'success': true, 'data': <Object?>[]});
    server.on('POST', 'projects/$project/publish', 200, recorded('project_published'));
    server.on('GET', 'projects/$project', 200, recorded('project_published'));
    await tapText(tester, 'موافق على الكل');
    await tester.pump();
    await tapText(tester, 'رفض', index: 2); // the second model: his own text
    await tester.pump();
    await tapText(tester, 'نشر المشروع');
    await _settle(tester, 10);

    expect(FakeServer.body(server.sent('POST', 'projects/$project/review').single), <String, Object?>{'decision': 'approve'});
    expect(FakeServer.body(server.sent('POST', 'projects/$project/units/review').single), <String, Object?>{
      'decisions': <Object?>[
        <String, Object?>{'unit_id': units[0]['id'], 'decision': 'approve'},
        <String, Object?>{'unit_id': units[1]['id'], 'decision': 'reject'},
      ],
    });
    expect(server.sent('POST', 'projects/$project/publish'), hasLength(1));
    expect(_path(), '/projects/P$project');
  });

  testWidgets('«مشاريعي»: a draft opens its review; a published project is hidden and shown again', (tester) async {
    await freshDevice();
    final server = serverDevice();
    server.on('GET', 'projects/mine', 200, recorded('projects_mine'));
    await serverSignIn(
      server,
      account: me(id: 41, role: 'developer', name: 'سنابل العسكر'),
    );
    await pumpApp(tester, '/account/listings');
    await _settle(tester);
    await tapText(tester, 'غير النشط 1'); // a draft is not public yet
    expect(find.text('مسودة — راجع الأوصاف وانشر'), findsOneWidget);
    expect(find.text('تعديل'), findsOneWidget);
    await tapText(tester, 'مسودة — راجع الأوصاف وانشر');
    await _settle(tester);
    expect(_path(), '/account/projects/$project/review');

    server.on('GET', 'projects/mine', 200, <String, Object?>{
      ...recorded('projects_mine'),
      'data': <Object?>[_one('project_published')],
    });
    server.on('POST', 'projects/$project/pause', 200, recorded('project_paused'));
    await pumpApp(tester, '/account/listings');
    await _settle(tester);
    await tapText(tester, 'إخفاء');
    await _settle(tester);
    expect(server.sent('POST', 'projects/$project/pause'), hasLength(1));
    await tapText(tester, 'غير النشط 1');
    expect(find.text('مخفي — أوقفته أنت'), findsOneWidget);

    server.on('POST', 'projects/$project/resume', 200, recorded('project_published'));
    await tapText(tester, 'إظهار');
    await _settle(tester);
    expect(server.sent('POST', 'projects/$project/resume'), hasLength(1));
    expect(find.text('مخفي — أوقفته أنت'), findsNothing);
  });
}
