#!/usr/bin/env bash

#
# LVChat — Discord-style web chat (PHP + SQLite)
# Copyright (C) LVChat contributors
# SPDX-License-Identifier: AGPL-3.0-only
# License: GNU Affero General Public License v3 only — see the LICENSE file.
#

set -u
cd "$(dirname "$0")/.."

# Zero-dependency Node suite: build + artifacts, .env parsing/validation, the
# web-bridge, and the api.js login flow against a mock LVChat server + preview.
node tests/build-test.js
