const CONSENT_COOKIE = 'bw_analytics_consent';
const CONSENT_GRANTED = 'v1:granted';
const CONSENT_DENIED = 'v1:denied';
const CONSENT_MAX_AGE_SECONDS = 180 * 24 * 60 * 60;

export function readConsent(cookieHeader) {
    if (typeof cookieHeader !== 'string') return null;
    for (const part of cookieHeader.split(';')) {
        const separator = part.indexOf('=');
        if (separator === -1) continue;
        if (part.slice(0, separator).trim() !== CONSENT_COOKIE) continue;
        let value;
        try {
            value = decodeURIComponent(part.slice(separator + 1).trim());
        } catch (_) {
            return null;
        }
        return value === CONSENT_GRANTED || value === CONSENT_DENIED ? value : null;
    }
    return null;
}

export function serializeConsentCookie(value, secure, now = new Date()) {
    if (value !== CONSENT_GRANTED && value !== CONSENT_DENIED) {
        throw new TypeError('Unsupported analytics consent value.');
    }
    const expires = new Date(now.getTime() + CONSENT_MAX_AGE_SECONDS * 1000).toUTCString();
    const attributes = [
        `${CONSENT_COOKIE}=${encodeURIComponent(value)}`,
        'Path=/',
        `Max-Age=${CONSENT_MAX_AGE_SECONDS}`,
        `Expires=${expires}`,
        'SameSite=Lax',
    ];
    if (secure) attributes.push('Secure');
    return attributes.join('; ');
}

export function isAnalyticsCookieName(name) {
    return /^(?:bw_ga(?:_.+)?|_ga(?:_.+)?|_pk_id.*|_pk_ses.*|cookieconsent_status)$/.test(name);
}

export function isLegacyAnalyticsCookieName(name) {
    return /^(?:_pk_id.*|_pk_ses.*|cookieconsent_status)$/.test(name);
}

export function cookieDeletionDomains(hostname) {
    if (typeof hostname !== 'string' || hostname === '') return [null];
    const domains = [null, hostname];
    if (hostname === 'www.bewelcome.org') domains.push('.bewelcome.org');
    return domains;
}

export function serializeDeletionCookie(name, domain, secure) {
    const attributes = [
        `${name}=`, 'Path=/', 'Max-Age=0', 'Expires=Thu, 01 Jan 1970 00:00:00 GMT', 'SameSite=Lax',
    ];
    if (secure) attributes.push('Secure');
    if (domain) attributes.push(`Domain=${domain}`);
    return attributes.join('; ');
}

export function shouldLoadAnalytics(config, consent) {
    return Boolean(config
        && config.measurementId
        && config.page
        && consent === CONSENT_GRANTED);
}

function writeConsent(value) {
    document.cookie = serializeConsentCookie(value, window.location.protocol === 'https:');
}

function deleteCookie(name, domain) {
    document.cookie = serializeDeletionCookie(name, domain, window.location.protocol === 'https:');
}

function cleanupCookies(predicate) {
    const names = document.cookie.split(';')
        .map((part) => part.split('=', 1)[0].trim())
        .filter(predicate);
    names.forEach((name) => {
        cookieDeletionDomains(window.location.hostname).forEach((domain) => deleteCookie(name, domain));
    });
}

function cleanupAnalyticsCookies() {
    cleanupCookies(isAnalyticsCookieName);
}

function cleanupLegacyAnalyticsCookies() {
    cleanupCookies(isLegacyAnalyticsCookieName);
}

function createGtag() {
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function gtag() { window.dataLayer.push(arguments); };
    return window.gtag;
}

function deniedConsentValues() {
    return {
        analytics_storage: 'denied',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
    };
}

function grantedConsentValues() {
    return {...deniedConsentValues(), analytics_storage: 'granted'};
}

function parseConfiguration(root) {
    try {
        return JSON.parse(root.dataset.analyticsConfiguration || '{}');
    } catch (_) {
        return {};
    }
}

export function initializeAnalytics(root) {
    const config = parseConfiguration(root);
    const {measurementId} = config;
    let consent = readConsent(document.cookie);
    let analyticsActive = false;
    let tagLoading = false;
    let previousFocus = null;
    let consentChannel = null;

    const banner = root.querySelector('.js-analytics-banner');
    const settingsButton = root.querySelector('.js-analytics-settings-open');
    const settings = root.querySelector('.js-analytics-settings');
    const status = root.querySelector('.js-analytics-status');
    const allowInSettings = root.querySelector('.js-analytics-settings-allow');
    const rejectInSettings = root.querySelector('.js-analytics-settings-reject');
    const withdrawButton = root.querySelector('.js-analytics-withdraw');

    function updateUi() {
        if (banner) banner.hidden = !(config.page && consent === null);
        if (settingsButton) settingsButton.hidden = banner?.hidden === false;
        if (status) {
            const key = consent === CONSENT_GRANTED ? 'granted' : (consent === CONSENT_DENIED ? 'denied' : 'unset');
            status.textContent = status.dataset[key] || '';
        }
        if (allowInSettings) allowInSettings.hidden = consent === CONSENT_GRANTED;
        if (rejectInSettings) rejectInSettings.hidden = consent !== null;
        if (withdrawButton) withdrawButton.hidden = consent !== CONSENT_GRANTED;
    }

    function sendConfiguredMeasurement() {
        tagLoading = false;
        if (analyticsActive) return;
        if (
            !shouldLoadAnalytics(config, readConsent(document.cookie))
            || window[`ga-disable-${measurementId}`] === true
        ) {
            return;
        }
        const gtag = createGtag();
        const page = config.page;
        gtag('config', measurementId, {
            send_page_view: false,
            page_location: page.page_location,
            page_title: page.page_title,
            page_referrer: '',
            ignore_referrer: true,
            content_group: page.content_group,
            language: page.language,
            login_state: page.login_state,
            allow_google_signals: false,
            allow_ad_personalization_signals: false,
            cookie_prefix: 'bw',
            cookie_domain: 'none',
            cookie_path: '/',
            cookie_expires: CONSENT_MAX_AGE_SECONDS,
            cookie_update: false,
            cookie_flags: 'SameSite=Lax;Secure',
        });
        gtag('event', 'page_view');
        analyticsActive = true;
    }

    function loadAnalytics() {
        if (!shouldLoadAnalytics(config, consent) || tagLoading || analyticsActive) return;
        const existingScript = document.querySelector('script[data-bw-analytics]');
        window[`ga-disable-${measurementId}`] = false;
        const gtag = createGtag();
        if (existingScript) {
            gtag('consent', 'update', grantedConsentValues());
            sendConfiguredMeasurement();
            return;
        }
        gtag('consent', 'default', deniedConsentValues());
        gtag('consent', 'update', grantedConsentValues());
        gtag('js', new Date());
        const script = document.createElement('script');
        script.async = true;
        script.dataset.bwAnalytics = 'true';
        script.referrerPolicy = 'no-referrer';
        script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`;
        script.addEventListener('load', sendConfiguredMeasurement, {once: true});
        script.addEventListener('error', () => {
            script.remove();
            analyticsActive = false;
            tagLoading = false;
        }, {once: true});
        tagLoading = true;
        document.head.appendChild(script);
    }

    function closeSettings() {
        if (!settings?.open) return;
        settings.close();
    }

    function allowAnalytics() {
        consent = CONSENT_GRANTED;
        writeConsent(consent);
        updateUi();
        closeSettings();
        loadAnalytics();
    }

    function applyBlockedConsent(nextConsent, persist) {
        if (measurementId) window[`ga-disable-${measurementId}`] = true;
        if ((analyticsActive || tagLoading) && typeof window.gtag === 'function') {
            window.gtag('consent', 'update', deniedConsentValues());
        }
        analyticsActive = false;
        tagLoading = false;
        cleanupAnalyticsCookies();
        consent = nextConsent;
        if (persist) writeConsent(CONSENT_DENIED);
        updateUi();
    }

    function rejectAnalytics() {
        applyBlockedConsent(CONSENT_DENIED, true);
        consentChannel?.postMessage(CONSENT_DENIED);
        closeSettings();
    }

    function synchronizeConsent() {
        const browserConsent = readConsent(document.cookie);
        if (browserConsent === consent) return;

        if (browserConsent === CONSENT_GRANTED) {
            consent = CONSENT_GRANTED;
            updateUi();
            loadAnalytics();
        } else {
            applyBlockedConsent(browserConsent, false);
        }
        closeSettings();
    }

    function openSettings() {
        if (!settings || settings.open) return;
        previousFocus = document.activeElement;
        updateUi();
        settings.showModal();
        document.body.classList.add('bw-analytics-settings-open');
        settings.querySelector('button:not([hidden]), a[href]')?.focus();
    }

    root.querySelectorAll('[data-analytics-action="allow"]').forEach((button) => button.addEventListener('click', allowAnalytics));
    root.querySelectorAll('[data-analytics-action="reject"]').forEach((button) => button.addEventListener('click', rejectAnalytics));
    root.querySelectorAll('[data-analytics-action="close"]').forEach((button) => button.addEventListener('click', closeSettings));
    settingsButton?.addEventListener('click', openSettings);
    settings?.addEventListener('close', () => {
        document.body.classList.remove('bw-analytics-settings-open');
        if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
        previousFocus = null;
    });
    if (typeof window.BroadcastChannel === 'function') {
        try {
            consentChannel = new window.BroadcastChannel('bw-analytics-consent');
            consentChannel.addEventListener('message', (event) => {
                if (event.data === CONSENT_DENIED) synchronizeConsent();
            });
        } catch (_) {
            consentChannel = null;
        }
    }
    window.addEventListener('pageshow', synchronizeConsent);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) synchronizeConsent();
    });

    cleanupLegacyAnalyticsCookies();
    if (!measurementId || consent !== CONSENT_GRANTED) cleanupAnalyticsCookies();
    updateUi();
    loadAnalytics();
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('[data-analytics-configuration]');
        if (root) initializeAnalytics(root);
    });
}
