<?php

use Proxy\Event\ProxyEvent;
use Proxy\Plugin\AbstractPlugin;
use Proxy\Config;

class YouTubePlugin extends AbstractPlugin
{
    private $youtube_patterns = array(
        '#^https?://(www\.)?youtube\.com#i',
        '#^https?://(www\.)?youtu\.be#i',
        '#^https?://m\.youtube\.com#i'
    );

    private function isYouTube($url)
    {
        foreach ($this->youtube_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        return false;
    }

    public function onBeforeRequest(ProxyEvent $event)
    {
        $request = $event['request'];
        $url = $request->getUri();

        if (!$this->isYouTube($url)) {
            return;
        }

        // Add YouTube-specific cookies for modern interface
        $headers = $request->headers;
        $headers->set('Cookie', 'PREF=f6=8&hl=en; YSC=0; VISITOR_INFO1_LIVE=0');
    }

    public function onHeadersReceived(ProxyEvent $event)
    {
        $request = $event['request'];
        $url = $request->getUri();

        if (!$this->isYouTube($url)) {
            return;
        }

        $response = $event['response'];
        $headers = $response->headers;

        // Remove X-Frame-Options to allow embedding
        $headers->remove('X-Frame-Options');

        // Update content-type charset
        $contentType = $headers->get('content-type');
        if ($contentType && strpos($contentType, 'text/html') !== false) {
            $headers->set('content-type', 'text/html; charset=utf-8');
        }
    }

    public function onCompleted(ProxyEvent $event)
    {
        $request = $event['request'];
        $url = $request->getUri();

        if (!$this->isYouTube($url)) {
            return;
        }

        $response = $event['response'];
        $contentType = $response->headers->get('content-type');

        if (!is_html($contentType)) {
            return;
        }

        $output = $response->getContent();

        // Inject modern browser polyfills and scripts
        $modernScripts = <<<'HTML'
<script>
// Polyfill for modern JavaScript features
(function() {
    // Ensure modern browser detection passes
    if (!window.navigator.userAgentData) {
        window.navigator.userAgentData = {
            brands: [
                {brand: "Google Chrome", version: "131"},
                {brand: "Chromium", version: "131"},
                {brand: "Not-A.Brand", version: "24"}
            ],
            mobile: false,
            platform: "Windows",
            getHighEntropyValues: function(hints) {
                return Promise.resolve({
                    architecture: "x86",
                    model: "",
                    platform: "Windows",
                    platformVersion: "10.0.0",
                    uaFullVersion: "131.0.0.0"
                });
            }
        };
    }

    // Polyfill for requestIdleCallback
    if (!window.requestIdleCallback) {
        window.requestIdleCallback = function(cb) {
            return setTimeout(function() {
                var start = Date.now();
                cb({
                    didTimeout: false,
                    timeRemaining: function() {
                        return Math.max(0, 50 - (Date.now() - start));
                    }
                });
            }, 1);
        };
        window.cancelIdleCallback = clearTimeout;
    }

    // Polyfill for IntersectionObserver if missing
    if (!window.IntersectionObserver) {
        window.IntersectionObserver = function(callback) {
            this.observe = function() {};
            this.unobserve = function() {};
            this.disconnect = function() {};
        };
    }
})();
</script>
HTML;

        // Insert scripts after <head> tag
        $output = preg_replace(
            '@<head([^>]*)>@i',
            '$0' . PHP_EOL . $modernScripts,
            $output,
            1
        );

        // Force disable the outdated browser redirect
        $output = preg_replace(
            '/window\.location\.replace\([^)]*oldbrowser[^)]*\)/i',
            '// disabled old browser redirect',
            $output
        );

        // Block the "update your browser" banner from showing
        $output = preg_replace(
            '/class="[^"]* outdated-browser[^"]*"/i',
            'class="hidden-outdated-browser" style="display:none !important"',
            $output
        );

        // Add CSS to hide outdated browser messages
        $hideOldBrowser = <<<'HTML'
<style>
.yt-alert-outdated-browser,
.outdated-browser,
[class*="outdated-browser"],
#outdated-browser,
.old-browser-warning,
.yt-old-browser,
ytm-alert-with-actions-renderer[alert-type*="outdated"],
ytm-info-panel-container-renderer[header-text*="browser"] {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    overflow: hidden !important;
}
</style>
HTML;

        $output = preg_replace(
            '@</head>@i',
            $hideOldBrowser . PHP_EOL . '</head>',
            $output,
            1
        );

        // Enable Polymer/Web Components support
        $output = str_replace(
            'window.ytcfg.set({"EXPERIMENT_FLAGS":',
            'window.ytcfg=window.ytcfg||{};window.ytcfg.data_=window.ytcfg.data_||{};window.ytcfg.set({"WEB_PLAYER_CONTEXT_CONFIGS":{"WEB_PLAYER_CONTEXT_CONFIG_ID_KEVLAR_WATCH":{"transparentBackground":true}},"EXPERIMENT_FLAGS":',
            $output
        );

        $response->setContent($output);
    }
}
