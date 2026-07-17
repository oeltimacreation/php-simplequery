# Security Policy

## Supported versions

There is no released version yet. Security support begins with the first
published release and will be listed here by release line.

| Version | Supported |
| --- | --- |
| Unreleased development branch | Best effort; not for production |

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting feature for this
repository. If it is unavailable, contact the maintainers through a private
channel listed on the owning GitHub organization.

Do not include secrets, customer data, production DSNs, or exploit details in a
public issue.

Reports should include:

- affected version or commit;
- affected driver and database version, when relevant;
- a minimal reproduction using synthetic data;
- expected and observed behavior;
- potential confidentiality, integrity, or availability impact.

The maintainers will acknowledge a report, investigate it, coordinate a fix,
and publish an advisory when appropriate. Release timing depends on severity,
reproducibility, and the safety of the proposed correction.

## Security boundaries

Parameterized values do not make caller-provided identifiers or raw SQL safe.
Applications remain responsible for authorization, identifier allowlists,
credential management, transport security, database permissions, proxy
configuration, and safe operational logging.
