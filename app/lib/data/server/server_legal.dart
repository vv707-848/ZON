// The policy pages with a server (docs/api.md «التطبيق»), the same text as zon.sa/legal/<slug>:
//
//   GET /legal          the published pages, in their order (a draft is not listed)
//   GET /legal/{slug}   one page: its title, its text (Markdown: `## ` a heading, `- ` a list item), its version
//                       and when it was published; a draft answers 404

import 'package:zon/core/net/api.dart';

/// A policy page as the server tells it; [body] only from [ServerLegal.page].
class LegalPage {
  const LegalPage({required this.slug, required this.title, this.version, this.publishedAt, this.body = ''});

  factory LegalPage.fromJson(Map<String, Object?> j) => LegalPage(
    slug: '${j['slug'] ?? ''}',
    title: '${j['title'] ?? ''}',
    version: j['version'] == null ? null : '${j['version']}',
    publishedAt: DateTime.tryParse('${j['published_at'] ?? ''}')?.toLocal(),
    body: j['body'] is String ? j['body']! as String : '',
  );

  final String slug;
  final String title;
  final String? version;
  final DateTime? publishedAt;
  final String body;
}

abstract final class ServerLegal {
  static Future<List<LegalPage>> list() async => <LegalPage>[
    for (final row in (await Api.client!.get('legal')).list) LegalPage.fromJson(row),
  ];

  static Future<LegalPage> page(String slug) async => LegalPage.fromJson((await Api.client!.get('legal/${Uri.encodeComponent(slug)}')).map);
}

/// One block of a page's Markdown: a heading, a list item, or a paragraph.
typedef LegalBlock = ({String kind, String text});

/// The Markdown the panel's editor asks for (its helper text): `## ` a heading, `- ` a list item, the rest
/// paragraphs with a blank line between them. The website renders it as CommonMark; here bold and italic marks
/// and a link's address are dropped, and a link keeps its words — never shown as raw `**` or `](…)`.
List<LegalBlock> legalBlocks(String body) {
  final out = <LegalBlock>[];
  final paragraph = <String>[];
  void flush() {
    if (paragraph.isNotEmpty) out.add((kind: 'p', text: _inline(paragraph.join(' '))));
    paragraph.clear();
  }

  for (final raw in body.replaceAll('\r\n', '\n').split('\n')) {
    final line = raw.trim();
    if (line.isEmpty) {
      flush();
    } else if (RegExp(r'^#{1,6}\s+').hasMatch(line)) {
      flush();
      out.add((kind: 'h', text: _inline(line.replaceFirst(RegExp(r'^#{1,6}\s+'), ''))));
    } else if (RegExp(r'^[-*•]\s+').hasMatch(line)) {
      flush();
      out.add((kind: 'li', text: _inline(line.replaceFirst(RegExp(r'^[-*•]\s+'), ''))));
    } else if (RegExp(r'^\d+[.)]\s+').hasMatch(line)) {
      flush();
      out.add((kind: 'li', text: _inline(line)));
    } else {
      paragraph.add(line);
    }
  }
  flush();
  return out;
}

String _inline(String text) => text
    .replaceAllMapped(RegExp(r'\[([^\]]+)\]\([^)]*\)'), (m) => m[1]!)
    .replaceAll(RegExp(r'\*\*|__'), '')
    .replaceAllMapped(RegExp(r'(^|\s)[*_]([^*_]+)[*_](?=\s|$|[.،,:؛])'), (m) => '${m[1]}${m[2]}');
