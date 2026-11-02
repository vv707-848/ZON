// Every price, percentage and plan quota of the app — in one place.
//
// Change a number here and every screen, check and test that uses it
// follows. When the server is connected these values come from its
// `packages` table (see docs/ARCHITECTURE.md); this file then only keeps the
// offline defaults.

/// Prices (SAR) and percentages.
abstract final class Pricing {
  // ---------------------------------------------------------------- broker
  /// Broker plan, monthly subscription.
  static const int brokerMonthly = 280;

  /// Broker plan, yearly subscription.
  static const int brokerYearly = 2300;

  /// Saving of the yearly plan: 280 × 12 − 2300 = 1060.
  static const int brokerYearlySaving = brokerMonthly * 12 - brokerYearly;

  /// Without a subscription, Zon shares this percentage of the broker's
  /// commission (السعي) when a deal is completed — of the commission, never of
  /// the property price.
  static const int brokerFreeCommissionSharePercent = 10;

  // ---------------------------------------------------------------- seeker
  /// Seeker plan, monthly subscription.
  static const int seekerMonthly = 199;

  // ---------------------------------------------------------------- owner
  /// Owner plan: commission (السعي) due after the advertised property is sold,
  /// as a percentage of its sale price. Tied to that property and its
  /// brokerage contract only.
  static const double ownerCommissionPercent = 0.5;

  /// Advertising licence fee the owner pays for the General Authority for Real
  /// Estate (REGA). A business input from the app owner — check it against
  /// REGA's current fee before any real collection. Not a subscription.
  static const int adLicenceFee = 50;

  // ---------------------------------------------------------------- requests
  /// An urgent search request (طلب عاجل) not covered by an included quota.
  /// A normal request is always free.
  static const int urgentRequestPrice = 50;

  // ---------------------------------------------------------------- listing boosts
  /// Featuring a listing («مميز») once the included quota is used. The app
  /// owner has not set a new price for listing boosts; this is the existing
  /// price of version 1.1 (the 50 of urgent requests does not apply here).
  static const int featuredListingPrice = 100;

  /// Marking a listing urgent («عاجل») once the included quota is used — same
  /// existing boost price, pending the owner's decision.
  static const int urgentListingPrice = 100;

  /// How long one boost keeps a listing featured / urgent. The approved
  /// screens spec (§18) says "3 days or a week, renewable"; this build uses a
  /// week until the owner confirms. It is NOT the validity of the yearly
  /// quota (a boost never lasts a whole year by itself).
  static const Duration featuredDuration = Duration(days: 7);
  static const Duration urgentListingDuration = Duration(days: 7);
}

/// Plan ids saved with a subscription.
abstract final class Plans {
  static const String brokerYear = 'broker-year';
  static const String brokerMonth = 'broker-month';
  static const String seeker = 'seeker';

  /// Not bought: activated by a brokerage contract + a published listing.
  static const String owner = 'owner';

  /// Length of one paid period.
  static Duration periodOf(String plan) => plan == brokerYear ? const Duration(days: 365) : const Duration(days: 30);

  /// Price of one period.
  static int priceOf(String plan) => switch (plan) {
    brokerYear => Pricing.brokerYearly,
    brokerMonth => Pricing.brokerMonthly,
    seeker => Pricing.seekerMonthly,
    _ => 0,
  };

  static bool isBroker(String? plan) => plan == brokerYear || plan == brokerMonth;
}

/// What one paid period of a plan includes.
class PlanQuota {
  const PlanQuota({this.featured = 0, this.urgentListing = 0, this.urgentRequests = 0});

  /// Listing boosts «مميز».
  final int featured;

  /// Listing boosts «عاجل».
  final int urgentListing;

  /// Urgent search requests (normal requests are free and never counted).
  final int urgentRequests;

  static const PlanQuota none = PlanQuota();
}

/// Included quotas per plan, for ONE period of that plan (a year for
/// `broker-year`, a month for the monthly plans).
const Map<String, PlanQuota> kPlanQuotas = <String, PlanQuota>{
  // Numbers set by the app owner (brief P02). Reading used here, pending his
  // confirmation: 15 and 10 are listing boosts, 5 is a separate quota of
  // urgent search requests; normal requests stay free.
  Plans.brokerYear: PlanQuota(featured: 15, urgentListing: 10, urgentRequests: 5),
  // Not set by the owner for the monthly plan. Kept from the approved screens
  // spec §18 ("one featuring per month"); no urgent quotas. Pending decision.
  Plans.brokerMonth: PlanQuota(featured: 1),
  Plans.seeker: PlanQuota.none,
  Plans.owner: PlanQuota.none,
};
