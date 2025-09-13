(function () {
  /**
   * Sends the tracking data to the WordPress backend via AJAX.
   * The `wplt` object is available from `wp_localize_script`.
   *
   * @param {object} payload The data to send.
   */
  function sendToServer(payload) {
    // We use URLSearchParams to format the data for admin-ajax.php
    const body = new URLSearchParams();
    body.append('action', 'wplt_record_event');
    body.append('nonce', wplt.nonce);

    // Append all payload properties to the body
    for (const key in payload) {
      if (Object.hasOwnProperty.call(payload, key)) {
        body.append(key, payload[key]);
      }
    }

    fetch(wplt.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      keepalive: true, // Ensures request is sent even if page is unloading
      body: body,
    });
  }

  function formatPath(path) {
    if (path === '/') return '/home/';
    if (!path.startsWith('/')) path = '/' + path;
    if (!path.endsWith('/')) path += '/';
    return path;
  }

  document.addEventListener('DOMContentLoaded', function () {
    // --- Part 1: Event Tracking ---
    const params = new URLSearchParams(window.location.search);
    const adKeys = ['gclid', 'gbraid', 'wbraid', 'gclsrc', 'gad_source', 'msclkid'];
    const isAdVisit = adKeys.some(k => params.has(k));
    const referrer = document.referrer;
    const isSocial = /facebook|instagram|twitter|linkedin|t\.co/i.test(referrer);

    // --- Part 1a: Store attribution data on first visit ---
    // If this is the first page view of the session, capture the initial data.
    if (!sessionStorage.getItem('wplt_tracked')) {
      sessionStorage.setItem('wplt_tracked', 'true');
      sessionStorage.setItem('entry_url', window.location.pathname);
      sessionStorage.setItem('utm_source', params.get('utm_source') || (isSocial ? 'facebook' : referrer ? new URL(referrer).hostname : ''));
      sessionStorage.setItem('utm_medium', params.get('utm_medium') || (isSocial ? 'social' : referrer ? 'referral' : ''));
      sessionStorage.setItem('utm_campaign', params.get('utm_campaign') || '');
      sessionStorage.setItem('utm_term', params.get('utm_term') || '');

      adKeys.forEach(key => {
        if (params.has(key)) sessionStorage.setItem(key, params.get(key));
      });
    }

    // UTM values (if available)
    const utm_source = sessionStorage.getItem('utm_source') || params.get('utm_source') || '';
    const utm_medium = sessionStorage.getItem('utm_medium') || params.get('utm_medium') || '';
    const utm_campaign = sessionStorage.getItem('utm_campaign') || params.get('utm_campaign') || '';
    const utm_term = sessionStorage.getItem('utm_term') || params.get('utm_term') || '';
    const ad_id = adKeys.map(k => params.get(k) || sessionStorage.getItem(k)).find(v => v) || '';

    // Fallback source/medium if UTM missing
    const fallbackSource = utm_source || (isSocial ? 'facebook' : referrer ? new URL(referrer).hostname : '');
    const fallbackMedium = utm_medium || (isSocial ? 'social' : referrer ? 'referral' : '');

    const rawEntryUrl = sessionStorage.getItem('entry_url') || window.location.pathname;
    const entryUrl = formatPath(rawEntryUrl);
    const submittingUrl = formatPath(window.location.pathname);
    const deviceType = /Mobi|Android/i.test(navigator.userAgent) ? 'Mobile' : 'Desktop';
    const trafficType = (['cpc', 'paid', 'ppc'].includes(utm_medium.toLowerCase()) || isAdVisit) ? 'Paid' : isSocial ? 'Social' : referrer ? 'Referral' : 'Direct';

    document.querySelectorAll('a[href^="tel:"], a[href^="sms:"], a[href^="mailto:"], [data-email]').forEach(function (link) {
      const hrefAttr = link.getAttribute('href') || '';
      let eventType = '';
      let eventLabel = '';

      if (hrefAttr.startsWith('tel:')) {
        eventType = 'phone_click';
        eventLabel = hrefAttr.replace('tel:', '');
      } else if (hrefAttr.startsWith('sms:')) {
        eventType = 'sms_click';
        eventLabel = hrefAttr.replace('sms:', '');
      } else if (hrefAttr.startsWith('mailto:')) {
        eventType = 'email_click';
        eventLabel = hrefAttr.replace('mailto:', '');
      } else if (link.hasAttribute('data-email')) {
        eventType = 'email_click';
        eventLabel = link.getAttribute('data-email');
      }

      if (eventType) {
        link.addEventListener('click', function () {
          sendToServer({ eventType, eventLabel, utm_source: fallbackSource, utm_medium: fallbackMedium, utm_campaign, utm_term, ad_id, entryUrl, submittingUrl, deviceType, trafficType, pageLocation: window.location.href });
        });
      }
    });

    // --- Part 2: Form Field Population ---
    const map = { "utm_campaign": "utm_campaign", "utm_term": "utm_term", "utm_source": "utm_source", "utm_medium": "utm_medium" };
    Object.keys(map).forEach(key => {
      const value = params.get(key);
      // This selector is specific, often used by form builders like Elementor.
      const field = document.querySelector(`[name="form_fields[${key}]"]`);
      if (value && field) {
        field.value = value;
      }
    });
  });
})();