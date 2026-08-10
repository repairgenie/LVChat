#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.."

# Zero-dependency Node suite: build + artifacts, .env parsing/validation, the
# web-bridge, and the api.js login flow against a mock LVChat server + preview.
node tests/build-test.js
