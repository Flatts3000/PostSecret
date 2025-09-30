# Security Policy

We take the security of this project seriously. Thank you for helping keep users safe.

## Reporting a Vulnerability

* **Please report vulnerabilities privately via [GitHub Security Advisories](https://docs.github.com/code-security/security-advisories/repository-security-advisories/about-repository-security-advisories).**
* **Do not open public issues for security bugs.** Public issues can expose users before a fix is available.
* When filing an advisory, include:

    * A clear description of the issue and affected components
    * Steps to reproduce or proof-of-concept (if available)
    * Impact assessment (what an attacker can do)
    * Any mitigations or workarounds you’ve identified

If you cannot use Security Advisories for some reason, you may share a minimal reproduction and impact details through a private channel of your choice and reference the repository; we will initiate an advisory thread with you.

## Coordinated Disclosure & Timelines

* We will acknowledge receipt **within 5 business days**.
* We will work with you to understand the report and prioritize a fix.
* Once a fix is ready and released, we’ll publish the advisory with credits (if desired).
* Our goal is to resolve valid issues and publish details **within 90 days** of the initial report, though complex cases may require more time.

## Scope

We welcome reports for vulnerabilities that could affect the **confidentiality, integrity, or availability** of:

* Source code in this repository (theme, plugins, tools)
* Default configurations and install/setup docs in this repo

### Out of Scope

* Vulnerabilities in third-party dependencies **not maintained** in this repository
* Issues that require unrealistic permissions or non-default configurations
* Social engineering, physical attacks, or SPF/DMARC/DMARC alignment on domains we don’t control
* Best-practice suggestions without a concrete security impact (these are welcome as regular issues/PRs)

## Safe Harbor

We support good-faith security research:

* As long as you **avoid privacy violations, service degradation, or data destruction**, and **do not access data you do not own**, we will consider your research authorized under this policy.
* Please **never** attempt to access real user data on production systems. Use test data and local environments.
* Do not perform denial-of-service or stress testing against any live deployments.

## Handling Sensitive Information

If your report contains sensitive details (e.g., credentials accidentally committed), **do not** include them in public artifacts. Share them only inside the private advisory thread.

## Credit & Recognition

With your permission, we will credit you in the published advisory. If you prefer to remain anonymous, please say so in your report.

## Security Hardening Guidance

If you find a security-related misconfiguration in our docs, send a PR or open a **non-security** issue with suggested improvements. Examples include:

* Strong defaults for WordPress, PHP, and MySQL
* Least-privilege permissions
* HTTPS everywhere and secure cookie settings
* Regular updates of dependencies

---

Thank you for helping us protect the community.