<?php

namespace App\Support\Site;

use App\Models\AppConfig;
use App\Models\LegalPage;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * The website (zon.sa): the address shared links open (config zon.deep_link_base), who runs the platform, and
 * where the apps are. Nothing here is shown before it is real: a footer line appears once its setting is filled,
 * a store badge once the store's link is set in «إعدادات التطبيق», a policy once it is published.
 */
final class Site
{
    public static function base(): string
    {
        return rtrim((string) config('zon.deep_link_base'), '/');
    }

    /** The website's host: its routes answer there only (routes/site.php). */
    public static function host(): string
    {
        return (string) parse_url(self::base(), PHP_URL_HOST);
    }

    public static function url(string $path = ''): string
    {
        return self::base().'/'.ltrim($path, '/');
    }

    /** A file of public/site with a version from its content, so a new release is never served stale. */
    public static function asset(string $file): string
    {
        $path = public_path("site/{$file}");
        $version = is_file($path) ? substr((string) md5_file($path), 0, 10) : '0';

        return "/site/{$file}?v={$version}";
    }

    /**
     * The footer's lines about the platform's owner, only those already filled in «إعدادات المنصة».
     *
     * @return array<string, string> label => value
     */
    public static function identity(): array
    {
        $vat = Settings::bool(Setting::VatRegistered) ? Settings::string(Setting::VatNumber) : '';

        return array_filter([
            'المنشأة' => Settings::string(Setting::SiteLegalName),
            'السجل التجاري' => Settings::string(Setting::SiteCrNumber),
            'رخصة فال للمنصة' => Settings::string(Setting::SiteFalLicence),
            'الرقم الضريبي' => $vat,
            'العنوان' => Settings::string(Setting::SiteAddress),
        ], fn (string $value) => trim($value) !== '');
    }

    /** @return array{email: ?string, phone: ?string} */
    public static function contact(): array
    {
        return [
            'email' => trim(Settings::string(Setting::SiteEmail)) ?: null,
            'phone' => trim(Settings::string(Setting::SitePhone)) ?: null,
        ];
    }

    /**
     * The apps' store pages, once their real links are set (an App Store link carries the app's number; a Google
     * Play link names our package). Until then the website says the apps are coming, without a dead badge.
     *
     * @return array{ios: ?string, android: ?string}
     */
    public static function stores(): array
    {
        $ios = AppConfig::for('ios')?->store_url;
        $android = AppConfig::for('android')?->store_url;

        return [
            'ios' => is_string($ios) && self::isAppStoreLink($ios) ? $ios : null,
            'android' => is_string($android) && self::isPlayLink($android) ? $android : null,
        ];
    }

    public static function isAppStoreLink(string $url): bool
    {
        return preg_match('#^https://apps\.apple\.com/([a-z]{2}/)?app/([^/?]+/)?id\d+#', $url) === 1;
    }

    public static function isPlayLink(string $url): bool
    {
        return str_starts_with($url, 'https://play.google.com/store/apps/details?id='.config('services.google_play.package'));
    }

    /** The App Store number of the app, for Safari's banner that opens the app on the same page (apple-itunes-app). */
    public static function appStoreId(): ?string
    {
        $ios = self::stores()['ios'];

        return $ios !== null && preg_match('#/id(\d+)#', $ios, $m) === 1 ? $m[1] : null;
    }

    /**
     * The published policies (title by slug), for the footer and /legal.
     *
     * @return array<string, string>
     */
    public static function policies(): array
    {
        /** @var array<string, string> */
        return Cache::remember('site.policies', 300, fn () => LegalPage::query()->publishedInOrder()->pluck('title', 'slug')->all());
    }
}
