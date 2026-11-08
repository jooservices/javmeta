# Decision: downloads and stream capture (JOOXCR-223)

Status: **ADOPTED** by the owner in the JOOXCR-258 implementation instructions (2026-10-24). Capture stays off until explicitly enabled in Settings; media AI stays local.

## What may be downloaded or captured

- Only for the owner's personal use, on the owner's own machines at home.
- Downloads start only from a magnet that JavMeta itself stored for that movie (the sites it already crawls). No arbitrary link, torrent file or magnet can be added by hand or by the bot.
- Capture only of a plain HLS or DASH playlist that the page serves to every visitor, without a login that the owner does not own and without any protection.

## What is never done

- No DRM circumvention of any kind: a playlist with encryption other than plain AES-128 whose key the player fetches openly, or any Widevine, PlayReady, FairPlay or SAMPLE-AES stream, is refused with a clear message.
- No bypass of a paywall, a login, a captcha or an access check.
- No sharing: files are not exposed outside the home network; a link to a file is signed, expires (default 60 minutes) and works only from the home network.
- Nothing involving minors, under any circumstance: a movie, title, tag or file whose metadata suggests it is refused and reported.
- No automatic bulk download: every download is started by one explicit action of the owner (the button or one bot command).

## Retention

- The owner decides: a download stays until the owner removes it in the UI (with or without the files). JavMeta never deletes a file on its own.
- The library scanner only reads and records; it never moves or deletes.

## Terms of service and law

- Crawling already reads public pages at a polite rate; downloads and captures can breach a site's terms and the law of the owner's country. The owner accepts that responsibility by approving this document.
- These features are not part of anything that is sold or shared (JavMeta API, MCP): they live only in the owner's UI and bot.

## How it is enforced in the product

Kill switches in Settings (database), the magnet-only rule in the download service, the DRM refusal in the capture service, signed expiring links limited to home-network addresses, and an audit event for every download, capture and link.
