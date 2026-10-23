# Security

Please do not report a vulnerability in a public issue.

Report it privately through GitHub: **Security → Report a vulnerability** on this repository.
Say what is affected and how to reproduce it.

This repository holds the client library only. It contains no keys, no credentials and no Payshen
infrastructure. Never paste an API key (`ps_live_...`, `ps_test_...`) or a webhook signing secret into an issue,
a pull request or a log you share: if one is exposed, roll it in the Payshen dashboard (Developers → API keys).

Supported: the latest release, on a PHP version that still receives security fixes (8.2 or newer). Keep PHP itself
patched: this library cannot protect a server from flaws in PHP, such as CVE-2026-109618 and CVE-2026-109619
(fixed in PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12).
