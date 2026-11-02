# VISION.md

## Purpose

### Problem Statement

Many residential and small-business internet providers delegate an IPv6 prefix that can
change without notice. pfSense configurations may contain global addresses derived from
that prefix in aliases, static assignments, virtual addresses, and VPN settings. When the
delegated prefix changes, those embedded addresses become stale together and services
can fail in ways that are difficult to diagnose.

The current updater proves that pfSense can detect the new prefix, find affected
configuration values, back up the configuration, replace the prefix, write the result,
and notify the administrator. Its manual root installation and external Cron setup make
deployment and upgrades fragile. The next version should make that behavior a native,
safe, observable pfSense package.

### Target Audience

- **pfSense operators with dynamic IPv6 delegation** - Administrators whose ISP changes
  the routed prefix while host portions and firewall intent should remain stable.
- **Home-lab and small-network administrators** - Users maintaining aliases, services,
  and VPNs that reference globally routed IPv6 addresses.
- **Package maintainers** - Contributors who need an installable, testable structure
  instead of files copied into `/root`.

### Value Proposition

pfSense IPv6 Prefix Updater will keep approved configuration references aligned with the
current delegated prefix while preserving host bits and firewall intent. A package-native
configuration and scheduling experience will make installation, upgrades, backups,
locking, notifications, and removal predictable from the pfSense interface.

## Product Direction

### Principles

- **Protect configuration first** - Validate, preview, back up, and verify before a
  change is committed.
- **Idempotent operation** - An unchanged prefix produces no configuration write,
  service churn, or duplicate notification.
- **pfSense-native lifecycle** - Installation, settings, scheduling, logs, upgrades, and
  removal should follow package conventions rather than modify a root home directory.
- **Targeted mutation** - Update understood IPv6-bearing fields, not every matching text
  value in the configuration.
- **Explain every change** - Logs and notifications should identify the interface,
  prefix transition, affected areas, and result without dumping the full configuration.
- **Compatibility is explicit** - Supported pfSense releases and configuration shapes
  must be tested and documented rather than assumed.

### Scope

#### Initial Package Scope

- Install and remove through pfSense Package Manager conventions.
- Configure one or more monitored interfaces and delegated prefix lengths through a
  package page.
- Schedule checks without requiring the separate Cron package.
- Prevent concurrent runs for the same configured target.
- Detect the current global IPv6 prefix and compare it with the last successful state.
- Back up configuration before mutation and retain a clear recovery path.
- Update approved references in aliases, static or virtual addresses, IPsec, OpenVPN,
  and WireGuard configuration where pfSense owns the data.
- Write through pfSense configuration APIs and send a native notification after a
  successful change.
- Expose useful status and logs from the package interface.

### Explicit Non-Goals

- Obtaining or negotiating an IPv6 prefix from the ISP.
- Replacing DHCPv6 Prefix Delegation or router advertisements.
- Performing unrestricted search-and-replace across `config.xml`.
- Editing remote WireGuard peer configuration files that pfSense does not own.
- Requiring an external controller or cloud service.
- Claiming compatibility with untested pfSense forks or releases.
- Changing host portions, firewall intent, or unrelated configuration.

## Product Design

### Core Capabilities

The package detects prefix transitions, plans approved configuration changes, protects
the write with locking and backup, verifies the result, and reports its status through
pfSense-native scheduling, logs, notifications, and WebGUI surfaces.

### User Experience

Operators configure monitored targets and schedules in pfSense, see no-op and change
outcomes without reading raw configuration, and retain a clear recovery path for every
write.

## Positioning

### Differentiators

| pfSense IPv6 Prefix Updater                        | Manual script workflows                        |
|----------------------------------------------------|------------------------------------------------|
| Package Manager lifecycle and WebGUI configuration | Files copied into a root home directory        |
| Built-in scheduling and single-run coordination    | Separate Cron package and hand-written entries |
| Targeted, reviewed configuration mutations         | Broad text replacement or manual edits         |
| Visible backup and change status                   | Recovery knowledge held outside the tool       |

## Risks and Future

### Open Questions

The package design workshop should decide:

- The exact pfSense releases and PHP compatibility range supported initially.
- Whether the first package supports multiple interfaces in one configuration.
- Which configuration paths are approved for automatic mutation in each release.
- Backup retention, restore UX, and integration with pfSense configuration history.
- The scheduling controls and a safe default interval.
- Whether a dry-run preview is exposed in the WebGUI.
- Which services need reloads after a successful configuration write.
- How legacy cache and backup files are discovered or migrated.

## Marketing Copy

### Tagline

Keep pfSense IPv6 references current when your ISP changes the prefix.

### Elevator Pitch

pfSense IPv6 Prefix Updater is a native package for networks with dynamic IPv6 Prefix
Delegation. It detects a changed prefix, safely updates approved pfSense configuration
references, preserves the host portion of each address, and reports what changed. Package
Manager installation, built-in scheduling, backups, and visible status replace the
current root script and manual Cron setup.

### Key Features

- **Automatic prefix tracking** - Compare the active interface prefix with the last
  successfully applied state.
- **Safe targeted updates** - Preserve host portions while changing approved pfSense
  IPv6 references.
- **Configuration protection** - Back up before writing and keep a clear recovery path.
- **Package-native scheduling** - Configure recurring checks without manually installing
  a script or creating a separate Cron entry.
- **Operational visibility** - Report no-op, success, warning, and failure outcomes
  through package status, logs, and pfSense notifications.

## Glossary

| Term               | Definition                                                                                 |
|--------------------|--------------------------------------------------------------------------------------------|
| Delegated prefix   | The IPv6 network prefix assigned by an ISP, commonly through DHCPv6 Prefix Delegation.     |
| Host portion       | The address bits after the delegated prefix that identify a specific interface or service. |
| Prefix transition  | A detected change from the last successfully applied prefix to the active prefix.          |
| Approved reference | A known pfSense configuration field the package is allowed to update.                      |
| No-op run          | A successful check where the active prefix is already represented and no write is needed.  |
| Legacy updater     | The current manually installed PHP script and its external Cron workflow.                  |

## Additional Vision Notes

### Current Context

> This document captures the direction for converting the existing root-run script into
> a first-class pfSense package through Nova.
