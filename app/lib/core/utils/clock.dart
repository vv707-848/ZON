// The app's clock — one place to read "now", so tests can move time
// (e.g. check that a yearly quota is not refilled after a month).

/// Returns the current time. Tests may replace it; the app never does.
DateTime Function() appClock = DateTime.now;

/// The current time according to [appClock].
DateTime appNow() => appClock();
