# syntax=docker/dockerfile:1
# Local/CI WordPress image. Production is managed-host-only; this pinned base
# does not define or claim the host's runtime image.
# wordpress:7.0.1-php8.3-apache
# BASELINE NOTE (PR-01): the platform baseline is WordPress >= 7.0.2 / PHP >= 8.3.
# This digest predates 7.0.2; preflight correctly fails on it. The release owner
# must repin to the 7.0.2+ digest from a network-connected environment.
FROM wordpress@sha256:d40b86dbdfcfad808a2029acf6543c670c4a61c29f70b9d24605e7d0b31ab83d
