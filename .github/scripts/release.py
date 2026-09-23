#!/usr/bin/env python3
"""Versioned release publisher. Standard library only; never deploys a host."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

VERSION = re.compile(r"v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-dev\.[1-9]\d*\.[1-9]\d*)?")
REPOS = {
    "xboard": ("VoidInTheShell/Xboard", "master"),
    "xboard-admin": ("VoidInTheShell/xboard-admin", "main"),
    "dk_theme": ("VoidInTheShell/DK_Theme", "main"),
    "xboard-node": ("VoidInTheShell/Xboard-Node", "dev"),
}
ASSET = "release-manifest.json"


def require(condition, message):
    if not condition:
        raise ValueError(message)


def version(value, stable=False):
    match = VERSION.fullmatch(value)
    require(match is not None and (not stable or not match[4]), "Invalid version: " + value)
    return value


def api(path, data=None, method=None, missing=False, binary=False):
    url = path if path.startswith("https://uploads.github.com/") else "https://api.github.com/" + path
    payload = data if binary else (json.dumps(data).encode() if data is not None else None)
    headers = {"Accept": "application/vnd.github+json", "User-Agent": "xboard-release-v1"}
    if os.environ.get("GH_TOKEN"):
        headers["Authorization"] = "Bearer " + os.environ["GH_TOKEN"]
    if payload is not None:
        headers["Content-Type"] = "application/octet-stream" if binary else "application/json"
    try:
        with urllib.request.urlopen(urllib.request.Request(url, data=payload, headers=headers, method=method), timeout=60) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        if error.code == 404 and missing:
            return None
        raise RuntimeError(f"GitHub API {error.code} for {path.split('?')[0]}") from None


def release_for_tag(repo, tag):
    # The tag endpoint returns published releases only. Drafts require listing.
    release = api(f"repos/{repo}/releases/tags/{tag}", missing=True)
    if release is not None:
        return release
    for page in range(1, 101):
        batch = api(f"repos/{repo}/releases?per_page=100&page={page}")
        require(isinstance(batch, list), "Invalid release listing")
        matches = [item for item in batch if item.get("tag_name") == tag]
        require(len(matches) <= 1, "Ambiguous release tag")
        if matches:
            return matches[0]
        if len(batch) < 100:
            return None
    raise ValueError("Release listing limit reached; refusing to create a duplicate")


def public_json(url, repo, tag):
    prefix = f"https://github.com/{repo}/releases/download/{tag}/"
    require(url == prefix + ASSET, "Unexpected manifest asset URL")
    with urllib.request.urlopen(url, timeout=60) as response:
        return json.load(response)


def image_reference(component, tag):
    return f"ghcr.io/voidintheshell/{component}:{version(tag)}"


def validate_manifest(manifest, component, tag):
    legacy_theme = component == "dk_theme" and manifest.get("schema_version") == 1
    require(manifest.get("schema_version") == 2 or legacy_theme, "Unsupported release manifest schema")
    require(manifest.get("component") == component and manifest.get("version") == tag,
            "Component manifest identity mismatch")
    require(manifest.get("repository") == REPOS[component][0], "Unexpected release repository")
    require(manifest.get("platforms") == ["linux/amd64", "linux/arm64"], "Incomplete image platforms")
    require(manifest.get("channel") == ("dev" if "-dev." in tag else "stable"), "Channel mismatch")
    if legacy_theme:
        require(manifest.get("image") == image_reference(component, tag), "Unexpected image reference")
        require(manifest.get("compatibility", {}).get("panel_contract") == 1
                and manifest.get("compatibility", {}).get("update_protocol") == 1,
                "Incompatible legacy theme contract")
        return manifest
    require(re.fullmatch(r"[0-9a-fA-F]{40}", manifest.get("source_commit", "")) is not None,
            "Invalid source commit")
    compatibility = manifest.get("compatibility", {})
    require(compatibility.get("panel_contract") == 1
            and compatibility.get("update_protocol") == 2
            and compatibility.get("updater_state_schema") == 1,
            "Incompatible updater contract")
    if component == "xboard-admin":
        artifacts = manifest.get("artifacts", {})
        require(artifacts.get("admin_image") == image_reference(component, tag), "Unexpected Admin image reference")
        require(artifacts.get("updater_image") == image_reference("xboard-admin-updater", tag),
                "Unexpected updater image reference")
    else:
        require(manifest.get("image") == image_reference(component, tag), "Unexpected image reference")
    return manifest


def dependency(component, selector, channel):
    repo = REPOS[component][0]
    if selector:
        version(selector, stable=channel == "stable")
        candidates = [api(f"repos/{repo}/releases/tags/{selector}")]
    else:
        candidates = []
        for page in range(1, 11):
            batch = api(f"repos/{repo}/releases?per_page=100&page={page}")
            candidates.extend(batch)
            if len(batch) < 100:
                break
        candidates.sort(key=lambda item: item.get("published_at") or "", reverse=True)
    for release in candidates:
        tag = release.get("tag_name", "")
        if release["draft"] or not VERSION.fullmatch(tag):
            continue
        expected_pre = "-dev." in tag
        if bool(release["prerelease"]) != expected_pre or (channel == "stable" and expected_pre):
            continue
        assets = [a for a in release["assets"] if a["name"] == ASSET and a["size"] > 0]
        if len(assets) != 1:
            continue
        manifest = validate_manifest(public_json(assets[0]["browser_download_url"], repo, tag), component, tag)
        result = {"repository": repo, "version": tag,
                  "image": manifest.get("image") or manifest["artifacts"]["admin_image"]}
        if component == "xboard-admin":
            result["artifacts"] = manifest["artifacts"]
            result["compatibility"] = manifest["compatibility"]
        return result
    raise ValueError(f"No complete compatible release for {repo}; publish the frontend first or select an exact version")


def plan(config, env):
    component = config["component"]
    require(component in REPOS, "Unknown component")
    repo, mainline = REPOS[component]
    require(env["GITHUB_REPOSITORY"].lower() == repo.lower(), "Publishing is restricted to the owned repository")
    sha = env["GITHUB_SHA"]
    require(re.fullmatch(r"[0-9a-f]{40}", sha) is not None, "Invalid source commit")
    requested = env.get("RELEASE_VERSION", "")
    event, ref = env["GITHUB_EVENT_NAME"], env["GITHUB_REF"]
    require(event in ("push", "workflow_dispatch"), "This event cannot publish")
    require(ref.startswith("refs/heads/"), "Publish via a branch dispatch, not a tag push")
    if requested:
        version(requested, stable=True)
        require(event == "workflow_dispatch" and ref == f"refs/heads/{mainline}", "Formal releases must dispatch from the mainline")
        require(requested == "v" + config["next_version"], "Formal version must match release-config.json next_version")
        tag, channel, publish = requested, "stable", True
    elif ref == "refs/heads/dev":
        base = version("v" + config["next_version"], stable=True)
        tag = version(f'{base}-dev.{env["GITHUB_RUN_ID"]}.{env["GITHUB_RUN_ATTEMPT"]}')
        channel, publish = "dev", True
    else:
        # Transitional deployment image; never exposed as an installable release.
        tag = f'build-{env["GITHUB_RUN_ID"]}-{env["GITHUB_RUN_ATTEMPT"]}'
        require(re.fullmatch(r"build-[1-9]\d*-[1-9]\d*", tag) is not None, "Invalid build identity")
        channel, publish = "legacy", False
    return {"schema_version": 2, "component": component, "repository": repo,
            "version": tag, "channel": channel, "source_commit": sha, "publish": publish,
            "image": f"ghcr.io/voidintheshell/{component}:{tag}",
            "platforms": ["linux/amd64", "linux/arm64"],
            "compatibility": {"panel_contract": 1, "update_protocol": 2, "updater_state_schema": 1},
            "update_capability": "external-executor-required"}


def output(values):
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as handle:
        for key, value in values.items():
            require("\n" not in str(value), "Invalid workflow output")
            handle.write(f"{key}={str(value).lower() if isinstance(value, bool) else value}\n")


def prepare(config, path):
    manifest = plan(config, os.environ)
    repo, tag = manifest["repository"], manifest["version"]
    source = manifest["source_commit"]
    if manifest["publish"]:
        release = release_for_tag(repo, tag)
        require(release is None or release["draft"], "Version already published; choose a new version")
        if release:
            require(release.get("target_commitish") == source, "Draft release belongs to a different commit")
            try:
                saved = json.loads(release.get("body", "").split("<!-- release-plan\n", 1)[1].split("\n-->", 1)[0])
            except (ValueError, IndexError):
                raise ValueError("Existing draft has no valid frozen release plan") from None
            require(saved["source_commit"] == source and saved["version"] == tag
                    and saved["repository"] == repo, "Frozen release plan mismatch")
            manifest = saved
        elif manifest["component"] == "xboard":
            if manifest["channel"] == "stable":
                require(os.environ.get("ADMIN_VERSION") and os.environ.get("THEME_VERSION"),
                        "Formal panel releases require exact, tested Admin and Theme versions")
            manifest["components"] = {
                "xboard": {"repository": repo, "version": tag, "image": manifest["image"]},
                "xboard-admin": dependency("xboard-admin", os.environ.get("ADMIN_VERSION", ""), manifest["channel"]),
                "dk_theme": dependency("dk_theme", os.environ.get("THEME_VERSION", ""), manifest["channel"]),
            }
        ref = api(f"repos/{repo}/git/ref/tags/{tag}", missing=True)
        if ref:
            require(ref["object"]["type"] == "commit" and ref["object"]["sha"] == source, "Tag points to another commit")
        else:
            api(f"repos/{repo}/git/refs", {"ref": "refs/tags/" + tag, "sha": source})
        if not release:
            api(f"repos/{repo}/releases", {
                "tag_name": tag, "target_commitish": source, "name": tag, "draft": True,
                "prerelease": manifest["channel"] == "dev",
                "body": "Build in progress.\n\n<!-- release-plan\n" + json.dumps(manifest, sort_keys=True) + "\n-->",
            })
    path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    output({"version": tag, "publish": manifest["publish"],
            "checkout_ref": "refs/tags/" + tag if manifest["publish"] else source})


def image_exists(image):
    result = subprocess.run(["docker", "buildx", "imagetools", "inspect", image, "--raw"],
                            capture_output=True, text=True)
    if result.returncode:
        error = result.stderr.lower()
        require("not found" in error or "manifest unknown" in error, "Image lookup failed; refusing to overwrite an uncertain version")
        return False
    index = json.loads(result.stdout)
    platforms = {f'{m.get("platform", {}).get("os")}/{m.get("platform", {}).get("architecture")}'
                 for m in index.get("manifests", [])}
    require({"linux/amd64", "linux/arm64"} <= platforms, "Existing image has incomplete platforms")
    return True


def complete(path, assets_dir):
    manifest = json.loads(path.read_text(encoding="utf-8"))
    require(manifest["publish"], "Legacy build cannot be published as a release")
    repo, tag = manifest["repository"], manifest["version"]
    require(os.environ["GITHUB_REPOSITORY"].lower() == repo.lower()
            and os.environ["GITHUB_SHA"] == manifest["source_commit"], "Release execution identity changed")
    validate_manifest(manifest, manifest["component"], tag)
    require(image_exists(manifest["image"]), "Image missing")
    release = release_for_tag(repo, tag)
    require(release is not None, "Prepared draft release is missing")
    require(release["draft"], "Release is already public")
    required = [path]
    if manifest["component"] in ("xboard-node", "xboard"):
        if manifest["component"] == "xboard-node":
            required += [assets_dir / f"{name}-linux-{arch}" for name in ("xboard-node", "xbctl") for arch in ("amd64", "arm64")]
        assets_dir.mkdir(parents=True, exist_ok=True)
        installer = Path("install.sh").read_text(encoding="utf-8")
        require(installer.count('DEFAULT_RELEASE_VERSION="latest"') == 1, "Installer version marker missing")
        pinned_installer = assets_dir / "install.sh"
        pinned_installer.write_text(installer.replace('DEFAULT_RELEASE_VERSION="latest"',
                                                    f'DEFAULT_RELEASE_VERSION="{tag}"'), encoding="utf-8", newline="\n")
        required += [pinned_installer, Path("compose.sample.yaml")]
        if manifest["component"] == "xboard-node":
            required += [Path("config.yml.example"), Path("updater.sample.json")]
        else:
            required += [Path("compose.entry.sample.yaml"), Path(".env.example")]
    for asset in required:
        require(asset.is_file() and asset.stat().st_size > 0, "Missing release asset: " + str(asset))
    if manifest["component"] in ("xboard-node", "xboard"):
        if manifest["component"] == "xboard-node":
            manifest["binaries"] = {
                arch: {name: f"https://github.com/{repo}/releases/download/{tag}/{name}-linux-{arch}"
                       for name in ("xboard-node", "xbctl")} for arch in ("amd64", "arm64")
            }
        manifest["installer"] = f"https://github.com/{repo}/releases/download/{tag}/install.sh"
    path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    existing = {a["name"]: a for a in release["assets"]}
    upload_url = release["upload_url"].split("{", 1)[0]
    for asset in required:
        if asset.name in existing:
            require(existing[asset.name]["size"] == asset.stat().st_size, "Existing draft asset differs; publish a new version")
            continue
        api(upload_url + "?name=" + urllib.parse.quote(asset.name), asset.read_bytes(), binary=True)
    updated = api(f"repos/{repo}/releases/{release['id']}")
    require({a.name for a in required} <= {a["name"] for a in updated["assets"] if a["size"] > 0}, "Incomplete uploaded assets")
    api(f"repos/{repo}/releases/{release['id']}", {
        "draft": False, "prerelease": manifest["channel"] == "dev",
        "make_latest": "false" if manifest["channel"] == "dev" else "true",
        "body": f"{tag}\n\nImage: {manifest['image']}\n\nSee {ASSET} for exact versions and compatibility.\n"
                "Publishing does not request an upgrade. An external update executor is required.",
    }, method="PATCH")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["prepare", "image-check", "complete"])
    parser.add_argument("--plan", type=Path, default=Path(os.environ.get("RUNNER_TEMP", ".")) / ASSET)
    parser.add_argument("--assets", type=Path, default=Path("release-binaries"))
    args = parser.parse_args()
    if args.command == "prepare":
        prepare(json.loads(Path(".github/release-config.json").read_text(encoding="utf-8")), args.plan)
    elif args.command == "image-check":
        data = json.loads(args.plan.read_text(encoding="utf-8"))
        output({"exists": image_exists(data["image"])})
    else:
        complete(args.plan, args.assets)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError, KeyError) as error:
        print(f"Release refused: {error}", file=sys.stderr)
        sys.exit(1)
