// Vendored tiptap editor for the admin legal pages. Bundled by esbuild into
// public/assets/vendor/tiptap/tiptap-bundle.js (committed like app.js), so the
// server never needs Node or internet. Exposes a single window.TiptapBundle.
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';

window.TiptapBundle = { Editor, StarterKit, Link, Placeholder, Underline };
