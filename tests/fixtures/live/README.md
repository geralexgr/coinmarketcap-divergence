# tests/fixtures/live

Empty until a key exists.

`bin/verify-endpoints.php --save-fixtures`, run on the host, writes the real CoinMarketCap payloads
here. They are free at that moment — the verification calls are being made anyway — and cost
credits to obtain later.

The extractor was built against `../synthetic/`, which is the documented response shape rather than
the real one. So the first thing to do once these files exist is re-point the extraction tests at
this directory and run them. Every failure is a place where the documentation and the API disagree.

Payloads captured here are trimmed, not edited: a shortened list is still a real shape, a corrected
field is not.
