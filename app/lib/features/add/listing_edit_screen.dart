// `/account/listings/:id` — edit one of my listings or projects.
//
// Opens the matching editor:
// * a project → ProjectEditor (project_editor.dart): project details plus its
//   units (add, edit, mark sold, delete);
// * a listing → ListingEditor (listing_editor.dart): the listing's fields and
//   photos.
//
// The route itself is guarded (QA ACCESS-02): a guest is sent to sign in,
// and an account that did not publish the listing gets a "not allowed"
// page — hiding the edit button is not the only protection. The editors
// check the owner again before saving. On the server the same rule must be
// enforced (403 for another account's listing).

import 'package:flutter/material.dart';

import 'package:zon/app/router.dart';
import 'package:zon/core/icons/lucide.dart';
import 'package:zon/core/layout/app_shell.dart';
import 'package:zon/core/theme/theme.dart';
import 'package:zon/core/widgets/widgets.dart';
import 'package:zon/data/store.dart';
import 'package:zon/features/add/listing_editor.dart';
import 'package:zon/features/add/project_editor.dart';

/// `/account/listings/$id` — edit a listing or a project.
class ListingEditScreen extends StatelessWidget {
  const ListingEditScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context) {
    if (store.readSession() == null) return const RedirectTo('/login');
    final listing = store.listingById(id);
    if (listing == null) {
      return const _Blocked(title: 'العرض غير موجود', text: 'ربما حُذف أو لم يعد متاحًا.');
    }
    if (!store.ownsListing(listing)) {
      return const _Blocked(title: 'لا يمكنك تعديل هذا العرض', text: 'التعديل متاح لصاحب العرض فقط.');
    }
    return AppShell(
      child: listing.kind == 'project' ? ProjectEditor(listing: listing) : ListingEditor(listing: listing),
    );
  }
}

class _Blocked extends StatelessWidget {
  const _Blocked({required this.title, required this.text});

  final String title;
  final String text;

  @override
  Widget build(BuildContext context) {
    return AppShell(
      title: 'تعديل',
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            const Center(child: LucideIcon(Lu.lock, size: 32, color: AppColors.sage)),
            const SizedBox(height: 12),
            Tx(
              title,
              style: ts(18, w: 700, lh: 27, c: AppColors.deep),
              align: TextAlign.center,
            ),
            const SizedBox(height: 6),
            Tx(
              text,
              style: ts(15, lh: 22.5, c: AppColors.muted),
              align: TextAlign.center,
            ),
            const SizedBox(height: 20),
            AppButton(label: 'عروضي', tone: ButtonTone.tonal, onTap: () => Nav.go(context, '/account/listings')),
          ],
        ),
      ),
    );
  }
}
