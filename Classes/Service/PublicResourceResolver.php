<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShield\Service;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\Publishing\UriGenerationOptions;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Resolves the public URL path of an `EXT:` resource on TYPO3 13 and 14.
 * TYPO3 14 replaced PathUtility::getPublicResourceWebPath() with the system
 * resource API, which TYPO3 13 does not have.
 */
final class PublicResourceResolver
{
    public function resolve(string $resource, bool $cacheBusting = false): string
    {
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            $publicResource = GeneralUtility::makeInstance(SystemResourceFactory::class)->createPublicResource($resource);

            return (string)GeneralUtility::makeInstance(SystemResourcePublisherInterface::class)
                ->generateUri($publicResource, null, new UriGenerationOptions(cacheBusting: $cacheBusting));
        }

        $path = PathUtility::getPublicResourceWebPath($resource);
        if (!$cacheBusting) {
            return $path;
        }

        return $path . '?' . (string)filemtime(GeneralUtility::getFileAbsFileName($resource));
    }
}
