/*
 * The payment page: starts Moyasar's form (cdn.moyasar.com) from the settings the page carries in
 * <script type="application/json" id="pay-config">. No inline script, so the page's CSP stays strict.
 * The card is typed into Moyasar's form and sent to Moyasar; our server never sees it.
 */
(function () {
  var node = document.getElementById('pay-config');
  if (!node) return;
  if (!window.Moyasar) {
    var failed = document.getElementById('pay-unavailable');
    if (failed) failed.hidden = false;
    return;
  }
  var c = JSON.parse(node.textContent);
  var options = {
    element: '.mysr-form',
    language: 'ar',
    amount: c.amount,
    currency: c.currency,
    description: c.description,
    publishable_api_key: c.key,
    callback_url: c.callback,
    methods: c.methods,
    supported_networks: c.networks,
    metadata: c.metadata
  };
  if (c.applePay) options.apple_pay = c.applePay;
  window.Moyasar.init(options);
})();
