'use strict';

const form = document.querySelector('#redeem');
if (form) {
  const token = window.location.hash.slice(1);
  if (/^[0-9a-f]{64}$/.test(token)) {
    form.elements.token.value = token;
    form.hidden = false;
    document.querySelector('#missing-link').hidden = true;
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
  }
}
