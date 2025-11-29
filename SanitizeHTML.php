<?php

function mySanitizeHTML(FreshRSS_Feed $feed, string $url, string $html): string
{
    if ($html === '') {
        return '';
    }

    $doc = new DOMDocument();
    /* @psalm-suppress ArgumentTypeCoercion */
    $doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($doc);

    $base = $xpath->evaluate('normalize-space(//base/@href)');
    if ($base == false || !is_string($base)) {
        $base = $url;
    } elseif (str_starts_with($base, '//')) {
        //Protocol-relative URLs "//www.example.net"
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $base = ($scheme !== false && $scheme !== null ? $scheme : 'https') . ':' . $base;
    }

    $html = '';
    $path_entries_filter = trim($feed->attributeString('path_entries_filter') ?? '', ', ');
    // get all nodes from the $doc
    $nodes = $xpath->query('//*');
    if ($nodes != false) {
        $filter_xpath = $path_entries_filter === '' ? '' : (new Gt\CssXPath\Translator($path_entries_filter, 'descendant-or-self::'))->asXPath();
        foreach ($nodes as $node) {
            if ($filter_xpath !== '' && ($filterednodes = $xpath->query($filter_xpath, $node)) !== false) {
                // Remove unwanted elements once before sanitizing, for CSS selectors to also match original content
                foreach ($filterednodes as $filterednode) {
                    if ($filterednode === $node) {
                        continue 2;
                    }
                    if (!($filterednode instanceof DOMElement) || $filterednode->parentNode === null) {
                        continue;
                    }
                    $filterednode->parentNode->removeChild($filterednode);
                }
            }
            $html .= $doc->saveHTML($node) . "\n";
        }
    }

    unset($xpath, $doc);
    $html = FreshRSS_SimplePieCustom::sanitizeHTML($html, $base);

    if ($path_entries_filter !== '') {
        // Remove unwanted elements again after sanitizing, for CSS selectors to also match sanitized content
        $modified = false;
        $doc = new DOMDocument();
        $utf8BOM = "\xEF\xBB\xBF";
        $doc->loadHTML($utf8BOM . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($doc);
        $filterednodes = $xpath->query((new Gt\CssXPath\Translator($path_entries_filter, '//'))->asXPath()) ?: [];
        foreach ($filterednodes as $filterednode) {
            if (!($filterednode instanceof DOMElement) || $filterednode->parentNode === null) {
                continue;
            }
            $filterednode->parentNode->removeChild($filterednode);
            $modified = true;
        }
        if ($modified) {
            $savedHtml = $doc->saveHTML($doc->getElementsByTagName('body')->item(0) ?? $doc->firstElementChild);
            $html = ($savedHtml !== false && $savedHtml !== '') ? $savedHtml : $html;
        }
    }

    return trim($html);
}
