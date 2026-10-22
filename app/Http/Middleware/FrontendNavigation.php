<?php

namespace App\Http\Middleware;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An opt-in representation of the existing Blade response, never a second API. */
class FrontendNavigation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->header('X-Frontend-Navigation') !== '1'
            || ! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            || $response->isRedirection()) {
            return $response;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $payload = ['version' => 1, 'url' => $request->fullUrl(), 'boundary' => true];
        if ($body instanceof DOMElement && $body->hasAttribute('data-app-layout')) {
            $assets = [];
            foreach ($document->getElementsByTagName('link') as $link) {
                if ($link->getAttribute('rel') === 'stylesheet') {
                    $assets[] = $link->getAttribute('href');
                }
            }
            foreach ($document->getElementsByTagName('script') as $script) {
                if ($script->getAttribute('type') === 'module') {
                    $assets[] = $script->getAttribute('src');
                }
            }
            $payload = array_merge($payload, [
                'boundary' => false,
                'title' => $document->getElementsByTagName('title')->item(0)?->textContent ?? config('app.name'),
                'csrf' => csrf_token(),
                'assets' => $assets,
                'body' => $this->node($body),
            ]);
        }

        // Retain cookies and all headers supplied by the existing middleware.
        $response->setContent(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $response->headers->set('Content-Type', 'application/vnd.webinar.navigation+json');
        $response->setVary('X-Frontend-Navigation', false);

        return $response;
    }

    private function node(DOMNode $node): array|string|null
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }
        if (! $node instanceof DOMElement || in_array(strtolower($node->tagName), ['script', 'style', 'iframe', 'object', 'embed', 'base', 'link', 'meta'], true)) {
            return null;
        }
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            if (! str_starts_with(strtolower($attribute->name), 'on') && ! in_array(strtolower($attribute->name), ['nonce', 'srcdoc'], true)) {
                $attributes[$attribute->name] = $attribute->value;
            }
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            $value = $this->node($child);
            if ($value !== null) {
                $children[] = $value;
            }
        }

        return ['tag' => strtolower($node->tagName), 'attrs' => (object) $attributes, 'children' => $children];
    }
}
