<?php
class AutoReadableContentExtension extends Minz_Extension
{
  public function init()
  {
    $this->registerHook('entry_before_insert', array($this, 'fetchContentIfEmpty'));
    $this->registerHook('entry_before_display', array($this, 'backfillOnDisplay'));
  }

  /**
   * For existing articles already in DB with empty content, fetch on first display and persist.
   */
  public function backfillOnDisplay($entry)
  {
    $threshold = (int)($this->getUserConfigurationValue('threshold', 200));
    $content = $entry->content(false);

    // Already has our extracted content — nothing to do
    if (strpos($content, 'auto-readable-content') !== false) {
      return $entry;
    }

    // Strip other extensions' injected HTML before measuring original content length
    $stripped = preg_replace('/<div class="oai-summary-wrap"[^>]*>.*?<\/div>\s*<\/div>/s', '', $content);
    if (mb_strlen(strip_tags(trim($stripped)), 'UTF-8') >= $threshold) {
      return $entry;
    }

    $link = htmlspecialchars_decode($entry->link(), ENT_QUOTES);
    if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link)) {
      return $entry;
    }

    $extracted = $this->extractContent($link);
    if ($extracted === null || trim($extracted) === '') {
      return $entry;
    }

    // Separate any prefix injected by other extensions (e.g. summary wrap) from original content
    $prefix = '';
    if (preg_match('/^((?:<div class="oai-summary-wrap"[^>]*>.*?<\/div>\s*<\/div>\s*)+)/s', $content, $m)) {
      $prefix = $m[1];
      $stripped = substr($content, strlen($prefix));
    } else {
      $stripped = $content;
    }

    $newOriginal = ($stripped !== '' ? '<details class="auto-readable-original"><summary>Original feed content</summary>' . $stripped . '</details>' : '')
      . '<div class="auto-readable-content">' . $extracted . '</div>';

    $entry->_content($prefix . $newOriginal);

    // Persist the extracted content (without other extensions' display-time prefix) to DB
    $dbContent = ($stripped !== '' ? '<details class="auto-readable-original"><summary>Original feed content</summary>' . $stripped . '</details>' : '')
      . '<div class="auto-readable-content">' . $extracted . '</div>';
    try {
      $dao = FreshRSS_Factory::createEntryDao();
      // Build a minimal entry array for updateEntry by cloning current entry with DB content
      $entryClone = clone $entry;
      $entryClone->_content($dbContent);
      $dao->updateEntry($entryClone->toArray());
    } catch (\Exception $e) {
      Minz_Log::warning('AutoReadableContent: DB update failed for ' . $entry->id() . ': ' . $e->getMessage());
    }

    return $entry;
  }

  public function fetchContentIfEmpty($entry)
  {
    $threshold = (int)($this->getUserConfigurationValue('threshold', 200));
    $content = strip_tags($entry->content(false));

    if (mb_strlen(trim($content), 'UTF-8') >= $threshold) {
      return $entry;
    }

    $link = htmlspecialchars_decode($entry->link(), ENT_QUOTES);
    if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
      return $entry;
    }

    // Skip non-HTTP URLs and common non-article links
    if (!preg_match('#^https?://#i', $link)) {
      return $entry;
    }

    $extracted = $this->extractContent($link);
    if ($extracted === null || trim($extracted) === '') {
      return $entry;
    }

    $originalContent = $entry->content(false);
    $entry->_content(
      ($originalContent !== '' ? '<details class="auto-readable-original"><summary>Original feed content</summary>' . $originalContent . '</details>' : '')
      . '<div class="auto-readable-content">' . $extracted . '</div>'
    );

    return $entry;
  }

  private function extractContent(string $url): ?string
  {
    $html = $this->fetchPage($url);
    if ($html === null) {
      return null;
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);

    // Remove noise elements
    foreach (['script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'noscript', 'iframe'] as $tag) {
      $nodes = $xpath->query('//' . $tag);
      if ($nodes) {
        foreach ($nodes as $node) {
          if ($node->parentNode) {
            $node->parentNode->removeChild($node);
          }
        }
      }
    }

    // Remove common non-content elements by class/id
    $noisePatterns = ['comment', 'sidebar', 'widget', 'menu', 'popup', 'modal', 'cookie', 'newsletter', 'social', 'share', 'related', 'advertisement', 'ad-', 'promo'];
    foreach ($noisePatterns as $pattern) {
      $nodes = $xpath->query("//*[contains(@class, '{$pattern}') or contains(@id, '{$pattern}')]");
      if ($nodes) {
        foreach ($nodes as $node) {
          if ($node->parentNode) {
            $node->parentNode->removeChild($node);
          }
        }
      }
    }

    // Strategy 1: Look for <article> tag
    $article = $xpath->query('//article');
    if ($article && $article->length > 0) {
      $best = $this->pickLargest($article, $doc);
      if ($best !== null && mb_strlen(strip_tags($best), 'UTF-8') > 100) {
        return $this->sanitize($best, $url);
      }
    }

    // Strategy 2: Look for common content selectors
    $selectors = [
      "//*[contains(@class, 'article-body') or contains(@class, 'article-content') or contains(@class, 'post-content') or contains(@class, 'entry-content') or contains(@class, 'story-body')]",
      "//*[@id='article-body' or @id='article-content' or @id='post-content' or @id='entry-content' or @id='content']",
      "//*[@role='main']",
      "//main",
    ];
    foreach ($selectors as $sel) {
      $nodes = $xpath->query($sel);
      if ($nodes && $nodes->length > 0) {
        $best = $this->pickLargest($nodes, $doc);
        if ($best !== null && mb_strlen(strip_tags($best), 'UTF-8') > 100) {
          return $this->sanitize($best, $url);
        }
      }
    }

    // Strategy 3: Find the div/section with the most <p> text
    $candidates = $xpath->query('//div | //section');
    if ($candidates && $candidates->length > 0) {
      $bestHtml = null;
      $bestScore = 0;

      foreach ($candidates as $node) {
        $paragraphs = $xpath->query('.//p', $node);
        if (!$paragraphs) continue;

        $textLen = 0;
        $pCount = 0;
        foreach ($paragraphs as $p) {
          $t = trim($p->textContent);
          if (mb_strlen($t, 'UTF-8') > 20) {
            $textLen += mb_strlen($t, 'UTF-8');
            $pCount++;
          }
        }

        // Score: favor many paragraphs with substantial text
        $score = $textLen * $pCount;
        if ($score > $bestScore) {
          $bestScore = $score;
          $bestHtml = $doc->saveHTML($node);
        }
      }

      if ($bestHtml !== null && mb_strlen(strip_tags($bestHtml), 'UTF-8') > 100) {
        return $this->sanitize($bestHtml, $url);
      }
    }

    return null;
  }

  private function pickLargest(DOMNodeList $nodes, DOMDocument $doc): ?string
  {
    $best = null;
    $bestLen = 0;
    foreach ($nodes as $node) {
      $html = $doc->saveHTML($node);
      $len = mb_strlen(strip_tags($html), 'UTF-8');
      if ($len > $bestLen) {
        $bestLen = $len;
        $best = $html;
      }
    }
    return $best;
  }

  private function fetchPage(string $url): ?string
  {
    $timeout = (int)($this->getUserConfigurationValue('timeout', 15));

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_USERAGENT => FRESHRSS_USERAGENT,
      CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
      CURLOPT_ACCEPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
      Minz_Log::warning('AutoReadableContent: fetch failed (' . $code . ') ' . $error . ' ' . $url);
      return null;
    }

    return is_string($body) ? $body : null;
  }

  private function sanitize(string $html, string $baseUrl): string
  {
    // Resolve relative URLs
    $html = preg_replace_callback(
      '/(src|href)=["\'](?!https?:\/\/|\/\/|data:|#)([^"\']+)["\']/i',
      function ($m) use ($baseUrl) {
        $resolved = $this->resolveUrl($m[2], $baseUrl);
        return $m[1] . '="' . $resolved . '"';
      },
      $html
    );

    // Use FreshRSS's built-in sanitizer if available
    if (class_exists('FreshRSS_SimplePieCustom') && method_exists('FreshRSS_SimplePieCustom', 'sanitizeHTML')) {
      $html = FreshRSS_SimplePieCustom::sanitizeHTML($html, $baseUrl);
    }

    return trim($html);
  }

  private function resolveUrl(string $relative, string $base): string
  {
    if (str_starts_with($relative, '/')) {
      $parsed = parse_url($base);
      return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $relative;
    }
    return rtrim(dirname($base), '/') . '/' . $relative;
  }

  public function handleConfigureAction()
  {
    if (Minz_Request::isPost()) {
      $this->setUserConfiguration([
        'threshold' => (int)Minz_Request::param('threshold', 200),
        'timeout' => (int)Minz_Request::param('timeout', 15),
      ]);
    }
  }
}
