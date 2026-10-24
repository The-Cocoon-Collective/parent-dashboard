/* ---------------------------------------------------------------------------
   The Cocoon Collective™ — parent portal
   Three small behaviours. No libraries, no analytics, no third-party anything.
   The page works with this file blocked; everything here is a convenience.
   --------------------------------------------------------------------------- */

(function () {
  'use strict';

  /* --- copy buttons ------------------------------------------------------- */

  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = document.querySelector(btn.getAttribute('data-copy'));
      if (!el) return;
      var text = (el.textContent || '').trim();
      var said = btn.textContent;

      var done = function () {
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = said; }, 1600);
      };

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, fallback);
      } else {
        fallback();
      }

      function fallback() {
        // Older browsers, and anything served over plain http.
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* leave the label */ }
        document.body.removeChild(ta);
      }
    });
  });

  /* --- "text this to my kid" ----------------------------------------------
     Uses the device's own share sheet where there is one, which on a phone is
     the shortest path from this page to the child's screen. On a desktop with
     no share sheet it copies instead, and says so.                          */

  var share = document.querySelector('[data-share]');
  if (share) {
    share.addEventListener('click', function () {
      var text = share.getAttribute('data-text') || '';
      if (navigator.share) {
        navigator.share({ text: text }).catch(function () { /* dismissed */ });
      } else if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () {
          share.textContent = 'Copied — paste it into a message';
        });
      } else {
        share.textContent = 'Copy the code above and send it yourself';
      }
    });
  }

  /* --- the younger band forces voice on ------------------------------------
     The form enforces the platform rule rather than offering it. The server
     enforces it too; this is only so the parent is not left looking at a
     checkbox that will be ignored.                                          */

  var voiceBox    = document.getElementById('voice-box');
  var voiceToggle = document.getElementById('voice-toggle');
  var voiceNote   = document.getElementById('voice-note');

  if (voiceBox && voiceToggle) {
    var originalNote = voiceNote ? voiceNote.innerHTML : '';

    var apply = function () {
      var band = document.querySelector('input[name="age_band"]:checked');
      var young = band && band.value === '6-7';

      voiceBox.checked  = young ? true : voiceBox.checked;
      voiceBox.disabled = !!young;
      voiceToggle.classList.toggle('locked', !!young);

      if (voiceNote) {
        voiceNote.innerHTML = young
          ? 'Always on for 6 to 7 year olds. They cannot type fast enough to play with a ' +
            'friend, and if there is no voice here they will use a phone or another app ' +
            'where nobody is looking after them. We would rather it happened somewhere we ' +
            'can keep an eye on it.'
          : originalNote;
      }
    };

    document.querySelectorAll('input[name="age_band"]').forEach(function (r) {
      r.addEventListener('change', apply);
    });
    apply();
  }
})();
