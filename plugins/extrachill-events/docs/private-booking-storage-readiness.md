# Private Booking Storage Readiness

Private booking storage remains disabled until repository checks and operator evidence both pass. A safe filesystem path alone is not approval.

## Architecture Boundary

- `LocalBookingPrivateFileProvider` owns path containment, ownership, mode, symlink, writability, atomic storage, and byte integrity.
- `BookingAttachmentPolicy` owns purposes, MIME and size limits, prohibited document names, malware requirements, and audit-sensitive purposes.
- `BookingPrivacyService` owns the versioned attachment retention defaults and exposes the redacted readiness result through the existing venue-authorized diagnostics ability.
- `BookingPrivateStorageReadiness` validates non-secret operator evidence. It does not provision storage, run backups, configure a scanner, change limits, or perform network probes.
- `BookingPrivateFileProviders::resolve()` is the activation boundary. It returns a provider only when every readiness check passes.
- Deployment configuration supplies evidence through `extrachill_events_booking_private_storage_evidence`. No option, table, REST route, CLI namespace, or parallel diagnostics system is introduced.

The readiness projection contains only `ready`, `blocked`, and stable reason codes. It never returns configured paths, provider classes, credentials, object references, storage metadata, scanner details, or probe targets.

## State Machine

1. `blocked/provider`: provider configuration is absent, public, unsafe, incorrectly owned, incorrectly permissioned, or unwritable.
2. `blocked/governance`: one or more attestations, policies, limits, or probes are missing, stale, or invalid.
3. `ready`: the provider is safe and every evidence section passes.
4. Any provider drift, expired attestation, reduced effective WordPress limit, failed probe, or removed approval returns the runtime to `blocked` immediately.

There is no degraded mode and no fallback to WordPress uploads or Data Machine files.

## Approved Policy Matrix

| Area | Repository contract | Operator evidence required |
| --- | --- | --- |
| Allowed documents | Promo images, EPK/press material, stage plots, technical/hospitality riders, insurance, contracts, and other approved private booking evidence | Explicit approval of every canonical purpose |
| Prohibited documents | W-9, SSN/TIN/EIN, tax identity, banking, routing, and direct-deposit documents | Explicit prohibited-class approval; use a separately approved vault if these are ever needed |
| Malware | PDF, DOCX, and XLSX fail closed without an explicit scanner verdict | Approved policy plus clean-file and rejection probes |
| Retention | Rejected 365 days, active 730 days, confirmed 2555 days | Approval of version 1, deletion authority, orphan handling, privacy behavior, and legal-hold probe |
| Legal holds | Confirmed/completed contracts, insurance, riders, stage plots, and other private evidence are hold-sensitive | Confirmed/completed hold behavior proven before cleanup |
| Limits | Per-file maximum is 20 MiB; aggregate request maximum is 50 MiB | Ingress and PHP post layers at least 50 MiB; PHP upload, WordPress, and application per-file layers at least 20 MiB |
| Capacity | No automatic deletion or public fallback under pressure | Monitoring enabled and a fail-closed pressure rehearsal |

## Evidence Configuration

The operator owns the deployment-specific configuration that returns this versioned array. Store evidence references in the operations system; do not put private paths, credentials, scanner output, backup identifiers, or probe URLs in this array.

```php
add_filter(
	'extrachill_events_booking_private_storage_evidence',
	static function (): array {
		return array(
			'version' => 1,
			'attested_at' => 'YYYY-MM-DDTHH:MM:SSZ',
			'expires_at' => 'YYYY-MM-DDTHH:MM:SSZ',
			'document_policy' => array(
				'approved' => true,
				'allowed_purposes' => \ExtraChillEvents\Core\BookingAttachmentPolicy::PURPOSES,
				'prohibited_classes' => array( 'w9', 'ssn_tin', 'tax_identity', 'banking' ),
			),
			'malware_policy' => array(
				'approved' => true,
				'required_mime_types' => array( 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
				'clean_probe_passed' => true,
				'rejection_probe_passed' => true,
			),
			'retention_policy' => array(
				'version' => 1,
				'approved' => true,
				'legal_hold_statuses' => array( 'confirmed', 'completed' ),
				'deletion_authority_approved' => true,
				'orphan_handling_approved' => true,
				'privacy_request_behavior_approved' => true,
				'legal_hold_probe_passed' => true,
			),
			'backup_restore' => array(
				'encrypted' => true,
				'path_covered' => true,
				'retention_documented' => true,
				'deletion_propagation_documented' => true,
				'restore_test_passed' => true,
				'restore_tested_at' => 'YYYY-MM-DDTHH:MM:SSZ',
			),
			'access_denial' => array(
				'environment' => 'staging',
				'static_probe_passed' => true,
				'application_probe_passed' => true,
				'tested_at' => 'YYYY-MM-DDTHH:MM:SSZ',
			),
			'upload_limits' => array(
				'ingress_bytes' => 52428800,
				'php_post_bytes' => 52428800,
				'php_upload_bytes' => 20971520,
				'wordpress_bytes' => 20971520,
				'application_bytes' => 20971520,
			),
			'capacity' => array(
				'monitoring_enabled' => true,
				'pressure_behavior' => 'fail_closed',
				'pressure_probe_passed' => true,
			),
		);
	}
);
```

## Live Operator Checklist

These steps are intentionally not claimed by this repository change. Keep #336 open until evidence is attached to the issue.

- Provision one durable directory outside the web root, uploads, caches, and coding workspaces. Record owner, group, mode, ACL, mount, and filesystem evidence without posting the path publicly.
- Prove the PHP-FPM identity can create, atomically rename, read, and delete owner-only fixtures. Prove the web-server identity cannot traverse or read them.
- Configure `EXTRACHILL_EVENTS_PRIVATE_STORAGE_ROOT` only after the directory is ready.
- Confirm encrypted backup inclusion, retention, deletion propagation, and unavailable-backup behavior. Restore a synthetic fixture into an isolated target and record integrity evidence.
- Approve the allowed/prohibited document matrix and incident/access-log owner.
- Approve a malware scanner for PDF, DOCX, and XLSX. Prove clean acceptance, malicious/test-signature rejection, timeout, and unavailable-scanner fail-closed behavior.
- Approve retention, grace periods, deletion authority, orphan handling, privacy requests, and legal holds. Rehearse a hold appearing immediately before cleanup and prove bytes remain.
- Align ingress and PHP `post_max_size` to the 50 MiB aggregate limit; align PHP `upload_max_filesize`, WordPress `wp_max_upload_size()`, and the application per-file limit to 20 MiB. Prove allowed per-file and aggregate maxima succeed and oversized requests fail without orphan bytes.
- Enable capacity monitoring. Rehearse threshold pressure and prove new admission fails closed without deleting retained bytes or using public storage.
- On staging, request an unguessable synthetic object through a direct static URL and through unauthorized application access. Record denial status, absence of bytes, and access-log evidence.
- Set attestation and expiration timestamps, add the non-secret evidence filter, and run the existing venue-authorized `diagnose` operation. Require every check to report `pass`.
- Re-run unauthorized static/application smoke after activation. Do not enable venue attachment invitation policy from #720 until this remains ready.

## Repository Evidence

`tests/BookingPrivateStorageReadinessTest.php` covers healthy synthetic evidence, absent/unsafe/public paths, ownership and permission failures, missing/stale attestations, prohibited-document policy, scanner policy, retention/legal holds, backup/restore, static/application denial, layer limit mismatch, capacity pressure, resolver gating, and redaction. Provider tests separately exercise real temporary-directory containment and permissions.

This work supports #336. It does not complete live provisioning, backup restore, scanner approval, limit changes, capacity rehearsal, or staging access proof.
