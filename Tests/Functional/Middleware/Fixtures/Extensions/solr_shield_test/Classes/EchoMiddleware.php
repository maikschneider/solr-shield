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
 * Stands in for the page: `/form` renders search markup, every other path
 * echoes the parameters that made it past the Solr Shield middleware.
 */
final class EchoMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/form') {
            return new HtmlResponse((string)file_get_contents(__DIR__ . '/../Resources/Private/form.html'));
        }

        return new JsonResponse([
            'query' => $request->getQueryParams(),
            'body' => $request->getParsedBody(),
        ]);
    }
}
