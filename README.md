# Auto Readable Content

A [FreshRSS](https://freshrss.org/) extension that automatically fetches and extracts readable article content for feeds that provide empty or minimal content (e.g. Hacker News, Lobsters).

## How it works

1. During feed refresh, each new article's text content length is checked against a configurable threshold.
2. If below the threshold, the extension fetches the linked page and extracts the main readable content using multiple strategies (article tags, common content selectors, paragraph density analysis).
3. The extracted content replaces the empty feed content and is saved to the database.
4. For existing articles already in the database with empty content, extraction happens on first display and is then persisted.
5. The original feed content (if any) is preserved in a collapsible "Original feed content" section above the extracted content.

No per-feed CSS selectors are needed — the extension works automatically with any feed.

## Installation

1. Download or clone this repository into your FreshRSS `extensions/` directory:
   ```
   extensions/xExtension-AutoReadableContent/
   ```
2. In FreshRSS, go to **Settings → Extensions** and enable **Auto Readable Content**.

## Configuration

After enabling, click **Configure** on the extension to adjust:

- **Content threshold (characters)** — Articles with fewer characters than this trigger auto-fetch. Default: `200`. Set to `0` to fetch for all articles.
- **Fetch timeout (seconds)** — Maximum time to wait when fetching an article page. Default: `15`.

## Notes

- Feed refresh will be slower since each qualifying article requires an additional HTTP fetch.
- The extension uses FreshRSS's built-in HTML sanitizer when available.
- Relative URLs in extracted content are resolved to absolute URLs.
