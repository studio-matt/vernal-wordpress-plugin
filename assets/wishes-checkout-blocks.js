(function () {
  var reg = window.wc && window.wc.wcBlocksRegistry;
  if (!reg || !window.wp || !window.wp.element) return;
  var data = (window.wc.wcSettings && window.wc.wcSettings.getSetting('wishes_data', {})) || {};
  var el = window.wp.element.createElement;
  var decode = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };
  var title = decode(data.title || 'Pay with card, Apple Pay or Google Pay');
  var Content = function () {
    return el('div', null, decode(data.description || ''), el('div', { id: 'wishes-inline-mount' }));
  };
  reg.registerPaymentMethod({
    name: 'wishes',
    label: el('span', null, title),
    ariaLabel: title,
    content: el(Content),
    edit: el(Content),
    canMakePayment: function () { return true; },
    placeOrderButtonLabel: 'Continue to payment',
    supports: { features: data.supports || ['products', 'refunds'] }
  });
})();
