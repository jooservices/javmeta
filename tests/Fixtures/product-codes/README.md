# Product-code regression captures

Captured on 2026-11-01 through the shared browser adapter
(`scripts/browser/main.py` / `flare`). Only parser-relevant HTML nodes are kept;
scripts, navigation, cookies and response headers are omitted. TokyoHot model
profiles and unrelated product metadata are omitted.

| Fixture | Public source |
| --- | --- |
| javbtc.html | https://javbtc.com/r18/softondemand-marina-shiraishi-atmania-stepmom |
| tokyohot-invasion.html | https://my.tokyo-hot.com/product/Japanese_Invasion_2_America_Sc4/ |
| tokyohot-soapland.html | https://my.tokyo-hot.com/product/Shemalesoapland_Sc4/ |
| ffjav.html | https://ffjav.com/torrent/10musume-052425_01 |
| 141jav.html | https://www.141jav.com/torrent/C2897 |

Each fixture is parsed by the locked CrawlerX dependency, passed through the
javmeta detail job and persisted twice to check that a re-crawl cannot duplicate
its movie. The second response changes the title and uses tracking parameters
containing unrelated DVD codes to verify both field updates and identity stability.
The unit/feature product cases additionally check source scoping and tracking
parameter stability without requiring live HTTP in CI.

`ffjav.html` was captured from
https://ffjav.com/torrent/10musume-052425_01 . The ticket's URL ends in a literal
`...`, which serves a 404 page; the product-code URL serves the actual detail.
