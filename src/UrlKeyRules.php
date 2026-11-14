<?php

declare(strict_types=1);

namespace IndianOpinion\NewsApiPull;

/**
 * The tunable lists behind the canonical key. Plain data, no WordPress.
 */
final class UrlKeyRules
{
    public const MIRROR_LABELS = ['www', 'www1', 'www2', 'www3', 'm', 'mobile', 'amp', 'wap', 'touch', 'lite'];

    public const TRACKING_PARAMS = [
        'fbclid', 'gclid', 'msclkid', 'igshid', 'mc_cid', 'mc_eid', '_gl', 'ref', 'ref_src', 'source',
        'src', 'from', 'cmpid', 'intcmp', 'ocid', 'smid', 'share', 'rss', 'feed', 'amp',
    ];

    public const ID_PARAMS = [
        'p', 'id', 'story', 'storyid', 'article', 'articleid', 'newsid', 'nid', 'sid', 'aid', 'pid',
        'contentid', 'postid',
    ];

    public const SCRIPT_EXTENSIONS = ['php', 'asp', 'aspx', 'jsp', 'cfm', 'cgi', 'do'];

    /** @var list<string> */
    public readonly array $mirrorLabels;

    /** @var list<string> */
    public readonly array $trackingParams;

    /** @var list<string> */
    public readonly array $idParams;

    /** @var list<string> */
    public readonly array $scriptExtensions;

    /** @var \Closure(string): bool */
    private readonly \Closure $lowercasePath;

    /**
     * @param list<string>|null $mirrorLabels
     * @param list<string>|null $trackingParams
     * @param list<string>|null $idParams
     * @param list<string>|null $scriptExtensions
     * @param (callable(string): bool)|null $lowercasePath Receives the host, returns whether to lower-case its paths.
     */
    public function __construct(
        ?array $mirrorLabels = null,
        ?array $trackingParams = null,
        ?array $idParams = null,
        ?array $scriptExtensions = null,
        ?callable $lowercasePath = null
    ) {
        $this->mirrorLabels = self::clean($mirrorLabels ?? self::MIRROR_LABELS);
        $this->trackingParams = self::clean($trackingParams ?? self::TRACKING_PARAMS);
        $this->idParams = self::clean($idParams ?? self::ID_PARAMS);
        $this->scriptExtensions = self::clean($scriptExtensions ?? self::SCRIPT_EXTENSIONS);
        $this->lowercasePath = \Closure::fromCallable($lowercasePath ?? static fn (string $host): bool => true);
    }

    public function lowercasePath(string $host): bool
    {
        return (bool) ($this->lowercasePath)($host);
    }

    /**
     * @param array<mixed> $list
     * @return list<string>
     */
    private static function clean(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = UrlKey::asciiLower(trim($item));
            }
        }
        return array_values(array_unique($out));
    }
}
