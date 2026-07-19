<?php

namespace App\Service;

use App\Doctrine\TranslationAllowedType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

class AnalyticsPageClassifier
{
    public const array CONSENT_TRANSLATION_CODES = [
        'analytics.consent.title',
        'analytics.consent.message',
        'analytics.consent.allow',
        'analytics.consent.reject',
        'analytics.consent.privacy',
        'analytics.settings.open',
        'analytics.settings.description',
        'analytics.settings.status.label',
        'analytics.settings.status.granted',
        'analytics.settings.status.denied',
        'analytics.settings.status.unset',
        'analytics.settings.withdraw',
        'analytics.settings.close',
    ];

    /** @var array<string, array{path: string, title: string, group: string}> */
    private const array ROUTE_MAP = [
        'homepage' => ['path' => '/_analytics/home', 'title' => 'Home', 'group' => 'home'],
        'landingpage' => ['path' => '/_analytics/dashboard', 'title' => 'Member dashboard', 'group' => 'dashboard'],

        'login' => ['path' => '/_analytics/auth/login', 'title' => 'Login', 'group' => 'auth'],
        'security_login' => ['path' => '/_analytics/auth/login', 'title' => 'Login', 'group' => 'auth'],
        'signup' => ['path' => '/_analytics/signup/start', 'title' => 'Signup', 'group' => 'signup'],
        'finish_setup' => ['path' => '/_analytics/signup/profile-setup', 'title' => 'Profile setup', 'group' => 'signup'],

        'search_map' => ['path' => '/_analytics/search/public-map', 'title' => 'Public map search', 'group' => 'search'],
        'search_members' => ['path' => '/_analytics/search/member-name', 'title' => 'Member name search', 'group' => 'search'],
        'search_locations' => ['path' => '/_analytics/search/member-location', 'title' => 'Member location search', 'group' => 'search'],
        'searchmembers' => ['path' => '/_analytics/search/members', 'title' => 'Member search', 'group' => 'search'],
        'searchmembers_map' => ['path' => '/_analytics/search/member-map', 'title' => 'Member map search', 'group' => 'search'],
        'searchmembers_map_advanced' => ['path' => '/_analytics/search/member-map', 'title' => 'Member map search', 'group' => 'search'],
        'searchmembers_text' => ['path' => '/_analytics/search/member-results', 'title' => 'Member search results', 'group' => 'search'],
        'searchmembers_text_advanced' => ['path' => '/_analytics/search/member-results', 'title' => 'Member search results', 'group' => 'search'],
        'searchmembers_advanced' => ['path' => '/_analytics/search/member-options', 'title' => 'Member search options', 'group' => 'search'],

        'members_profile' => ['path' => '/_analytics/profile/member', 'title' => 'Member profile', 'group' => 'profile'],
        'members_profile_old' => ['path' => '/_analytics/profile/member', 'title' => 'Member profile', 'group' => 'profile'],
        'members_profile_notification' => ['path' => '/_analytics/profile/member', 'title' => 'Member profile', 'group' => 'profile'],
        'members_profile_invite' => ['path' => '/_analytics/profile/member', 'title' => 'Member profile', 'group' => 'profile'],
        'profile_comments' => ['path' => '/_analytics/profile/comments', 'title' => 'Member comments', 'group' => 'profile'],
        'profile_forum_posts' => ['path' => '/_analytics/profile/forum-posts', 'title' => 'Member forum posts', 'group' => 'profile'],
        'profile_forum_posts_search' => ['path' => '/_analytics/profile/forum-posts', 'title' => 'Member forum posts', 'group' => 'profile'],
        'friends' => ['path' => '/_analytics/profile/friends', 'title' => 'Member friends', 'group' => 'profile'],
        'gallery_show_user' => ['path' => '/_analytics/profile/gallery', 'title' => 'Member gallery', 'group' => 'profile'],
        'gallery_show_user_images' => ['path' => '/_analytics/profile/gallery', 'title' => 'Member gallery', 'group' => 'profile'],
        'gallery_show_user_images_pages' => ['path' => '/_analytics/profile/gallery', 'title' => 'Member gallery', 'group' => 'profile'],
        'gallery_show_user_albums' => ['path' => '/_analytics/profile/gallery-albums', 'title' => 'Member gallery albums', 'group' => 'profile'],
        'gallery_show_user_albums_pages' => ['path' => '/_analytics/profile/gallery-albums', 'title' => 'Member gallery albums', 'group' => 'profile'],
        'gallery_show_image' => ['path' => '/_analytics/profile/gallery-image', 'title' => 'Gallery image', 'group' => 'profile'],

        'hosting_request' => ['path' => '/_analytics/hospitality/request', 'title' => 'Hosting request', 'group' => 'hospitality'],
        'hosting_invitation' => ['path' => '/_analytics/hospitality/invitation', 'title' => 'Hosting invitation', 'group' => 'hospitality'],
        'message_new' => ['path' => '/_analytics/conversations/new-message', 'title' => 'New message', 'group' => 'conversations'],
        'conversations' => ['path' => '/_analytics/conversations/list', 'title' => 'Conversations', 'group' => 'conversations'],
        'conversations_with' => ['path' => '/_analytics/conversations/member', 'title' => 'Conversations with member', 'group' => 'conversations'],
        'messages_with' => ['path' => '/_analytics/conversations/member', 'title' => 'Conversations with member', 'group' => 'conversations'],
        'conversation_view' => ['path' => '/_analytics/conversations/thread', 'title' => 'Conversation', 'group' => 'conversations'],
        'conversation_reply' => ['path' => '/_analytics/conversations/reply', 'title' => 'Reply to conversation', 'group' => 'conversations'],

        'group_start' => ['path' => '/_analytics/groups/group', 'title' => 'Group', 'group' => 'groups'],
        'groups_featured' => ['path' => '/_analytics/groups/featured', 'title' => 'Featured groups', 'group' => 'groups'],
        'groups_forums_overview' => ['path' => '/_analytics/groups/forums', 'title' => 'Group forums', 'group' => 'groups'],
        'groups_forums_overview_paged' => ['path' => '/_analytics/groups/forums', 'title' => 'Group forums', 'group' => 'groups'],
        'groups_mygroups' => ['path' => '/_analytics/groups/mine', 'title' => 'My groups', 'group' => 'groups'],
        'groups_search' => ['path' => '/_analytics/groups/search', 'title' => 'Group search', 'group' => 'groups'],
        'group_forum' => ['path' => '/_analytics/groups/forum', 'title' => 'Group forum', 'group' => 'groups'],
        'group_forum_pages' => ['path' => '/_analytics/groups/forum', 'title' => 'Group forum', 'group' => 'groups'],
        'group_forum_thread' => ['path' => '/_analytics/groups/thread', 'title' => 'Group discussion', 'group' => 'groups'],
        'group_forum_thread_pages' => ['path' => '/_analytics/groups/thread', 'title' => 'Group discussion', 'group' => 'groups'],
        'group_forum_thread_title' => ['path' => '/_analytics/groups/thread', 'title' => 'Group discussion', 'group' => 'groups'],
        'group_members' => ['path' => '/_analytics/groups/members', 'title' => 'Group members', 'group' => 'groups'],
        'group_members_paged' => ['path' => '/_analytics/groups/members', 'title' => 'Group members', 'group' => 'groups'],
        'group_wiki' => ['path' => '/_analytics/groups/wiki', 'title' => 'Group wiki', 'group' => 'groups'],
        'group_wiki_page' => ['path' => '/_analytics/groups/wiki', 'title' => 'Group wiki', 'group' => 'groups'],

        'forums' => ['path' => '/_analytics/forums', 'title' => 'Forums', 'group' => 'forums'],
        'forums_pages' => ['path' => '/_analytics/forums', 'title' => 'Forums', 'group' => 'forums'],
        'forums_new' => ['path' => '/_analytics/forums/new', 'title' => 'New forum posts', 'group' => 'forums'],
        'forums_search' => ['path' => '/_analytics/forums/search', 'title' => 'Forum search', 'group' => 'forums'],
        'forums_search_pages' => ['path' => '/_analytics/forums/search', 'title' => 'Forum search', 'group' => 'forums'],
        'bwforum' => ['path' => '/_analytics/forums/community', 'title' => 'Community forum', 'group' => 'forums'],
        'bwforum_pages' => ['path' => '/_analytics/forums/community', 'title' => 'Community forum', 'group' => 'forums'],
        'forum_permalink' => ['path' => '/_analytics/forums/thread', 'title' => 'Forum thread', 'group' => 'forums'],
        'forum_thread' => ['path' => '/_analytics/forums/thread', 'title' => 'Forum thread', 'group' => 'forums'],
        'forum_thread_pages' => ['path' => '/_analytics/forums/thread', 'title' => 'Forum thread', 'group' => 'forums'],
        'forum_tag' => ['path' => '/_analytics/forums/tag', 'title' => 'Forum tag', 'group' => 'forums'],
        'forum_tag_detail_1' => ['path' => '/_analytics/forums/tag', 'title' => 'Forum tag', 'group' => 'forums'],
        'forum_tag_detail_2' => ['path' => '/_analytics/forums/tag', 'title' => 'Forum tag', 'group' => 'forums'],
        'forum_rules' => ['path' => '/_analytics/forums/rules', 'title' => 'Forum rules', 'group' => 'forums'],
        'forum_reply' => ['path' => '/_analytics/forums/reply', 'title' => 'Reply to forum thread', 'group' => 'forums'],

        'trips' => ['path' => '/_analytics/trips', 'title' => 'Trips', 'group' => 'trips'],
        'trip_show' => ['path' => '/_analytics/trips/trip', 'title' => 'Trip', 'group' => 'trips'],
        'new_trip' => ['path' => '/_analytics/trips/new', 'title' => 'New trip', 'group' => 'trips'],
        'trip_edit' => ['path' => '/_analytics/trips/edit', 'title' => 'Edit trip', 'group' => 'trips'],
        'visitors' => ['path' => '/_analytics/trips/visitors', 'title' => 'Visitors', 'group' => 'trips'],

        'activities' => ['path' => '/_analytics/activities', 'title' => 'Activities', 'group' => 'activities'],
        'activities_my_activities' => ['path' => '/_analytics/activities/mine', 'title' => 'My activities', 'group' => 'activities'],
        'activities_my_activities_pages' => ['path' => '/_analytics/activities/mine', 'title' => 'My activities', 'group' => 'activities'],
        'activities_upcoming_activities' => ['path' => '/_analytics/activities/upcoming', 'title' => 'Upcoming activities', 'group' => 'activities'],
        'activities_upcoming_activities_pages' => ['path' => '/_analytics/activities/upcoming', 'title' => 'Upcoming activities', 'group' => 'activities'],
        'activities_upcoming_online_activities' => ['path' => '/_analytics/activities/online', 'title' => 'Online activities', 'group' => 'activities'],
        'activities_past_activities' => ['path' => '/_analytics/activities/past', 'title' => 'Past activities', 'group' => 'activities'],
        'activities_near_me' => ['path' => '/_analytics/activities/nearby', 'title' => 'Nearby activities', 'group' => 'activities'],
        'activities_create' => ['path' => '/_analytics/activities/new', 'title' => 'New activity', 'group' => 'activities'],
        'activities_edit' => ['path' => '/_analytics/activities/edit', 'title' => 'Edit activity', 'group' => 'activities'],
        'activities_show' => ['path' => '/_analytics/activities/activity', 'title' => 'Activity', 'group' => 'activities'],

        'donations' => ['path' => '/_analytics/donations', 'title' => 'Donations', 'group' => 'donations'],
        'donation_complete' => ['path' => '/_analytics/donations/complete', 'title' => 'Donation complete', 'group' => 'donations'],

        'community' => ['path' => '/_analytics/community', 'title' => 'Community', 'group' => 'community'],
        'communitynews' => ['path' => '/_analytics/community/news', 'title' => 'Community news', 'group' => 'community'],
        'communitynews_show' => ['path' => '/_analytics/community/news-item', 'title' => 'Community news item', 'group' => 'community'],
        'about' => ['path' => '/_analytics/community/about', 'title' => 'About BeWelcome', 'group' => 'community'],
        'about_theidea' => ['path' => '/_analytics/community/about', 'title' => 'About BeWelcome', 'group' => 'community'],
        'about_people' => ['path' => '/_analytics/community/people', 'title' => 'BeWelcome people', 'group' => 'community'],
        'getactive' => ['path' => '/_analytics/community/volunteer', 'title' => 'Volunteer', 'group' => 'community'],
        'volunteer' => ['path' => '/_analytics/community/volunteer', 'title' => 'Volunteer', 'group' => 'community'],
        'about_press' => ['path' => '/_analytics/community/press', 'title' => 'Press information', 'group' => 'community'],
        'media' => ['path' => '/_analytics/community/press', 'title' => 'Press information', 'group' => 'community'],
        'about_bod' => ['path' => '/_analytics/community/board', 'title' => 'Board', 'group' => 'community'],
        'about_bv' => ['path' => '/_analytics/community/association', 'title' => 'BeVolunteer', 'group' => 'community'],
        'about_credits' => ['path' => '/_analytics/community/credits', 'title' => 'Credits', 'group' => 'community'],
        'about_faq' => ['path' => '/_analytics/community/faq', 'title' => 'Frequently asked questions', 'group' => 'community'],
        'faqs_overview' => ['path' => '/_analytics/community/faq', 'title' => 'Frequently asked questions', 'group' => 'community'],
        'wiki_front_page' => ['path' => '/_analytics/community/wiki', 'title' => 'Wiki', 'group' => 'community'],
        'wiki_recent' => ['path' => '/_analytics/community/wiki-recent', 'title' => 'Recent wiki changes', 'group' => 'community'],
        'wiki_page' => ['path' => '/_analytics/community/wiki-page', 'title' => 'Wiki page', 'group' => 'community'],
        'newsletters' => ['path' => '/_analytics/community/newsletters', 'title' => 'Newsletters', 'group' => 'community'],
        'newsletter_single' => ['path' => '/_analytics/community/newsletter', 'title' => 'Newsletter', 'group' => 'community'],
    ];

    /** @var array<string, bool> */
    private array $consentTranslationReadiness = [];

    /** @param string[] $locales */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $locales,
    ) {
    }

    /**
     * @return array{path: string, title: string, contentGroup: string, locale: string, loginState: string}|null
     */
    public function classify(string $routeName, string $locale, string $loginState): ?array
    {
        if (
            !\in_array($locale, $this->locales, true)
            || !\in_array($loginState, ['visitor', 'member'], true)
        ) {
            return null;
        }

        if ('homepage' === $routeName && 'member' === $loginState) {
            $routeName = 'landingpage';
        }

        $route = self::ROUTE_MAP[$routeName] ?? null;
        if (null === $route) {
            return null;
        }

        return [
            'path' => $route['path'],
            'title' => $route['title'],
            'contentGroup' => $route['group'],
            'locale' => $locale,
            'loginState' => $loginState,
        ];
    }

    public function hasConsentTranslations(string $locale): bool
    {
        return $this->consentTranslationReadiness[$locale]
            ??= [] === $this->getMissingConsentTranslations($locale);
    }

    /**
     * @return string[]
     */
    public function getMissingConsentTranslations(string $locale): array
    {
        if (!\in_array($locale, $this->locales, true)) {
            return self::CONSENT_TRANSLATION_CODES;
        }

        try {
            $parameters = [
                'locale' => $locale,
                'domain' => 'messages+intl-icu',
                'codes' => self::CONSENT_TRANSLATION_CODES,
                'translationAllowed' => TranslationAllowedType::TRANSLATION_ALLOWED,
            ];
            $sql = <<<'SQL'
                SELECT translation.code
                FROM word translation
                INNER JOIN word source
                    ON source.code = translation.code
                   AND source.ShortCode = 'en'
                   AND source.domain = translation.domain
                WHERE translation.ShortCode = :locale
                  AND translation.domain = :domain
                  AND translation.code IN (:codes)
                  AND TRIM(translation.Sentence) <> ''
                  AND (translation.isarchived = 0 OR translation.isarchived IS NULL)
                  AND TRIM(source.Sentence) <> ''
                  AND (source.isarchived = 0 OR source.isarchived IS NULL)
                  AND source.donottranslate = :translationAllowed
                  AND translation.updated IS NOT NULL
                  AND (source.majorupdate IS NULL OR translation.updated >= source.majorupdate)
                SQL;

            $codes = $this->entityManager->getConnection()->executeQuery(
                $sql,
                $parameters,
                ['codes' => ArrayParameterType::STRING],
            )->fetchFirstColumn();
        } catch (Throwable) {
            return self::CONSENT_TRANSLATION_CODES;
        }

        return array_values(array_diff(
            self::CONSENT_TRANSLATION_CODES,
            $codes,
        ));
    }
}
