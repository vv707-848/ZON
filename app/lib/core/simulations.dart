// What this build simulates. A store build sets `--dart-define=ZON_SIMULATIONS=false`.

/// Whether this build simulates what the server and its providers will do:
/// - payments, the REGA contract approval and licence numbers, which stop at
///   «waiting for the service» when off;
/// - the sample market shown with the account's own data: listings and
///   requests (`data/seed`), chats, clients, owners, stock, followers, tasks,
///   the people a match scan shows, and the public profile's counts.
///
/// Off, nothing is made up: what the account did not create and the server
/// did not send is not shown.
const bool kSimulations = bool.fromEnvironment('ZON_SIMULATIONS', defaultValue: true);
