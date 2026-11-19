<?php

declare(strict_types=1);

namespace MaikSchneider\SolrShieldTest;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Stands in for the page: `/form` renders search markup, `/large-form` a
 * search form after large inline content, `/page` is rendered by TYPO3,
 * every other path echoes the parameters that made it past the Solr Shield
 * middleware.
 */
final class EchoMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/page') {
            return $handler->handle($request);
        }
        if ($request->getUri()->getPath() === '/form') {
            return new HtmlResponse((string)file_get_contents(__DIR__ . '/../Resources/Private/form.html'));
        }
        if ($request->getUri()->getPath() === '/large-form') {
            // Each part is larger than PHP's default `pcre.backtrack_limit`.
            $large = str_repeat('x', 1_100_000);
            return new HtmlResponse(
                '<!--' . $large . '--><script>' . $large . '</script><style>' . $large . '</style>'
                . '<div data-note=' . $large . '></div><textarea>' . $large . '</textarea>'
                . '<form id="large" action="/search"><input type="text" name="tx_solr[q]"></form>'
            );
        }

        return new JsonResponse([
            'query' => $request->getQueryParams(),
            'body' => $request->getParsedBody(),
        ]);
    }
}
