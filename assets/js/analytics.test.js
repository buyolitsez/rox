import {afterEach, describe, expect, test} from 'bun:test';
import {JSDOM} from 'jsdom';

import {
    cookieDeletionDomains,
    initializeAnalytics,
    isAnalyticsCookieName,
    isLegacyAnalyticsCookieName,
    readConsent,
    serializeConsentCookie,
    serializeDeletionCookie,
    shouldLoadAnalytics,
} from './analytics.js';

const originalGlobals = {
    window: globalThis.window,
    document: globalThis.document,
};

afterEach(() => {
    for (const [name, value] of Object.entries(originalGlobals)) {
        if (value === undefined) {
            delete globalThis[name];
        } else {
            Object.defineProperty(globalThis, name, {configurable: true, value});
        }
    }
});

function configuration(overrides = {}) {
    return {
        measurementId: 'G-TEST123',
        page: {
            page_location: 'https://www.bewelcome.org/_analytics/search/member-results',
            page_title: 'Member search results',
            content_group: 'search',
            language: 'en',
            login_state: 'visitor',
        },
        ...overrides,
    };
}

function setBrowserGlobals(dom) {
    Object.defineProperty(globalThis, 'window', {configurable: true, value: dom.window});
    Object.defineProperty(globalThis, 'document', {configurable: true, value: dom.window.document});
}

function createBrowser(
    config,
    consent = null,
    BroadcastChannelClass = null,
    initialCookies = [],
    url = 'https://www.bewelcome.org/search/locations?location=private',
) {
    const dom = new JSDOM(`<!doctype html><html><head></head><body>
        <div data-analytics-configuration>
            <button class="js-analytics-settings-open">settings</button>
            <section class="js-analytics-banner" hidden>
                <button data-analytics-action="allow">allow</button>
                <button data-analytics-action="reject">reject</button>
            </section>
            <dialog class="js-analytics-settings">
                <span class="js-analytics-status"
                      data-granted="granted"
                      data-denied="denied"
                      data-unset="unset"></span>
                <button class="js-analytics-settings-allow"
                        data-analytics-action="allow">allow</button>
                <button class="js-analytics-settings-reject"
                        data-analytics-action="reject">reject</button>
                <button class="js-analytics-withdraw"
                        data-analytics-action="reject">withdraw</button>
                <button data-analytics-action="close">close</button>
            </dialog>
        </div>
    </body></html>`, {url});

    setBrowserGlobals(dom);
    if (BroadcastChannelClass) {
        Object.defineProperty(dom.window, 'BroadcastChannel', {
            configurable: true,
            value: BroadcastChannelClass,
        });
    }

    const settings = dom.window.document.querySelector('.js-analytics-settings');
    settings.showModal = () => settings.setAttribute('open', '');
    settings.close = () => {
        settings.removeAttribute('open');
        settings.dispatchEvent(new dom.window.Event('close'));
    };

    if (consent) dom.window.document.cookie = serializeConsentCookie(consent, true);
    initialCookies.forEach((cookie) => {
        dom.window.document.cookie = `${cookie}; Path=/; Secure; SameSite=Lax`;
    });

    const root = dom.window.document.querySelector('[data-analytics-configuration]');
    root.dataset.analyticsConfiguration = JSON.stringify(config);
    initializeAnalytics(root);

    return {dom, root};
}

function gtagCalls(dom) {
    return (dom.window.dataLayer || []).map((call) => Array.from(call));
}

class FakeBroadcastChannel {
    static instances = [];

    constructor(name) {
        this.name = name;
        this.messages = [];
        this.listeners = [];
        FakeBroadcastChannel.instances.push(this);
    }

    addEventListener(type, listener) {
        if (type === 'message') this.listeners.push(listener);
    }

    postMessage(message) {
        this.messages.push(message);
    }

    emit(message) {
        this.listeners.forEach((listener) => listener({data: message}));
    }
}

describe('analytics consent values', () => {
    test.each([
        ['bw_analytics_consent=v1%3Agranted', 'v1:granted'],
        ['other=x; bw_analytics_consent=v1%3Adenied', 'v1:denied'],
        ['bw_analytics_consent=v2%3Agranted', null],
        ['bw_analytics_consent=%E0%A4%A', null],
        ['', null],
    ])('reads only supported consent from %p', (cookieHeader, expected) => {
        expect(readConsent(cookieHeader)).toBe(expected);
    });

    test('serializes a 180-day host-only consent cookie', () => {
        const serialized = serializeConsentCookie('v1:granted', true, new Date('2026-01-01T00:00:00Z'));
        expect(serialized).toContain('bw_analytics_consent=v1%3Agranted');
        expect(serialized).toContain('Path=/');
        expect(serialized).toContain('Max-Age=15552000');
        expect(serialized).toContain('Expires=Tue, 30 Jun 2026 00:00:00 GMT');
        expect(serialized).toContain('SameSite=Lax');
        expect(serialized).toContain('Secure');
        expect(serialized).not.toContain('Domain=');
    });

    test.each([
        ['bw_ga', true, false],
        ['bw_ga_G_ABC', true, false],
        ['_ga', true, false],
        ['_ga_ABC', true, false],
        ['_pk_id.1.abc', true, true],
        ['_pk_ses.1.abc', true, true],
        ['cookieconsent_status', true, true],
        ['session', false, false],
    ])('classifies cleanup cookie %p', (name, analytics, legacy) => {
        expect(isAnalyticsCookieName(name)).toBe(analytics);
        expect(isLegacyAnalyticsCookieName(name)).toBe(legacy);
    });

    test('limits deletion domains to the current host and canonical production parent', () => {
        expect(cookieDeletionDomains('www.bewelcome.org'))
            .toEqual([null, 'www.bewelcome.org', '.bewelcome.org']);
        expect(cookieDeletionDomains('beta.bewelcome.org'))
            .toEqual([null, 'beta.bewelcome.org']);
        expect(cookieDeletionDomains('')).toEqual([null]);
    });

    test('serializes an expired deletion cookie', () => {
        expect(serializeDeletionCookie('_ga', null, true)).toBe(
            '_ga=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax; Secure',
        );
    });

    test.each([
        [configuration(), 'v1:granted', true],
        [configuration(), null, false],
        [configuration(), 'v1:denied', false],
        [configuration({measurementId: null}), 'v1:granted', false],
        [configuration({page: null}), 'v1:granted', false],
    ])('applies the loading gates %#', (config, consent, expected) => {
        expect(shouldLoadAnalytics(config, consent)).toBe(expected);
    });
});

describe('analytics consent runtime', () => {
    test('opens native settings modally and restores focus when closed', () => {
        const {dom, root} = createBrowser(configuration(), 'v1:denied');
        const opener = root.querySelector('.js-analytics-settings-open');
        const settings = root.querySelector('.js-analytics-settings');

        opener.focus();
        opener.click();
        expect(settings.open).toBe(true);
        expect(dom.window.document.body.classList.contains('bw-analytics-settings-open')).toBe(true);
        expect(settings.contains(dom.window.document.activeElement)).toBe(true);

        root.querySelector('[data-analytics-action="close"]').click();
        expect(settings.open).toBe(false);
        expect(dom.window.document.body.classList.contains('bw-analytics-settings-open')).toBe(false);
        expect(dom.window.document.activeElement).toBe(opener);
    });

    test('does not contact Google before consent and persists rejection', () => {
        const {dom, root} = createBrowser(configuration());

        expect(root.querySelector('.js-analytics-banner').hidden).toBe(false);
        expect(dom.window.document.querySelector('script[data-bw-analytics]')).toBeNull();

        root.querySelector('[data-analytics-action="reject"]').click();

        expect(readConsent(dom.window.document.cookie)).toBe('v1:denied');
        expect(root.querySelector('.js-analytics-banner').hidden).toBe(true);
        expect(dom.window.document.querySelector('script[data-bw-analytics]')).toBeNull();
        expect(gtagCalls(dom)).toEqual([]);
    });

    test('acceptance loads one tag and sends one synthetic page view', () => {
        const {dom, root} = createBrowser(configuration());
        const allow = root.querySelector('[data-analytics-action="allow"]');

        allow.click();
        allow.click();

        const scripts = dom.window.document.querySelectorAll('script[data-bw-analytics]');
        expect(scripts).toHaveLength(1);
        expect(scripts[0].src).toBe('https://www.googletagmanager.com/gtag/js?id=G-TEST123');
        expect(scripts[0].referrerPolicy).toBe('no-referrer');
        expect(readConsent(dom.window.document.cookie)).toBe('v1:granted');

        scripts[0].dispatchEvent(new dom.window.Event('load'));
        const calls = gtagCalls(dom);
        expect(calls[0]).toEqual(['consent', 'default', {
            analytics_storage: 'denied',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
        }]);
        expect(calls[1]).toEqual(['consent', 'update', {
            analytics_storage: 'granted',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
        }]);
        expect(calls.find((call) => call[0] === 'config')).toEqual([
            'config',
            'G-TEST123',
            {
                send_page_view: false,
                page_location: 'https://www.bewelcome.org/_analytics/search/member-results',
                page_title: 'Member search results',
                page_referrer: '',
                ignore_referrer: true,
                content_group: 'search',
                language: 'en',
                login_state: 'visitor',
                allow_google_signals: false,
                allow_ad_personalization_signals: false,
                cookie_prefix: 'bw',
                cookie_domain: 'none',
                cookie_path: '/',
                cookie_expires: 15552000,
                cookie_update: false,
                cookie_flags: 'SameSite=Lax;Secure',
            },
        ]);
        expect(calls.filter((call) => call[0] === 'event')).toEqual([['event', 'page_view']]);
        expect(JSON.stringify(calls)).not.toContain('/search/locations');
        expect(JSON.stringify(calls)).not.toContain('location=private');
    });

    test('failed tag load is removed without sending measurement', () => {
        const {dom} = createBrowser(configuration(), 'v1:granted');
        const script = dom.window.document.querySelector('script[data-bw-analytics]');

        script.dispatchEvent(new dom.window.Event('error'));

        expect(dom.window.document.querySelector('script[data-bw-analytics]')).toBeNull();
        expect(gtagCalls(dom).some((call) => ['config', 'event'].includes(call[0]))).toBe(false);
    });

    test('withdrawal denies consent and cleans only analytics cookies', () => {
        const {dom, root} = createBrowser(configuration(), 'v1:granted');
        dom.window.document.querySelector('script[data-bw-analytics]')
            .dispatchEvent(new dom.window.Event('load'));
        for (const name of ['bw_ga', '_ga', '_pk_id.1.x', '_pk_ses.1.x', 'cookieconsent_status']) {
            dom.window.document.cookie = `${name}=stale; Path=/; Secure; SameSite=Lax`;
        }
        dom.window.document.cookie = 'session=keep; Path=/; Secure; SameSite=Lax';

        root.querySelector('.js-analytics-settings-open').click();
        root.querySelector('.js-analytics-withdraw').click();

        expect(dom.window['ga-disable-G-TEST123']).toBe(true);
        expect(readConsent(dom.window.document.cookie)).toBe('v1:denied');
        expect(dom.window.document.cookie).toContain('session=keep');
        for (const name of ['bw_ga', '_ga', '_pk_id', '_pk_ses', 'cookieconsent_status']) {
            expect(dom.window.document.cookie).not.toContain(name);
        }
        expect(gtagCalls(dom).at(-1)).toEqual(['consent', 'update', {
            analytics_storage: 'denied',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
        }]);
    });

    test('withdrawal is applied immediately to another active tab', () => {
        FakeBroadcastChannel.instances = [];
        const first = createBrowser(configuration(), 'v1:granted', FakeBroadcastChannel);
        first.dom.window.document.querySelector('script[data-bw-analytics]')
            .dispatchEvent(new first.dom.window.Event('load'));
        const second = createBrowser(configuration(), 'v1:granted', FakeBroadcastChannel);
        second.dom.window.document.querySelector('script[data-bw-analytics]')
            .dispatchEvent(new second.dom.window.Event('load'));

        setBrowserGlobals(first.dom);
        first.root.querySelector('.js-analytics-withdraw').click();
        expect(FakeBroadcastChannel.instances[0].messages).toEqual(['v1:denied']);

        second.dom.window.document.cookie = serializeConsentCookie('v1:denied', true);
        setBrowserGlobals(second.dom);
        FakeBroadcastChannel.instances[1].emit('v1:denied');

        expect(second.dom.window['ga-disable-G-TEST123']).toBe(true);
        expect(gtagCalls(second.dom).at(-1).slice(0, 2)).toEqual(['consent', 'update']);
    });

    test('pageshow catches consent removed while a tab was suspended', () => {
        const {dom} = createBrowser(configuration(), 'v1:granted');
        dom.window.document.querySelector('script[data-bw-analytics]')
            .dispatchEvent(new dom.window.Event('load'));
        dom.window.document.cookie = 'bw_analytics_consent=; Path=/; Max-Age=0; Secure; SameSite=Lax';

        dom.window.dispatchEvent(new dom.window.PageTransitionEvent('pageshow'));

        expect(dom.window['ga-disable-G-TEST123']).toBe(true);
        expect(gtagCalls(dom).at(-1).slice(0, 2)).toEqual(['consent', 'update']);
    });

    test('legacy cookies are always cleaned and blank-ID rollback cleans current cookies', () => {
        const active = createBrowser(
            configuration(),
            'v1:granted',
            null,
            ['_ga=current', 'bw_ga=current', '_pk_id.1.x=stale', 'cookieconsent_status=allow'],
        );
        expect(active.dom.window.document.cookie).toContain('_ga=current');
        expect(active.dom.window.document.cookie).toContain('bw_ga=current');
        expect(active.dom.window.document.cookie).not.toContain('_pk_id');
        expect(active.dom.window.document.cookie).not.toContain('cookieconsent_status');

        const disabled = createBrowser(
            configuration({measurementId: null, page: null}),
            null,
            null,
            ['_ga=stale', '_pk_id.1.x=stale'],
            'https://beta.bewelcome.org/',
        );
        expect(disabled.dom.window.document.cookie).toBe('');
        expect(disabled.dom.window.document.querySelector('script[data-bw-analytics]')).toBeNull();
    });
});
