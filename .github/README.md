# Versioned releases

This repository publishes xboard from its own source. Release configuration is in
release-config.json; next_version is currently 1.1.0 and must be advanced for
the next development cycle after a formal release.

## Publish

- A dev push publishes the final commit as vNEXT-dev.RUN_ID.RUN_ATTEMPT.
  A push containing multiple commits produces one version from that event's tip,
  not one version per commit. Later branch changes do not change its source.
- Run the existing workflow on master with release_version=v1.1.0 to publish a formal
  version. The input must match next_version. No merge or deployment is performed
  by this formal publishing operation.
- A tag alone does not trigger publishing. The workflow prepares the tag and draft,
  builds from that exact tag, then publishes only after all required artifacts pass.
- A public version cannot be overwritten. Failed-job reruns reuse their prepared
  plan; a new development run attempt creates a new development version.
- Formal panel releases additionally require admin_version and theme_version
  (or RELEASE_ADMIN_VERSION / RELEASE_THEME_VERSION repository variables) containing
  the exact tested frontend versions. Publish both frontends before the first panel
  suite. Development builds can resolve published compatible frontends automatically;
  the selected versions are frozen before building, not resolved during installation.

## Transitional deployment behavior

Remote deployment jobs are paused with an unconditional false guard. Tests,
image builds and versioned publishing remain enabled. Updating a server requires
an explicit updater operation; publishing a release does not install it.
The legacy deployment implementation is retained for recovery reference only.
Non-dev builds keep run-specific build tags and do not become installable Releases.
No floating branch/latest image tags are published.

## Artifact contract (schema version 1)

Every public release contains release-manifest.json:

- component, repository, version, channel (stable/dev), source_commit;
- image: ghcr.io/voidintheshell/xboard:VERSION;
- platforms: linux/amd64 and linux/arm64;
- compatibility.panel_contract=1 and compatibility.update_protocol=1;
- update_capability=external-executor-required: enroll the independent host updater
  described in deploy/updater/README.md before submitting updates.
- components: exact repository, version and image for xboard, xboard-admin and
  dk_theme. This suite is an initial-install reference; compatible frontend
  components can be updated independently without a new Xboard release.

The updater must reject drafts, missing/incompatible manifests, wrong repository
namespaces and incomplete platforms. The Git tag, image tag and runtime version
must agree. Releases are visible only after image publication and runtime checks
on both architectures. No artifact hash comparison is required.

Published image versions are never overwritten on retries. Authentication or
registry failures stop publication instead of assuming a version does not exist.
A failed release stays draft and must not be listed as an available update.

## Discover a new development version

Wait for the workflow to publish a non-draft Pre-release with its complete
release-manifest.json. In Admin, select the component's Dev channel and refresh
the version list. A newly pushed commit or a draft tag is not installable.
The selected full version remains fixed when the update task is created.
